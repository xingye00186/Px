<?php

namespace Px\Core;

use native_types;

use Px\Platform\Platform;
use Px\Platform\PlatformEvent;
use Px\Platform\MouseEvent;
use Px\Platform\KeyboardEvent;
use Px\Platform\PlatformFactory;
use Px\Rendering\Backend\ResilientRenderContext;
use Px\Rendering\Backend\RuntimeBackendSelector;
use Px\Rendering\VNode;
use Px\Rendering\RenderNode;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\LayoutResolver;
use Px\Rendering\CssMappings;
use Px\Rendering\ImageManager;
use Px\Rendering\RenderTreeManager;
use Px\ReactiveComponent;
use Px\Styling\Theme\ThemeData;
use Px\Styling\Provider\ThemeProvider;
use Px\Styling\Adapter\PlatformAdapter;
use Px\Core\Config;

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

    /** 当前鼠标光标类型：'' 默认, 'pointer' 手型 */
    private string $currentCursor = '';

    /** @var array<string, ReactiveComponent> VNode.groupId → Component instance */
    private array $componentByGroupId = [];

    private int $nextComponentId = 1;
    private bool $isRendering = false;

    // ── 调试快照 ──
    /** @var array 事件环形缓冲区（最近 N 个平台事件） */
    private array $eventRingBuffer = [];
    private int $eventBufferSize = 5;
    private int $eventBufferIndex = 0;
    private int $frameCounter = 0;
    /** @var bool 主动请求 snapshot 标记（仅在用户交互后设置） */
    private bool $snapshotRequested = false;

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
            $this->resolveComponentByGroupId(...)
        );
        // VNodeRenderer 依赖 RenderContext，在 initRenderer() 中初始化
    }

    // ── 平台事件处理器 ─────────────────────────

    private function handleRenderRequest(): void
    {
        $this->renderRequested = true;
    }

    private function handleMouseEvent(MouseEvent $event): void
    {
        if ($this->activeVNodeTree === null) {
            return;
        }

        $action = $event->getAction();
        $this->recordEvent('mouse:' . $action, $event->getX(), $event->getY());

        // ── 鼠标滚轮：驱动滚动容器 ────────────
        if ($event->getAction() === 'wheel') {
            $rootNode = $this->renderTreeManager->getRootRenderNode();
            if ($rootNode !== null) {
                $this->scrollManager->handleScrollWheel($event, $rootNode);
                $this->snapshotRequested = true;
            }
            return;
        }

        // ── 鼠标拖动：滚动条拖拽 + 光标 hover ──
        if ($event->getAction() === 'move') {
            // 先处理滚动条拖拽
            $this->scrollManager->handleScrollbarDrag($event->getX(), $event->getY());

            // 然后检查光标状态（仅当不在拖拽状态时）
            if (!$this->scrollManager->isDragging()) {
                $hoverNode = $this->renderTreeManager->hitTest($event->getX(), $event->getY());
                $newCursor = '';
                if ($hoverNode !== null) {
                    $cursorStyle = $hoverNode->style['cursor'] ?? '';
                    if ($cursorStyle === 'pointer' || $cursorStyle === 'hand') {
                        $newCursor = 'pointer';
                    }
                }
                if ($newCursor !== $this->currentCursor) {
                    $this->currentCursor = $newCursor;
                    $this->platform->setCursor($newCursor);
                }
            }
            return;
        }

        // ── 鼠标释放：结束拖拽，持久化滚动位置 ──
        if ($event->getAction() === 'up') {
            $this->scrollManager->handleMouseUp();
            $this->snapshotRequested = true;
            return;
        }

        // ── 鼠标按下：优先检测滚动条，其次 @click ──
        if ($event->getAction() === 'down') {
            $rootNode = $this->renderTreeManager->getRootRenderNode();
            if ($rootNode !== null) {
                $sbResult = $this->scrollManager->hitTestScrollbar($event->getX(), $event->getY(), $rootNode);
                if ($sbResult !== null) {
                    $this->scrollManager->handleScrollbarDown(
                        $sbResult['scrollNode'],
                        $sbResult['type'],
                        $event->getX(), $event->getY(),
                        $sbResult['isHorizontal']
                    );
                    return;
                }
            }

            // hitTest 返回 RenderNode，通过 sourceVNode 访问 props
            // groupId 直接使用 RenderNode.groupId（由 updateFromVNode 设置，不再读取 VNode.groupId）
            $renderNode = $this->renderTreeManager->hitTest($event->getX(), $event->getY());
            if ($renderNode !== null) {
                $sourceVNode = $renderNode->sourceVNode;
                if ($sourceVNode !== null && isset($sourceVNode->props['@click'])) {
                    $handler = $sourceVNode->props['@click'];
                    $arg = $sourceVNode->props['click-arg'] ?? null;
                    $target = $this->resolveComponentByGroupId($renderNode->groupId);
                    $target->dispatchClick($handler, $arg);
                    $this->snapshotRequested = true;
                }
            }
        }
    }

    private function handleKeyboardEvent(KeyboardEvent $event): void
    {
        if ($this->activeVNodeTree === null) {
            return;
        }

        $this->recordEvent('keyb:' . $event->getAction(), -1, -1, $event->getChar());

        $input = $this->findFocusedInput($this->activeVNodeTree);
        if ($input === null) {
            return;
        }
        // 通过 RenderNode 获取 groupId（替代已废弃的 VNode.groupId 读取）
        $inputRN = $this->renderTreeManager->findRenderNodeBySourceVNode($input);
        if ($inputRN === null) return;
        $target = $this->resolveComponentByGroupId($inputRN->groupId);
        $action = $event->getAction();
        if ($action === 'down') {
            $handler = $input->props['@keydown'] ?? null;
            if ($handler !== null) {
                $target->dispatchKey($handler, $action, $event->getKeyCode(), $event->getChar());
                $this->snapshotRequested = true;
            }
        } elseif ($action === 'up') {
            $handler = $input->props['@keyup'] ?? null;
            if ($handler !== null) {
                $target->dispatchKey($handler, $action, $event->getKeyCode(), $event->getChar());
                $this->snapshotRequested = true;
            }
        } elseif ($action === 'char') {
            $handler = $input->props['@enter'] ?? null;
            if ($handler !== null && $event->getKeyCode() === 13) {
                $target->dispatchKey($handler, $action, $event->getKeyCode(), $event->getChar());
                $this->snapshotRequested = true;
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
     * 根据 groupId 查找目标组件。
     * 找不到时回退到根组件。
     */
    public function resolveComponentByGroupId(string $groupId): ReactiveComponent
    {
        return $this->componentByGroupId[$groupId] ?? $this->rootComponent;
    }

    public function getPlatform(): Platform   { return $this->platform; }
    public function getScheduler(): Scheduler { return $this->scheduler; }
    public function getRenderTreeManager(): RenderTreeManager { return $this->renderTreeManager; }

    private ?string $selectedBackendName = null;

    public function getSelectedBackendName(): ?string
    {
        return $this->selectedBackendName;
    }

    private function initRenderer(): void
    {
        $w = WINDOW_WIDTH;
        $h = WINDOW_HEIGHT;

        // Stage 1: 让 platform 创建窗口 + 默认 RenderContext（测试环境直接使用此 context）
        $defaultCtx = $this->platform->init(WINDOW_TITLE, $w, $h);

        // 检查 C++ 绑定是否可用：无 vue_begin_paint 说明是测试环境（PHP-only），跳过后端选择
        if (!function_exists('vue_begin_paint')) {
            $this->renderer = new VNodeRenderer($this->rootComponent, $defaultCtx);
            return;
        }

        unset($defaultCtx);  // 显式释放默认 RC，让 RuntimeBackendSelector 创建最优后端

        // Stage 2: 用 RuntimeBackendSelector 探测 + 选择最优后端
        $hwnd = $this->platform->getHwnd();
        $selector  = new RuntimeBackendSelector();
        $backend   = $selector->select($hwnd, $w, $h);
        $this->selectedBackendName = $backend->getName();

        // Stage 3: 包一层 ResilientRenderContext 支持运行时降级
        $renderCtx = new ResilientRenderContext($selector, $backend->getContext(), $hwnd, $w, $h);

        $this->renderer = new VNodeRenderer($this->rootComponent, $renderCtx);
    }

    public function mount(ReactiveComponent $root, string $appDir = ''): self
    {
        $this->rootComponent = $root;
        $this->rootComponent->setScheduler($this->scheduler);
        $this->rootComponent->setRenderCallback($this->handleRenderRequest(...));
        $this->registerComponent('app', $root);

        // 初始化图片管理器（必须早于任何图片加载）
        if ($appDir !== '') {
            ImageManager::setAppRoot($appDir);
        }

        // 初始化调试配置（从 px_debug.yml）
        if ($appDir !== '') {
            Config::init($appDir);
        }

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
        
        // 设置动画/定时渲染定时器（1 秒间隔，用于 carousel 等定时功能）
        // 优化：仅当根组件定义了 onTimerTick 时才触发 requestRender，
        // 避免静态页面空转渲染浪费 CPU（快照分析显示 49 帧完全相同）
        $this->platform->setAnimationTimer(function () {
            if ($this->rootComponent !== null && method_exists($this->rootComponent, 'onTimerTick')) {
                $this->rootComponent->onTimerTick();
                $this->requestRender();
            }
        }, 1000);

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

        if (Config::get('diag_enabled', false)) {
            $isSame = $oldTree !== null && $this->activeVNodeTree === $oldTree;
            error_log("[DIAG] rebuildVNodeTree: sameTree=" . ($isSame ? 'YES' : 'NO')
                . " oldReg=" . count($oldRegistry)
                . " newReg=" . count($this->componentByGroupId));
        }

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

        // ── VNode 级 grid 子节点诊断 ──
        if (Config::get('diag_enabled', false)) {
            $this->diagGridChildrenInVNode($this->activeVNodeTree);
        }
    }

    /**
     * 递归遍历 VNode 树，找到 display:grid 的节点并记录 children 数量
     */
    private function diagGridChildrenInVNode(VNode $node): void
    {
        if ($node->isComponent()) {
            // #component 节点：检查其展开后的 children
            if ($node->children !== null) {
                $this->diagGridChildrenInVNodeChildren($node->children);
            }
            return;
        }
        $style = $node->props['style'] ?? '';
        if (str_contains($style, 'display:grid') || str_contains($style, 'display: grid')) {
            $childCnt = 0;
            if ($node->children instanceof VNode) {
                $childCnt = 1;
            } elseif (is_array($node->children)) {
                $childCnt = count($node->children);
            }
            error_log('[DIAG] VNODE grid: children=' . $childCnt
                . ' style="' . $style . '"');
        }
        // 递归子节点
        $this->diagGridChildrenInVNodeChildren($node->children);
    }

    private function diagGridChildrenInVNodeChildren(mixed $children): void
    {
        if ($children instanceof VNode) {
            $this->diagGridChildrenInVNode($children);
        } elseif (is_array($children)) {
            foreach ($children as $child) {
                if ($child instanceof VNode) {
                    $this->diagGridChildrenInVNode($child);
                }
            }
        }
    }

    // ── 展开组件 ──────────────────────────────

    private function expandComponentNode(VNode $node, ReactiveComponent $owner): VNode
    {
        $className = $node->componentClass;
        if ($className === null) return $node;

        $instance = \ComponentFactory::create($className);
        $instance->setScheduler($this->scheduler);
        $instance->setRenderCallback($this->handleRenderRequest(...));
        $instance->setParent($owner);
        $instance->mount();

        $instanceId = $node->componentClass . '_' . $this->nextComponentId++;
        $instance->setId($instanceId);
        $this->registerComponent($instanceId, $instance);

        if (Config::get('diag_enabled', false)) {
            error_log("[DIAG] expandComponentNode: class={$className} id={$instanceId}"
                . " owner=" . get_class($owner)
                . " hasPropVals=" . ($node->componentPropValues !== null ? 'YES' : 'NO'));
        }

        // ── VideoGridComponent 诊断：检查 mount 后的数据状态 ──
        if (Config::get('diag_enabled', false) && $className === 'VideoGridComponent') {
            $allVCnt = (int)(property_exists($instance, 'allVideos') ? count($instance->allVideos) : -1);
            $vlCnt = (int)(property_exists($instance, 'videoList') ? count($instance->videoList) : -1);
            error_log('[DIAG] VGRID after mount: allVideos=' . $allVCnt . ' videoList=' . $vlCnt);
        }

        if ($node->componentPropValues !== null) {
            // V-for loop: pre-computed direct values, skip bind key lookup
            foreach ($node->componentPropValues as $childKey => $value) {
                $instance->setBindValue($childKey, $value);
            }
        } elseif ($node->componentProps !== null && $owner !== null) {
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
        // 返回一个新 VNode 树，此时 layoutOffset 已设置在占位节点上，定位由 RenderTreeManager 处理。
        $childRoot = $instance->getVNodeTree();

        // ── B2 不可变性：克隆 VNode 再设置 children/componentInstance ──
        // 避免修改 getVNodeTree() 缓存的原始 VNode
        $expanded = clone $node;
        $expanded->componentInstance = $instance;
        $expanded->children = $childRoot;

        $this->patchComponentTree($expanded->children, $instance, null);

        return $expanded;
    }

    private function patchComponentTree(
        VNode $newNode,
        ReactiveComponent $owner,
        ?VNode $oldNode = null
    ): ?VNode {
        if ($newNode->isComponent()) {
            // B2 不可变性：matchComponentNode 返回克隆后的节点
            return $this->matchComponentNode($newNode, $owner, $oldNode);
        }

        $oldChildren = $oldNode !== null
            ? VNode::childrenToArray($oldNode->children)
            : [];
        $newChildren = VNode::childrenToArray($newNode->children);

        $count = (int)min(count($oldChildren), count($newChildren));
        for ($i = 0; $i < $count; $i++) {
            $replacement = $this->patchComponentTree(
                $newChildren[$i],
                $owner,
                $oldChildren[$i]
            );
            if ($replacement !== null && $replacement !== $newChildren[$i] && is_array($newNode->children)) {
                $this->replaceVNodeInArray($newNode->children, $newChildren[$i], $replacement);
            }
        }

        for ($i = $count; $i < count($newChildren); $i++) {
            $replacement = $this->patchComponentTree($newChildren[$i], $owner, null);
            if ($replacement !== null && $replacement !== $newChildren[$i] && is_array($newNode->children)) {
                $this->replaceVNodeInArray($newNode->children, $newChildren[$i], $replacement);
            }
        }

        return null;
    }

    /**
     * Replace a VNode in an array by identity comparison.
     */
    private function replaceVNodeInArray(array &$arr, VNode $original, VNode $replacement): void
    {
        foreach ($arr as $k => $v) {
            if ($v === $original) {
                $arr[$k] = $replacement;
                return;
            }
        }
    }

    private function matchComponentNode(
        VNode $newNode,
        ReactiveComponent $owner,
        ?VNode $oldNode = null
    ): VNode {
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

        if (Config::get('diag_enabled', false)) {
            $path = $instance !== null ? 'REUSE' : 'EXPAND';
            $newInstState = $newNode->componentInstance !== null ? 'SET' : 'NULL';
            $oldInstState = ($oldNode !== null && $oldNode->componentInstance !== null) ? 'SET' : 'NULL';
            error_log("[DIAG] matchComponentNode: class={$newNode->componentClass}"
                . " path={$path} newInst={$newInstState} oldInst={$oldInstState}"
                . " sameObj=" . ($newNode === $oldNode ? 'YES' : 'NO'));
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

            // ── 方案 A：VNode 树身份修复 ──
            // 当 oldNode === newNode（根 VNode 树缓存命中，同一对象）时，
            // 必须在替换 children 之前保存旧 children 引用，
            // 否则 $oldNode->children 会被 $newNode->children = getVNodeTree() 覆盖，
            // 导致递归 patchComponentTree 的 oldNode->children 和 newNode->children 指向同一个新树，
            // 所有子 #component 节点的 $oldNode === $newNode → componentInstance 全为 null → 全部走 EXPAND。
            // 保存旧 children 可以保证子组件的匹配走正常的 REUSE 路径。
            $oldChildren = ($oldNode !== null) ? $oldNode->children : null;

            // ── B2 不可变性：克隆 VNode 再设置 children/componentInstance ──
            $matched = clone $newNode;
            $matched->componentInstance = $instance;
            $matched->children = $instance->getVNodeTree();

            $this->registerComponent($instance->getId(), $instance);

            $this->patchComponentTree(
                $matched->children,
                $instance,
                $oldChildren
            );

            return $matched;
        } else {
            if ($oldNode !== null && $oldNode->componentInstance !== null) {
                $oldNode->componentInstance->unmount();
            }
            return $this->expandComponentNode($newNode, $owner);
        }

        // 当 oldNode !== null 但未命中 reuse 路径时返回 original
        return $newNode;
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
        $this->frameCounter++;
        $this->rebuildVNodeTree();

        // getRootRenderNodes() = 顶层 #root 所有旧子节点，作为 candidates 传递给 #root handler
        $oldRootChildren = $this->renderTreeManager->getRootRenderNodes();
        $candidates = !empty($oldRootChildren) ? $oldRootChildren : null;

        // VNode → RenderNode 转换 + bind 值同步（type+key 匹配复用）
        // 传递 'app' 作为根组件的 groupId（VNode.groupId 不再写入，依赖参数传播）
        $rootRenderNode = $this->renderTreeManager->updateFromVNode(
            $this->activeVNodeTree,
            null,
            $this->rootComponent,
            $this->componentByGroupId,
            $candidates,
            'app'
        );
        if ($rootRenderNode === null) return;

        // LayoutResolver 处理 RenderNode（利用 layoutDirty 增量）
        $this->layoutResolver->resolve($rootRenderNode);

        // ── 调试快照：仅在显式请求时输出 ──
        if (Config::get('snapshot_enabled', false) && $this->snapshotRequested) {
            $this->snapshotRequested = false;
            $snapshot = $this->renderTreeManager->dumpRenderTree(
                $rootRenderNode,
                $this->frameCounter,
                $this->eventRingBuffer
            );
            $this->outputSnapshot($snapshot);
        }

        // VNodeRenderer 处理 RenderNode（利用 paintDirty 增量）
        $this->renderer->render($rootRenderNode);
    }

    /**
     * 输出调试快照到 stdout 和 {APP_DIR}/debug/_snapshot.log。
     * 文件超过 maxSize 时自动轮转（保留 maxBackups 个备份）。
     */
    private function outputSnapshot(string $snapshot): void
    {
        $appDir = Config::getAppDir();
        if ($appDir === '') return;

        $debugDir = $appDir . '/debug';
        @mkdir($debugDir, 0777, true);
        $file = $debugDir . '/_snapshot.log';

        // ── 文件轮转：超过阈值时 shift 备份 ──
        $maxSize = Config::get('snapshot_max_size_mb', 5) * 1024 * 1024;
        if (file_exists($file) && filesize($file) > $maxSize) {
            $maxBackups = Config::get('snapshot_max_backups', 5);
            $oldest = $debugDir . "/_snapshot.{$maxBackups}.log";
            if (file_exists($oldest)) @unlink($oldest);
            for ($i = $maxBackups - 1; $i >= 1; $i--) {
                $from = $debugDir . "/_snapshot.{$i}.log";
                if (file_exists($from)) {
                    @rename($from, $debugDir . "/_snapshot." . ($i + 1) . ".log");
                }
            }
            @rename($file, $debugDir . '/_snapshot.1.log');
        }

        file_put_contents($file, $snapshot . "\n\n", FILE_APPEND);
        echo $snapshot . "\n";
    }

    private function doFirstRender(): void
    {
        $this->render();

        if (Config::get('diag_enabled', false)) {
            $rootRN = $this->renderTreeManager->getRootRenderNode();
            $gridRN = $rootRN !== null ? $this->findGridRenderNode($rootRN) : null;
            error_log('[DIAG] doFirstRender: Frame1 done'
                . ' gridRN=' . ($gridRN !== null ? 'FOUND' : 'NF')
                . ' gridChildren=' . ($gridRN !== null ? count($gridRN->children) : -1));
        }

        $this->scheduler->flushMicrotasks();

        if (Config::get('diag_enabled', false)) {
            error_log('[DIAG] doFirstRender: after flush renderRequested=' . ($this->renderRequested ? 'yes' : 'no'));
        }

        if ($this->renderRequested) {
            $this->renderRequested = false;
            $this->render();

            if (Config::get('diag_enabled', false)) {
                $rootRN = $this->renderTreeManager->getRootRenderNode();
                $gridRN = $rootRN !== null ? $this->findGridRenderNode($rootRN) : null;
                error_log('[DIAG] doFirstRender: Frame2 done'
                    . ' gridRN=' . ($gridRN !== null ? 'FOUND' : 'NF')
                    . ' gridChildren=' . ($gridRN !== null ? count($gridRN->children) : -1));
            }
        }
    }

    private function findGridRenderNode(RenderNode $node): ?RenderNode
    {
        $style = $node->style;
        if (($style['display'] ?? '') === 'grid') {
            return $node;
        }
        foreach ($node->children as $child) {
            $found = $this->findGridRenderNode($child);
            if ($found !== null) return $found;
        }
        return null;
    }

    // ── 事件循环 ──────────────────────────────

    public function run(): void
    {
        $this->doFirstRender();

        // ── 初始挂载快照（组件树已完全展开稳定）──
        if (Config::get('snapshot_enabled', false)) {
            $rootNode = $this->renderTreeManager->getRootRenderNode();
            if ($rootNode !== null) {
                $snapshot = $this->renderTreeManager->dumpRenderTree(
                    $rootNode, $this->frameCounter, $this->eventRingBuffer
                );
                $this->outputSnapshot($snapshot);
            }
        }

        while ($this->running) {
            $rawEvents = $this->platform->pollEvents();
            foreach ($rawEvents as $ev) {
                if ($ev instanceof MouseEvent) {
                    $this->handleMouseEvent($ev);
                } elseif ($ev instanceof KeyboardEvent) {
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
        // 释放所有图片资源
        ImageManager::freeAll();

        $this->platform->shutdown();
    }

    // ── 键盘事件辅助 ──────────────────────────

    /**
     * 记录平台事件到环形缓冲区（调试快照用）。
     */
    private function recordEvent(string $eventType, int $x = -1, int $y = -1, string $detail = ''): void
    {
        if (!Config::get('snapshot_enabled', false)) return;

        $ev = [
            'frame' => $this->frameCounter,
            'type'  => $eventType,
            'time'  => time(),
            'x'     => $x,
            'y'     => $y,
            'detail' => $detail,
        ];
        $this->eventRingBuffer[$this->eventBufferIndex] = $ev;
        $this->eventBufferIndex = ($this->eventBufferIndex + 1) % $this->eventBufferSize;
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
