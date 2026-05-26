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
 * 持有：Platform、Scheduler
 * 事件循环：
 *   平台事件 → 命中测试 → 组件方法 → 微任务 → 渲染 → 宏任务
 *
 * 组件事件路由（对标 Vue 3 / Flutter hitTest 链）：
 *   1. VNode.groupId 标识所属组件
 *   2. Application 维护 componentByGroupId 注册表
 *   3. hitTest 找到 VNode → 查表找到组件 → 调用 dispatchClick/dispatchKey
 *
 * 编译时常量（main.php 定义）：
 *   APP_PLATFORM  WINDOW_WIDTH  WINDOW_HEIGHT  WINDOW_TITLE
 */
class Application
{
    private Platform $platform;
    private Scheduler $scheduler;
    private VNodeRenderer $renderer;
    private LayoutResolver $layoutResolver;

    private ?ReactiveComponent $rootComponent = null;
    private ?VNode $activeVNodeTree = null;
    private bool $renderRequested = false;
    private bool $running = true;

    /** @var array<string, ReactiveComponent> VNode.groupId → Component instance */
    private array $componentByGroupId = [];

    /** @var array<string, ReactiveComponent> componentClass → instance (singleton per class) */
    private array $componentInstances = [];

    // ── Scroll interaction state ──────────────
    private ?VNode $scrollDragTarget = null;
    private int $scrollDragStartY = 0;
    private int $scrollDragStartScrollTop = 0;

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance;
    }

    public static function create(): self
    {
        $platform = PlatformFactory::create(APP_PLATFORM);
        $scheduler = new Scheduler();
        $app = new self($platform, $scheduler);
        self::$instance = $app;
        return $app;
    }

    public function __construct(Platform $platform, Scheduler $scheduler)
    {
        $this->platform  = $platform;
        $this->scheduler = $scheduler;
        $this->layoutResolver = new LayoutResolver();
    }

    // ── 平台事件处理器 ─────────────────────────

    private function handleRenderRequest(): void
    {
        $this->renderRequested = true;
    }

    private function handleMouseEvent($event): void
    {
        if ($event === null || $this->activeVNodeTree === null) {
            return;
        }

        // ── 鼠标滚轮：驱动滚动容器 ────────────
        if ($event->action === 'wheel') {
            $this->handleScrollWheel($event);
            return;
        }

        // ── 鼠标拖动：滚动条拖拽 ──────────────
        if ($event->action === 'move') {
            if ($this->scrollDragTarget !== null) {
                $this->handleScrollbarDrag($event->y);
            }
            return;
        }

        // ── 鼠标释放：结束拖拽，持久化滚动位置 ──
        if ($event->action === 'up') {
            if ($this->scrollDragTarget !== null) {
                $this->applyScrollTop($this->scrollDragTarget, $this->scrollDragTarget->scrollTop, true);
                $this->scrollDragTarget = null;
            }
            return;
        }

        // ── 鼠标按下：优先检测滚动条，其次 @click ──
        if ($event->action === 'down') {
            $sbResult = $this->hitTestScrollbar($event->x, $event->y, $this->activeVNodeTree);
            if ($sbResult !== null) {
                $this->handleScrollbarDown($sbResult['scrollNode'], $sbResult['type'], $event->y);
                return;
            }

            $btn = $this->hitTest($event->x, $event->y, $this->activeVNodeTree);
            if ($btn !== null && isset($btn->props['@click'])) {
                $handler = $btn->props['@click'];
                $arg = $btn->props['click-arg'] ?? null;
                $target = $this->resolveComponent($btn);
                $target->dispatchClick($handler, $arg);
            }
        }
    }

    private function handleKeyboardEvent($event): void
    {
        if ($event === null || $this->activeVNodeTree === null) {
            return;
        }
        $input = $this->findFocusedInput($this->activeVNodeTree);
        if ($input === null) {
            return;
        }
        $target = $this->resolveComponent($input);
        $action = $event->action;
        if ($action === 'down') {
            $handler = $input->props['@keydown'] ?? null;
            if ($handler !== null) {
                $target->dispatchKey($handler, $action, $event->keyCode, $event->char);
            }
        } elseif ($action === 'up') {
            $handler = $input->props['@keyup'] ?? null;
            if ($handler !== null) {
                $target->dispatchKey($handler, $action, $event->keyCode, $event->char);
            }
        } elseif ($action === 'char') {
            $handler = $input->props['@enter'] ?? null;
            if ($handler !== null && $event->keyCode === 13) {
                $target->dispatchKey($handler, $action, $event->keyCode, $event->char);
            }
        }
    }

    // ── 组件注册表 ─────────────────────────────

    /**
     * 注册组件实例，按 groupId 索引。
     * 根组件自动注册为 'app'；子组件用其 tagName 注册。
     */
    public function registerComponent(string $groupId, ReactiveComponent $component): void
    {
        $this->componentByGroupId[$groupId] = $component;
    }

    /**
     * 注销组件实例。
     */
    public function unregisterComponent(string $groupId): void
    {
        unset($this->componentByGroupId[$groupId]);
    }

    /**
     * 根据 VNode 的 groupId 查找目标组件。
     * 找不到时回退到根组件（兼容全量内联架构）。
     */
    private function resolveComponent(VNode $node): ReactiveComponent
    {
        return $this->componentByGroupId[$node->groupId] ?? $this->rootComponent;
    }

    public function getPlatform(): Platform   { return $this->platform; }
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
        // 注入渲染请求回调，替代原来的 render:request 事件
        $this->rootComponent->setRenderCallback($this->handleRenderRequest(...));
        // 注册根组件
        $this->registerComponent('app', $root);
        $this->initRenderer();
        $this->rootComponent->mount();
        return $this;
    }

    /**
     * 异步请求渲染（通过微任务延迟）。
     */
    public function requestRender(): void
    {
        $this->scheduler->addMicrotask($this->handleRenderRequest(...));
    }

    private function rebuildVNodeTree(): void
    {
        // 惰性重建: dirty 时才调 render(), 否则返回缓存 (Vue 3 风格)
        $this->activeVNodeTree = $this->rootComponent->getVNodeTree();
        $this->expandComponentTree($this->activeVNodeTree);
        // 将组件的 bind 值写入 VNode (如 :scroll-top → scrollTop)
        $this->resolveVNodeBindings($this->activeVNodeTree);
    }

    /**
     * 递归展开组件占位节点（#component），替换为子组件的 VNode 树。
     */
    private function expandComponentTree(VNode $node): void
    {
        $children = $node->children;

        if ($children instanceof VNode) {
            $children = objval($children, VNode::class);
            if ($children->isComponent()) {
                $this->expandComponentNode($children);
            }
            // After expansion (or not), recurse into child's subtree
            if ($children->children instanceof VNode || is_array($children->children)) {
                $this->expandComponentTree($children);
            }
        } elseif (is_array($children)) {
            foreach ($children as $child) {
                if (!$child instanceof VNode) continue;
                $child = objval($child, VNode::class);

                if ($child->isComponent()) {
                    $this->expandComponentNode($child);
                }

                // Recurse into children (component nodes need this after children replaced)
                if ($child->children instanceof VNode || is_array($child->children)) {
                    $this->expandComponentTree($child);
                }
            }
        }
    }

    /**
     * 展开单个组件占位节点。
     */
    private function expandComponentNode(VNode $node): void
    {
        $className = $node->componentClass;
        if ($className === null) return;

        // 获取或创建组件实例
        if (!isset($this->componentInstances[$className])) {
            $instance = \ComponentFactory::create($className);
            $instance->setScheduler($this->scheduler);
            $instance->setRenderCallback($this->handleRenderRequest(...));
            $instance->setParent($this->rootComponent);
            $instance->mount();
            $this->registerComponent($className, $instance);
            $this->componentInstances[$className] = $instance;
        }
        $instance = $this->componentInstances[$className];

        // 传递 props：父组件的 bind key → 子组件的属性
        if ($node->componentProps !== null && $this->rootComponent !== null) {
            foreach ($node->componentProps as $childKey => $parentExpr) {
                $parentValue = $this->rootComponent->getBindValue($parentExpr);
                $instance->setBindValue($childKey, $parentValue);
            }
        }

        // 展开子树（利用子组件 vnodeCache）
        $childRoot = $instance->getVNodeTree();
        $node->w = $childRoot->w;
        $node->h = $childRoot->h;
        $node->componentInstance = $instance;
        $node->children = $childRoot;

        // 设置 groupId 用于事件路由
        $this->setGroupIdRecursive($childRoot, $className);

        // 递归：子组件树可能也包含组件占位节点
        $this->expandComponentTree($childRoot);
    }

    /**
     * 递归设置 VNode 树中所有节点的 groupId。
     */
    private function setGroupIdRecursive(VNode $node, string $groupId): void
    {
        $node->groupId = $groupId;
        if ($node->children instanceof VNode) {
            $this->setGroupIdRecursive($node->children, $groupId);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->setGroupIdRecursive($child, $groupId);
                }
            }
        }
    }

    // ── 滚动系统 ──────────────────────────────

    /**
     * 将组件 bind 值同步到 VNode 属性（如 :scroll-top → scrollTop）。
     * 在每次重建 VNode 树后、layout 之前调用。
     */
    private function resolveVNodeBindings(VNode $node): void
    {
        // :scroll-top bind → VNode::scrollTop
        $scrollBindKey = $node->props[':scroll-top'] ?? '';
        if ($scrollBindKey !== '') {
            $component = $this->componentByGroupId[$node->groupId] ?? $this->rootComponent;
            $node->scrollTop = (int) $component->getBindValue($scrollBindKey);
        }

        $children = $node->children;
        if ($children instanceof VNode) {
            $this->resolveVNodeBindings($children);
        } elseif (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof VNode) {
                    $this->resolveVNodeBindings($child);
                }
            }
        }
    }

    /**
     * 鼠标滚轮事件 — 更新最近祖先滚动容器的 scrollTop。
     */
    private function handleScrollWheel($event): void
    {
        $scrollNode = $this->findScrollContainerAt($event->x, $event->y, $this->activeVNodeTree);
        if ($scrollNode === null) return;

        $delta = $event->delta ?? 0;
        // Windows: 120 = one notch, scale down for smooth scroll
        $scrollAmount = (int)($delta / 40);

        $contentH = $scrollNode->contentHeight;
        $containerH = $scrollNode->h;
        $maxScroll = max($contentH - $containerH, 0);
        if ($maxScroll <= 0) return;

        $newScrollTop = max(0, min($maxScroll, $scrollNode->scrollTop - $scrollAmount));
        if ($newScrollTop !== $scrollNode->scrollTop) {
            $this->applyScrollTop($scrollNode, $newScrollTop, true);
        }
    }

    /**
     * 查找鼠标坐标下的滚动容器（最深层的子孙优先）。
     */
    private function findScrollContainerAt(int $x, int $y, VNode $node): ?VNode
    {
        $children = $node->children;
        if ($children instanceof VNode) {
            $found = $this->findScrollContainerAt($x, $y, $children);
            if ($found !== null) return $found;
        } elseif (is_array($children)) {
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $child = $children[$i];
                if ($child instanceof VNode) {
                    $child = objval($child, VNode::class);
                    $found = $this->findScrollContainerAt($x, $y, $child);
                    if ($found !== null) return $found;
                }
            }
        }

        if ($node->isScrollContainer
            && $x >= $node->x && $x <= $node->x + $node->w
            && $y >= $node->y && $y <= $node->y + $node->h) {
            return $node;
        }
        return null;
    }

    /**
     * 滚动条命中测试。
     * 返回 ['scrollNode' => VNode, 'type' => 'thumb'|'track'] 或 null。
     */
    private function hitTestScrollbar(int $x, int $y, VNode $node): ?array
    {
        $children = $node->children;
        if ($children instanceof VNode) {
            $result = $this->hitTestScrollbar($x, $y, $children);
            if ($result !== null) return $result;
        } elseif (is_array($children)) {
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $child = $children[$i];
                if ($child instanceof VNode) {
                    $child = objval($child, VNode::class);
                    $result = $this->hitTestScrollbar($x, $y, $child);
                    if ($result !== null) return $result;
                }
            }
        }

        if (!$node->isScrollContainer) return null;
        $contentH = $node->contentHeight;
        if ($contentH <= $node->h) return null;

        $sbW = 12;
        $sbX = $node->x + $node->w - $sbW;

        if ($x >= $sbX && $x <= $sbX + $sbW
            && $y >= $node->y && $y <= $node->y + $node->h) {
            $ratio = min($node->h / max($contentH, 1), 1.0);
            $thumbH = max((int)($node->h * $ratio), 20);
            $maxScroll = max($contentH - $node->h, 0);
            $scrollRatio = $maxScroll > 0 ? $node->scrollTop / $maxScroll : 0.0;
            $thumbY = $node->y + (int)(($node->h - $thumbH) * $scrollRatio);

            if ($y >= $thumbY && $y <= $thumbY + $thumbH) {
                return ['scrollNode' => $node, 'type' => 'thumb'];
            }
            return ['scrollNode' => $node, 'type' => 'track'];
        }

        return null;
    }

    /**
     * 滚动条点击处理：轨道 = 跳转，滑块 = 开始拖拽。
     */
    private function handleScrollbarDown(VNode $scrollNode, string $type, int $mouseY): void
    {
        $contentH = $scrollNode->contentHeight;
        $containerH = $scrollNode->h;
        $maxScroll = max($contentH - $containerH, 0);
        if ($maxScroll <= 0) return;

        $ratio = min($containerH / max($contentH, 1), 1.0);
        $thumbH = max((int)($containerH * $ratio), 20);
        $trackH = $containerH - $thumbH;

        if ($type === 'track') {
            $clickOffset = $mouseY - $scrollNode->y - (int)($thumbH / 2);
            $newScrollTop = (int)($maxScroll * $clickOffset / max($trackH, 1));
            $newScrollTop = max(0, min($maxScroll, $newScrollTop));
            $this->applyScrollTop($scrollNode, $newScrollTop, true);
        } elseif ($type === 'thumb') {
            $this->scrollDragTarget = $scrollNode;
            $this->scrollDragStartY = $mouseY;
            $this->scrollDragStartScrollTop = $scrollNode->scrollTop;
        }
    }

    /**
     * 滚动条拖拽 — 实时更新 scrollTop（不持久化到组件，避免频繁重建树）。
     */
    private function handleScrollbarDrag(int $mouseY): void
    {
        $node = $this->scrollDragTarget;
        if ($node === null) return;

        $contentH = $node->contentHeight;
        $containerH = $node->h;
        $maxScroll = max($contentH - $containerH, 0);
        if ($maxScroll <= 0) return;

        $ratio = min($containerH / max($contentH, 1), 1.0);
        $thumbH = max((int)($containerH * $ratio), 20);
        $trackH = $containerH - $thumbH;

        $dy = $mouseY - $this->scrollDragStartY;
        $scrollDy = (int)($maxScroll * $dy / max($trackH, 1));
        $newScrollTop = max(0, min($maxScroll, $this->scrollDragStartScrollTop + $scrollDy));

        if ($newScrollTop !== $node->scrollTop) {
            $this->applyScrollTop($node, $newScrollTop, false);
        }
    }

    /**
     * 应用 scrollTop 到 VNode，可选持久化到组件 bind 值。
     *
     * persist=true:  持久化到组件 + 请求重建树（滚轮、轨道点击、拖拽结束）
     * persist=false: 仅修改 VNode + 直接重绘（拖拽过程中，避免整树重建）
     */
    private function applyScrollTop(VNode $node, int $newScrollTop, bool $persist): void
    {
        $node->scrollTop = $newScrollTop;

        if ($persist) {
            $bindKey = $node->props[':scroll-top'] ?? '';
            if ($bindKey !== '') {
                $target = $this->resolveComponent($node);
                $target->setBindValue($bindKey, (string) $newScrollTop);
            }
            $this->requestRender();
        } else {
            // 拖拽中：跳过组件更新，直接在现有树上重绘
            $this->directRender($this->activeVNodeTree);
        }
    }

    /**
     * 直接渲染（跳过 VNode 树重建），用于拖拽滚动等高频操作。
     */
    private function directRender(VNode $tree): void
    {
        $this->layoutResolver->resolve($tree);
        $this->renderer->render($tree);
    }

    private function render(): void
    {
        $this->rebuildVNodeTree();
        $this->layoutResolver->resolve($this->activeVNodeTree);
        $this->renderer->render($this->activeVNodeTree);
    }

    private function doFirstRender(): void
    {
        $this->render();
        $this->scheduler->flushMicrotasks();
        if ($this->renderRequested) {
            $this->renderRequested = false;
            $this->render();
        }
    }

    /**
     * 事件循环
     */
    public function run(): void
    {
        $this->doFirstRender();

        while ($this->running) {
            $rawEvents = $this->platform->pollEvents();
            foreach ($rawEvents as $ev) {
                if ($ev->type === 'mouse') {
                    $this->handleMouseEvent($ev);
                } elseif ($ev->type === 'keyboard') {
                    $this->handleKeyboardEvent($ev);
                }
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
        $this->platform->shutdown();
    }

    /**
     * 命中测试：找到鼠标坐标命中的最顶层可交互元素。
     *
     * 设计原则（对标 CSS / Flutter）：
     *   1. 反向遍历子节点 —— 后渲染的在视觉上层，应优先命中
     *   2. Layer 感知 —— z-index 创建的层叠上下文控制命中优先级
     *   3. 返回命中的 VNode，调用方通过 groupId 路由到对应组件
     *
     * 注意：v-if 已在编译期处理，false 分支不会出现在 VNode 树中。
     *
     * @param int   $x    鼠标 X 坐标
     * @param int   $y    鼠标 Y 坐标
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
            // 反向: 数组末尾元素渲染在最上层（对标 CSS painting order）
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
     * 查找 VNode 树中第一个有键盘处理器的 input 元素。
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
