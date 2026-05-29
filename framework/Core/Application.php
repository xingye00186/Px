<?php

namespace Px\Core;

use Px\Platform\Platform;
use Px\Platform\PlatformFactory;
use Px\Rendering\VNode;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\LayoutResolver;
use Px\ReactiveComponent;
use Px\Styling\Theme\ThemeData;
use Px\Styling\Provider\ThemeProvider;
use Px\Styling\Adapter\PlatformAdapter;

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

    private int $nextComponentId = 1;
    private bool $isRendering = false;

    private ScrollManager $scrollManager;

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
        $this->scrollManager = new ScrollManager(
            $this->requestRender(...),
            function () { $this->directRender($this->activeVNodeTree); },
            $this->resolveComponent(...)
        );
        $this->renderer = new VNodeRenderer();
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
            $this->scrollManager->handleScrollWheel($event, $this->activeVNodeTree);
            return;
        }

        // ── 鼠标拖动：滚动条拖拽 ──────────────
        if ($event->action === 'move') {
            $this->scrollManager->handleScrollbarDrag($event->x, $event->y);
            return;
        }

        // ── 鼠标释放：结束拖拽，持久化滚动位置 ──
        if ($event->action === 'up') {
            $this->scrollManager->handleMouseUp();
            return;
        }

        // ── 鼠标按下：优先检测滚动条，其次 @click ──
        if ($event->action === 'down') {
            $sbResult = $this->scrollManager->hitTestScrollbar($event->x, $event->y, $this->activeVNodeTree);
            if ($sbResult !== null) {
                $this->scrollManager->handleScrollbarDown(
                    $sbResult['scrollNode'],
                    $sbResult['type'],
                    $event->x, $event->y,
                    $sbResult['isHorizontal']
                );
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

        // 初始化主题系统
        $baseTheme = ThemeData::light();
        $platformStyling = PlatformAdapter::create(APP_PLATFORM, $baseTheme);
        $finalTheme = $platformStyling->apply($baseTheme);
        ThemeProvider::inject($finalTheme);

        // 注册根组件的编译后 class styles
        if (method_exists($this->rootComponent, 'getClassStyles')) {
            $rootCs = $this->rootComponent->getClassStyles();
            ThemeProvider::registerClassStyles(
                get_class($this->rootComponent),
                $rootCs
            );
        }

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
        if ($this->isRendering) {
            return; // 防止重入
        }
        $this->isRendering = true;

        // 保存旧树和旧注册表
        $oldTree = $this->activeVNodeTree;
        $oldRegistry = $this->componentByGroupId;

        // 清空注册表
        $this->componentByGroupId = [];
        $this->registerComponent('app', $this->rootComponent);

        // 渲染新树
        $this->activeVNodeTree = $this->rootComponent->getVNodeTree();

        // Patch：传递旧树用于匹配
        $this->patchComponentTree(
            $this->activeVNodeTree,
            $this->rootComponent,
            $oldTree
        );

        // 将组件的 bind 值写入 VNode (如 :scroll-top → scrollTop)
        $this->resolveVNodeBindings($this->activeVNodeTree);

        // 卸载不再存在的旧实例
        foreach ($oldRegistry as $id => $instance) {
            if ($id !== 'app' && !isset($this->componentByGroupId[$id])) {
                $instance->unmount();
            }
        }

        $this->isRendering = false;
    }

    /**
     * 展开单个组件占位节点。
     * 
     * Vue 3 语义：每个 #component VNode 创建独立的组件实例，
     * 而不是按类名共享。
     *
     * @param VNode $node   #component 占位节点
     * @param ReactiveComponent $owner  父组件（即拥有此占位的组件）
     */
    private function expandComponentNode(VNode $node, ReactiveComponent $owner): void
    {
        $className = $node->componentClass;
        if ($className === null) return;

        // 为每个 VNode 节点创建独立的组件实例（Vue 3 语义）
        $instance = \ComponentFactory::create($className);
        $instance->setScheduler($this->scheduler);
        $instance->setRenderCallback($this->handleRenderRequest(...));
        $instance->setParent($owner);
        $instance->mount();

        // 使用计数器生成唯一实例 ID
        $instanceId = $node->componentClass . '_' . $this->nextComponentId++;
        $instance->setId($instanceId);
        $this->registerComponent($instanceId, $instance);

        // 传递 props：父组件的 bind key → 子组件的属性
        if ($node->componentProps !== null && $owner !== null) {
            foreach ($node->componentProps as $childKey => $parentExpr) {
                if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                    $staticValue = substr($parentExpr, 7);
                    $instance->setBindValue($childKey, $staticValue);
                } else {
                    $parentValue = $owner->getBindValue($parentExpr);
                    $instance->setBindValue($childKey, $parentValue);
                }
            }
        }

        // 注册子组件的编译后 class styles（必须在 render() 之前注册）
        if (method_exists($instance, 'getClassStyles')) {
            $cs = $instance->getClassStyles();
            ThemeProvider::registerClassStyles(
                get_class($instance),
                $cs
            );
        }

        // 展开子树（强制重建，因为 props 可能改变了组件状态）
        $childRoot = $instance->render();

        $node->componentInstance = $instance;
        $node->children = $childRoot;

        // 设置 groupId 用于事件路由
        $this->setGroupIdRecursive($childRoot, $instanceId);

        // 递归处理子组件树中的 #component 节点
        $this->patchComponentTree($node->children, $instance, null);
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

    /**
     * 遍历子树，处理所有 #component 节点。
     *
     * @param VNode            $newNode  新树当前节点
     * @param ReactiveComponent $owner   当前子树的拥有者组件
     * @param VNode|null       $oldNode  旧树对应节点（用于匹配）
     */
    private function patchComponentTree(
        VNode $newNode,
        ReactiveComponent $owner,
        ?VNode $oldNode = null
    ): void {
        // ── 非 #component 节点：确保 groupId 归属于正确的组件 ──
        if (!$newNode->isComponent()) {
            $newNode->groupId = $owner->getId();
        }

        // ── #component 节点：匹配或创建 ──
        if ($newNode->isComponent()) {
            $this->matchComponentNode($newNode, $owner, $oldNode);
            return;
        }

        // ── 普通节点：位置并行走访子节点 ──
        $oldChildren = $oldNode !== null
            ? $this->vnodeChildrenToArray($oldNode->children)
            : [];
        $newChildren = $this->vnodeChildrenToArray($newNode->children);

        $count = min(count($oldChildren), count($newChildren));
        for ($i = 0; $i < $count; $i++) {
            $this->patchComponentTree(
                $newChildren[$i],
                $owner,
                $oldChildren[$i]
            );
        }

        // 新树多出的子节点
        for ($i = $count; $i < count($newChildren); $i++) {
            $this->patchComponentTree($newChildren[$i], $owner, null);
        }
    }

    /**
     * 匹配单个 #component 节点。
     * 优先级：① 新节点已有实例（缓存）→ 复用
     *         ② 旧节点匹配（同 componentClass + 同 key）→ 转移实例
     *         ③ 均不满足 → 创建新实例
     */
    private function matchComponentNode(
        VNode $newNode,
        ReactiveComponent $owner,
        ?VNode $oldNode = null
    ): void {
        $instance = null;

        // 优先级 1：新节点已有实例（来自父组件缓存树）
        if ($newNode->componentInstance !== null) {
            $instance = $newNode->componentInstance;
        }
        // 优先级 2：从旧树匹配（父组件 dirty，新树无实例）
        elseif ($oldNode !== null && $oldNode->isComponent()) {
            $sameClass = $oldNode->componentClass === $newNode->componentClass;
            $sameKey   = ($oldNode->key ?? '') === ($newNode->key ?? '');
            if ($sameClass && $sameKey) {
                $instance = $oldNode->componentInstance;
            }
        }

        if ($instance !== null) {
            // ── 复用实例 ──
            $instance->setParent($owner);

            // 同步 props
            if ($newNode->componentProps !== null) {
                foreach ($newNode->componentProps as $childKey => $parentExpr) {
                    if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                        $instance->setBindValue($childKey, substr($parentExpr, 7));
                    } else {
                        $instance->setBindValue($childKey, $owner->getBindValue($parentExpr));
                    }
                }
            }

            // 获取子树（解析 dirty 状态）
            $newNode->componentInstance = $instance;
            $newNode->children = $instance->getVNodeTree();

            // 注册事件路由
            $this->setGroupIdRecursive($newNode->children, $instance->getId());
            $this->registerComponent($instance->getId(), $instance);

            // 递归处理子树的 #component 节点
            $this->patchComponentTree(
                $newNode->children,
                $instance,
                $oldNode !== null ? $oldNode->children : null
            );
        } else {
            // ── 创建新实例 ──
            if ($oldNode !== null && $oldNode->componentInstance !== null) {
                $oldNode->componentInstance->unmount();
            }
            $this->expandComponentNode($newNode, $owner);
        }
    }

    /**
     * 将 VNode 的 children 统一为数组，用于位置并行走访。
     */
    private function vnodeChildrenToArray(mixed $children): array
    {
        if ($children === null) return [];
        if ($children instanceof VNode) return [$children];
        if (is_array($children)) {
            return array_values(array_filter($children, fn($c) => $c instanceof VNode));
        }
        return [];
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

        // :scroll-left bind → VNode::scrollLeft
        $scrollLeftBindKey = $node->props[':scroll-left'] ?? '';
        if ($scrollLeftBindKey !== '') {
            $component = $this->componentByGroupId[$node->groupId] ?? $this->rootComponent;
            $node->scrollLeft = (int) $component->getBindValue($scrollLeftBindKey);
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
     * 直接渲染（跳过 VNode 树重建），用于拖拽滚动等高频操作。
     */
    public function directRender(VNode $tree): void
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
