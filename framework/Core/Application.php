<?php

namespace Px\Core;

use Px\Platform\Platform;
use Px\Platform\PlatformFactory;
use Px\Rendering\VNode;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\LayoutResolver;
use Px\ReactiveComponent;

/**
 * Application — AOT 框架入口
 *
 * 持有：Platform、ReactionBus、Scheduler
 * 事件循环：
 *   平台事件 → 命中测试 → 组件方法 → 微任务 → 渲染 → 宏任务
 *
 * 编译时常量（main.php 定义）：
 *   APP_PLATFORM  WINDOW_WIDTH  WINDOW_HEIGHT  WINDOW_TITLE
 */
class Application
{
    private Platform $platform;
    private ReactionBus $bus;
    private Scheduler $scheduler;
    private VNodeRenderer $renderer;
    private LayoutResolver $layoutResolver;

    private ?ReactiveComponent $rootComponent = null;
    private ?VNode $activeVNodeTree = null;
    private bool $renderRequested = false;
    private bool $running = true;

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance;
    }

    public static function create(): self
    {
        $platform = PlatformFactory::create(APP_PLATFORM);
        $bus = new ReactionBus();
        $scheduler = new Scheduler();
        $app = new self($platform, $bus, $scheduler);
        self::$instance = $app;
        return $app;
    }

    public function __construct(Platform $platform, ReactionBus $bus, Scheduler $scheduler)
    {
        $this->platform  = $platform;
        $this->bus       = $bus;
        $this->scheduler = $scheduler;
        $this->layoutResolver = new LayoutResolver();

        // 内部监听器：渲染请求
        $this->bus->on('render:request', function () {
            $this->renderRequested = true;
        });

        // 内部监听器：平台鼠标事件 → 命中测试
        $this->bus->on('platform:mouse', function ($event) {
            if ($event === null || $event->action !== 'down' || $this->activeVNodeTree === null) {
                return;
            }
            $btn = $this->hitTest($event->x, $event->y, $this->activeVNodeTree);
            if ($btn !== null && isset($btn->props['@click'])) {
                $handler = $btn->props['@click'];
                $arg = $btn->props['click-arg'] ?? null;
                $this->rootComponent->dispatchClick($handler, $arg);
            }
        });

        // 内部监听器：平台键盘事件 → 焦点 input 元素
        $this->bus->on('platform:keyboard', function ($event) {
            if ($event === null || $this->activeVNodeTree === null) {
                return;
            }
            $input = $this->findFocusedInput($this->activeVNodeTree);
            if ($input === null) {
                return;
            }
            $action = $event->action;
            if ($action === 'down') {
                $handler = $input->props['@keydown'] ?? null;
                if ($handler !== null) {
                    $this->rootComponent->dispatchKey($handler, $action, $event->keyCode, $event->char);
                }
            } elseif ($action === 'up') {
                $handler = $input->props['@keyup'] ?? null;
                if ($handler !== null) {
                    $this->rootComponent->dispatchKey($handler, $action, $event->keyCode, $event->char);
                }
            } elseif ($action === 'char') {
                $handler = $input->props['@enter'] ?? null;
                if ($handler !== null && $event->keyCode === 13) {
                    $this->rootComponent->dispatchKey($handler, $action, $event->keyCode, $event->char);
                }
            }
        });
    }

    public function getPlatform(): Platform   { return $this->platform; }
    public function getBus(): ReactionBus     { return $this->bus; }
    public function getScheduler(): Scheduler { return $this->scheduler; }

    private function initRenderer(): void
    {
        $render_ctx = $this->platform->init(WINDOW_TITLE, WINDOW_WIDTH, WINDOW_HEIGHT);
        $this->renderer = new VNodeRenderer($this->rootComponent, $render_ctx);

        // 加载编译期提取的 CSS class styles 到 LayoutResolver
        if (method_exists($this->rootComponent, 'getClassStyles')) {
            $classStyles = $this->rootComponent->getClassStyles();
            $this->layoutResolver->setClassStyles($classStyles);
        }
    }

    public function mount(ReactiveComponent $root): self
    {
        $this->rootComponent = $root;
        $this->rootComponent->setScheduler($this->scheduler);
        $this->rootComponent->setBus($this->bus);
        $this->initRenderer();
        $this->rootComponent->mount();
        return $this;
    }

    public function requestRender(): void
    {
        $this->bus->emitAsync('render:request', null);
    }

    private function rebuildVNodeTree(): void
    {
        // 惰性重建: dirty 时才调 render(), 否则返回缓存 (Vue 3 风格)
        $this->activeVNodeTree = $this->rootComponent->getVNodeTree();
    }

    private function render(): void
    {
        $this->rebuildVNodeTree();
        $this->bus->emit('render:before', null);
        $this->layoutResolver->resolve($this->activeVNodeTree);
        $this->renderer->render($this->activeVNodeTree);
        $this->bus->emit('render:after', null);
    }

    private function doFirstRender(): void
    {
        $this->render();
        $this->scheduler->flushMicrotasks();
        if ($this->renderRequested) {
            $this->renderRequested = false;
            $this->render();
        }
        $this->bus->emit('app:first_render_complete', null);
    }

    /**
     * 事件循环
     */
    public function run(): void
    {
        $this->doFirstRender();
        $this->bus->emit('app:before_run', null);

        while ($this->running) {
            $rawEvents = $this->platform->pollEvents();
            foreach ($rawEvents as $ev) {
                $this->bus->emit('platform:' . $ev->type, $ev);
            }

            $this->scheduler->flushMicrotasks();

            if ($this->renderRequested) {
                $this->renderRequested = false;
                $this->render();
            }

            $hasMacro = $this->scheduler->runOneMacrotask();

            if (!$hasMacro && !$this->renderRequested && count($rawEvents) === 0) {
                usleep(1000);
            }

            if ($this->platform->shouldClose()) {
                $this->running = false;
            }
        }
        $this->bus->emit('app:will_quit', null);
        $this->platform->shutdown();
    }

    /**
     * 命中测试：找到鼠标坐标命中的最顶层可交互元素。
     *
     * 设计原则（对标 CSS）：
     *   1. 反向遍历子节点 —— 后渲染的 DOM 在视觉上层，应先命中
     *   2. Layer 感知 —— z-index 创建的层叠上下文控制命中优先级
     *
     * 注意：v-if 已在编译期处理，false 分支不会出现在 VNode 树中。
     *
     * @param int  $x    鼠标 X 坐标
     * @param int  $y    鼠标 Y 坐标
     * @param VNode $node 当前 VNode
     * @return VNode|null 命中的最顶层交互元素，无则 null
     */
    private function hitTest(int $x, int $y, VNode $node): ?VNode
    {
        // 先检查子节点 —— 反向遍历（后渲染 = 视觉上层 = 优先命中）
        $children = $node->children;
        if ($children instanceof VNode) {
            $found = $this->hitTest($x, $y, $children);
            if ($found !== null) return $found;
        } elseif (is_array($children)) {
            // 反向: 数组末尾元素渲染在最上层
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $child = $children[$i];
                if ($child instanceof VNode) {
                    $child = objval($child, VNode::class);
                    $found = $this->hitTest($x, $y, $child);
                    if ($found !== null) return $found;
                }
            }
        }

        // 子节点均未命中 → 检查自身
        if ($node->props !== null
            && isset($node->props['@click'])
            && $x >= $node->x && $x <= $node->x + $node->w
            && $y >= $node->y && $y <= $node->y + $node->h) {
            return $node;
        }

        return null;
    }

    /**
     * Find the first input element in the VNode tree that has keyboard handlers.
     * Used to route keyboard events to the focused input.
     */
    private function findFocusedInput(VNode $node): ?VNode
    {
        if ($node->type === 'input'
            && (isset($node->props['@keydown']) || isset($node->props['@keyup']) || isset($node->props['@enter']))) {
            return $node;
        }
        $children = $node->children;
        if ($children instanceof VNode) {
            return $this->findFocusedInput($children);
        }
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof VNode) {
                    $child = objval($child, VNode::class);
                    $found = $this->findFocusedInput($child);
                    if ($found !== null) return $found;
                }
            }
        }
        return null;
    }
}