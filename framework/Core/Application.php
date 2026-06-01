<?php

namespace Px\Core;

use Px\Platform\Platform;
use Px\Platform\PlatformFactory;
use Px\Rendering\VNode;
use Px\Rendering\RenderNode;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\LayoutResolver;
use Px\Rendering\CssMappings;
use Px\Rendering\RenderTreeManager;
use Px\ReactiveComponent;
use Px\Styling\Theme\ThemeData;
use Px\Styling\Provider\ThemeProvider;
use Px\Styling\Adapter\PlatformAdapter;

/**
 * Application — AOT 框架入口（RenderNode 版）
 *
 * 持有：Platform、Scheduler、RenderTreeManager
 * 事件循环：
 *   平台事件 → 命中测试 → 组件方法 → 微任务 → 渲染 → 宏任务
 *
 * 渲染流程（重构后）：
 *   VNode 树重建 → RenderTreeManager::updateFromVNode（VNode → RenderNode + bind 值同步）
 *   → LayoutResolver::resolve（RenderNode 坐标计算）
 *   → VNodeRenderer::render（RenderNode 树 → GDI 调用）
 */
class Application
{
    private Platform $platform;
    private Scheduler $scheduler;
    private ?VNodeRenderer $renderer = null;
    private LayoutResolver $layoutResolver;
    private RenderTreeManager $renderTreeManager;

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
        $this->renderTreeManager = new RenderTreeManager();
        $this->scrollManager = new ScrollManager(
            $this->requestRender(...),
            function () { $this->directRender(); },
            $this->resolveComponent(...),
            $this->renderTreeManager
        );
        // VNodeRenderer 依赖 RenderContext，在 initRenderer() 中初始化
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
            $this->scrollManager->handleScrollWheel($event, $this->renderTreeManager->getRootRenderNode());
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
            $sbResult = $this->scrollManager->hitTestScrollbar($event->x, $event->y, $this->renderTreeManager->getRootRenderNode());
            if ($sbResult !== null) {
                $this->scrollManager->handleScrollbarDown(
                    $sbResult['scrollNode'],
                    $sbResult['type'],
                    $event->x, $event->y,
                    $sbResult['isHorizontal']
                );
                return;
            }

            // hitTest 返回 RenderNode，通过 sourceVNode 访问 props
            $renderNode = $this->renderTreeManager->hitTest($event->x, $event->y);
            if ($renderNode !== null) {
                $sourceVNode = $renderNode->sourceVNode;
                if ($sourceVNode !== null && isset($sourceVNode->props['@click'])) {
                    $handler = $sourceVNode->props['@click'];
                    $arg = $sourceVNode->props['click-arg'] ?? null;
                    $target = $this->resolveComponent($sourceVNode);
                    $target->dispatchClick($handler, $arg);
                }
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
     * 找不到时回退到根组件。
     */
    public function resolveComponent(VNode $node): ReactiveComponent
    {
        return $this->componentByGroupId[$node->groupId] ?? $this->rootComponent;
    }

    public function getPlatform(): Platform   { return $this->platform; }
    public function getScheduler(): Scheduler { return $this->scheduler; }
    public function getRenderTreeManager(): RenderTreeManager { return $this->renderTreeManager; }

    private function initRenderer(): void
    {
        $render_ctx = $this->platform->init(WINDOW_TITLE, WINDOW_WIDTH, WINDOW_HEIGHT);
        $this->renderer = new VNodeRenderer($this->rootComponent, $render_ctx);
    }

    public function mount(ReactiveComponent $root): self
    {
        $this->rootComponent = $root;
        $this->rootComponent->setScheduler($this->scheduler);
        $this->rootComponent->setRenderCallback($this->handleRenderRequest(...));
        $this->registerComponent('app', $root);

        $this->initRenderer();

        // 初始化主题系统
        $baseTheme = ThemeData::light();
        $platformStyling = PlatformAdapter::create(APP_PLATFORM, $baseTheme);
        $finalTheme = $platformStyling->apply($baseTheme);
        ThemeProvider::inject($finalTheme);

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
            return;
        }
        $this->isRendering = true;

        $oldTree = $this->activeVNodeTree;
        $oldRegistry = $this->componentByGroupId;

        $this->componentByGroupId = [];
        $this->registerComponent('app', $this->rootComponent);

        $this->activeVNodeTree = $this->rootComponent->getVNodeTree();

        $this->patchComponentTree(
            $this->activeVNodeTree,
            $this->rootComponent,
            $oldTree
        );

        // 卸载不再存在的旧实例
        foreach ($oldRegistry as $id => $instance) {
            if ($id !== 'app' && !isset($this->componentByGroupId[$id])) {
                $instance->unmount();
            }
        }

        $this->isRendering = false;
    }

    // ── 展开组件 ──────────────────────────────

    private function expandComponentNode(VNode $node, ReactiveComponent $owner): void
    {
        $className = $node->componentClass;
        if ($className === null) return;

        $instance = \ComponentFactory::create($className);
        $instance->setScheduler($this->scheduler);
        $instance->setRenderCallback($this->handleRenderRequest(...));
        $instance->setParent($owner);
        $instance->mount();

        $instanceId = $node->componentClass . '_' . $this->nextComponentId++;
        $instance->setId($instanceId);
        $this->registerComponent($instanceId, $instance);

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

        if (method_exists($instance, 'getClassStyles')) {
            $cs = $instance->getClassStyles();
            ThemeProvider::registerClassStyles(
                get_class($instance),
                $cs
            );
        }

        // 必须使用 getVNodeTree() 而非 render()，确保结果缓存到 vnodeCache。
        // 否则后续 updateFromVNode() 调用 $instance->getVNodeTree() 时会再次执行 render()，
        // 返回一个未经过 transferComponentPositioning 修改的新 VNode 树，导致 left/top 定位丢失。
        $childRoot = $instance->getVNodeTree();

        $node->componentInstance = $instance;
        $node->children = $childRoot;

        // 将 #component 占位符的 style(left/top) 传递到子组件根元素 VNode 的 style
        // 这样 RenderTreeManager 无需做任何坐标计算，LayoutResolver 统一处理
        $placeholderStyle = $node->props['style'] ?? '';
        if ($placeholderStyle !== '') {
            $this->transferComponentPositioning($placeholderStyle, $childRoot);
        }

        $this->setGroupIdRecursive($childRoot, $instanceId);

        $this->patchComponentTree($node->children, $instance, null);
    }

    /**
     * 将 #component 占位符的 left/top 定位传递到子组件根元素 VNode 的 style。
     *
     * #component 是语义透明的占位符，不产生 RenderNode。其 style(left/top)
     * 表示子组件应出现的位置，需要写入子组件根元素的 style，以便 LayoutResolver
     * 在布局阶段统一处理。
     *
     * AOT 安全：仅使用字符串操作和数组遍历。
     */
    private function transferComponentPositioning(string $placeholderStyle, VNode $componentRoot): void
    {
        if ($placeholderStyle === '') {
            return;
        }

        // 解析 left/top 值
        $left = null;
        $top = null;
        $pairs = explode(';', $placeholderStyle);
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            $lower = strtolower($pair);
            if (str_starts_with($lower, 'left:')) {
                $left = (int) trim(substr($pair, 5));
            } elseif (str_starts_with($lower, 'top:')) {
                $top = (int) trim(substr($pair, 4));
            }
        }

        if ($left === null && $top === null) {
            return;
        }

        // 遍历 #root 链找到第一个可渲染元素（跳过 #root、#text 等非渲染节点）
        $target = $componentRoot;
        while ($target !== null && $target->type === '#root') {
            $children = $target->children;
            if ($children instanceof VNode) {
                $target = $children;
            } elseif (is_array($children)) {
                $next = null;
                foreach ($children as $child) {
                    if ($child instanceof VNode && $child->type !== '#text') {
                        $next = $child;
                        break;
                    }
                }
                $target = $next;
            } else {
                $target = null;
            }
        }

        if ($target === null || $target === $componentRoot) {
            return;
        }

        // 将现有 style 解析为数组 → 结构化合并 → 序列化回字符串
        $existingStyle = $target->props['style'] ?? '';
        $styleArray = CssMappings::parseStyleStringToArray($existingStyle);
        // 移除已有的 left/top（保证幂等性）
        unset($styleArray['left'], $styleArray['top']);
        // 写入新的定位值（带 px 单位，与原始解析值格式一致）
        if ($left !== null) { $styleArray['left'] = $left . 'px'; }
        if ($top !== null) { $styleArray['top'] = $top . 'px'; }
        $target->props['style'] = CssMappings::buildStyleStringFromArray($styleArray);
    }

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

    private function patchComponentTree(
        VNode $newNode,
        ReactiveComponent $owner,
        ?VNode $oldNode = null
    ): void {
        if (!$newNode->isComponent()) {
            $newNode->groupId = $owner->getId();
        }

        if ($newNode->isComponent()) {
            $this->matchComponentNode($newNode, $owner, $oldNode);
            return;
        }

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

        for ($i = $count; $i < count($newChildren); $i++) {
            $this->patchComponentTree($newChildren[$i], $owner, null);
        }
    }

    private function matchComponentNode(
        VNode $newNode,
        ReactiveComponent $owner,
        ?VNode $oldNode = null
    ): void {
        $instance = null;

        if ($newNode->componentInstance !== null) {
            $instance = $newNode->componentInstance;
        } elseif ($oldNode !== null && $oldNode->isComponent()) {
            $sameClass = $oldNode->componentClass === $newNode->componentClass;
            $sameKey   = ($oldNode->key ?? '') === ($newNode->key ?? '');
            if ($sameClass && $sameKey) {
                $instance = $oldNode->componentInstance;
            }
        }

        if ($instance !== null) {
            $instance->setParent($owner);

            if ($newNode->componentProps !== null) {
                foreach ($newNode->componentProps as $childKey => $parentExpr) {
                    if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                        $instance->setBindValue($childKey, substr($parentExpr, 7));
                    } else {
                        $instance->setBindValue($childKey, $owner->getBindValue($parentExpr));
                    }
                }
            }

            $newNode->componentInstance = $instance;
            $newNode->children = $instance->getVNodeTree();

            // 将 #component 占位符的 left/top 定位传递到子组件根元素 VNode 的 style
            // 每次更新都调用 transferComponentPositioning，保证幂等性
            // 解决子组件 markDirty 后重新 render() 时新 VNode 树丢失定位的问题
            $placeholderStyle = $newNode->props['style'] ?? '';
            if ($placeholderStyle !== '') {
                $this->transferComponentPositioning($placeholderStyle, $newNode->children);
            }

            $this->setGroupIdRecursive($newNode->children, $instance->getId());
            $this->registerComponent($instance->getId(), $instance);

            $this->patchComponentTree(
                $newNode->children,
                $instance,
                $oldNode !== null ? $oldNode->children : null
            );
        } else {
            if ($oldNode !== null && $oldNode->componentInstance !== null) {
                $oldNode->componentInstance->unmount();
            }
            $this->expandComponentNode($newNode, $owner);
        }
    }

    private function vnodeChildrenToArray(mixed $children): array
    {
        if ($children === null) return [];
        if ($children instanceof VNode) return [$children];
        if (is_array($children)) {
            return array_values(array_filter($children, fn($c) => $c instanceof VNode));
        }
        return [];
    }

    // ── 渲染系统 ──────────────────────────────

    /**
     * 直接渲染（跳过 VNode 树重建），用于拖拽滚动等高频操作。
     * 使用上次缓存的 RenderNode 树，跳过 updateFromVNode。
     */
    public function directRender(): void
    {
        $root = $this->renderTreeManager->getRootRenderNode();
        if ($root === null) return;

        $this->layoutResolver->resolve($root);
        $this->renderer->render($root);
    }

    /**
     * 完整渲染流程：
     *   1. 重建 VNode 树（含组件展开）
     *   2. RenderTreeManager::updateFromVNode 转换并同步 bind 值
     *   3. LayoutResolver::resolve 计算坐标
     *   4. VNodeRenderer::render 生成 GDI 调用
     */
    private function render(): void
    {
        $this->rebuildVNodeTree();

        // getRootRenderNodes() = 顶层 #root 所有旧子节点，作为 candidates 传递给 #root handler
        $oldRootChildren = $this->renderTreeManager->getRootRenderNodes();
        $candidates = !empty($oldRootChildren) ? $oldRootChildren : null;

        // VNode → RenderNode 转换 + bind 值同步（type+key 匹配复用）
        $rootRenderNode = $this->renderTreeManager->updateFromVNode(
            $this->activeVNodeTree,
            null,
            $this->rootComponent,
            $this->componentByGroupId,
            $candidates
        );
        if ($rootRenderNode === null) return;

        // LayoutResolver 处理 RenderNode（利用 layoutDirty 增量）
        $this->layoutResolver->resolve($rootRenderNode);

        // VNodeRenderer 处理 RenderNode（利用 paintDirty 增量）
        $this->renderer->render($rootRenderNode);
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

    // ── 事件循环 ──────────────────────────────

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

    // ── 键盘事件辅助 ──────────────────────────

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
