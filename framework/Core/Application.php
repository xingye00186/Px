<?php

namespace Px\Core;

use native_types;

use Px\Platform\Platform;
use Px\Platform\PlatformEvent;
use Px\Platform\MouseEvent;
use Px\Platform\KeyboardEvent;
use Px\Platform\WindowEvent;
use Px\Platform\PlatformFactory;
use Px\Rendering\Backend\ResilientRenderContext;
use Px\Rendering\Backend\RuntimeBackendSelector;
use Px\Rendering\TextBackend\TextBackendRegistry;
use Px\Rendering\TextBackend\ResilientTextBackendProxy;
use Px\Rendering\VNode;
use Px\Rendering\RenderNode;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\LayoutResolver;
use Px\Rendering\LayoutOrchestrator;
use Px\Rendering\StyleRecalcPass;
use Px\Rendering\InteractionState;
use Px\Rendering\CssMappings;
use Px\Rendering\ImageManager;
use Px\Rendering\RenderTreeManager;
use Px\Interfaces\ReactiveComponentInterface;
use Px\ReactiveComponent;
use Px\Styling\Theme\ThemeData;
use Px\Styling\Provider\ThemeProvider;
use Px\Styling\Adapter\PlatformAdapter;
use Px\Core\Config;
use Px\Rendering\RenderNodeSerializer;

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
    private ?LayoutOrchestrator $layoutOrchestrator = null;
    private bool $useOrchestrator = false;
    private RenderTreeManager $renderTreeManager;

    private ?ReactiveComponentInterface $rootComponent = null;
    private ?VNode $activeVNodeTree = null;
    private bool $renderRequested = false;
    private bool $running = true;

    /** 当前鼠标光标类型：'' 默认, 'pointer' 手型 */
    private string $currentCursor = '';

    /** 当前 hover 的 RenderNode（用于 :hover 样式切换） */
    private ?RenderNode $hoveredNode = null;

    // ── InteractionState 映射 ───────────────────────
    /** @var array<string, InteractionState> */
    private array $interactionStates = [];

    private function getInteractionState(RenderNode $node): InteractionState
    {
        $key = spl_object_id($node);
        if (!isset($this->interactionStates[$key])) {
            $this->interactionStates[$key] = new InteractionState();
        }
        return $this->interactionStates[$key];
    }

    public function getInteractionStateForNode(RenderNode $node): InteractionState
    {
        return $this->getInteractionState($node);
    }

    /** @var array<string, ReactiveComponentInterface> VNode.groupId → Component instance */
    private array $componentByGroupId = [];

    private int $nextComponentId = 1;
    private bool $isRendering = false;

    private ScrollManager $scrollManager;

    private static ?self $instance = null;

    /** headless 模式标志（AOT 下 defined() 编译期失效，用静态属性替代） */
    public static bool $HEADLESS = false;

    /** 帧计数器，用于诊断输出 */
    private int $debugFrameNumber = 0;

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

    /**
     * 处理 CLI 布局导出参数。
     *
     * 支持的参数：
     *   --dump-layout                    → 单帧导出 engine_layout.json
     *   --dump-layout-after-frames=N     → N 帧后导出 engine_layout_after_Nframes.json
     *
     * @param self $app Application 实例
     * @param string $appDir 应用目录（输出 JSON 到此目录）
     * @param array $argv CLI 参数数组
     * @return bool 是否匹配并处理了 CLI 参数（true=已处理，调用方应 return 0）
     */
    public static function handleDumpArgs(self $app, string $appDir, array $argv): bool
    {
        // Extract --frame=N (default 1), --dump-layout-to=PATH, --case=CASE_NAME
        $frame = 1;
        $dumpTo = '';
        $caseName = '';
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--frame=')) {
                $frame = max(1, (int)substr($arg, strlen('--frame=')));
            } elseif (str_starts_with($arg, '--dump-layout-to=')) {
                $dumpTo = substr($arg, strlen('--dump-layout-to='));
            } elseif (str_starts_with($arg, '--case=')) {
                $caseName = substr($arg, strlen('--case='));
            }
        }

        // Auto-default: --case=xxx → test_case/{xxx}/ref/engine_layout.json
        if ($dumpTo === '') {
            if ($caseName !== '') {
                $dumpTo = $appDir . '/test_case/' . $caseName . '/ref/engine_layout.json';
            } else {
                $caseDirs = glob($appDir . '/test_case/case-*', GLOB_ONLYDIR);
                if (!empty($caseDirs)) {
                    $firstCase = basename($caseDirs[0]);
                    $dumpTo = $appDir . '/test_case/' . $firstCase . '/ref/engine_layout.json';
                }
            }
        }

        // --dump-layout: 导出布局（受 --frame=N 控制渲染帧数）
        if (in_array('--dump-layout', $argv)) {
            // 当 frame>1 时使用 _after_{N}frames 后缀
            if ($frame > 1) {
                $ext = '.json';
                $base = substr($dumpTo, 0, -strlen($ext));
                $path = $base . "_after_{$frame}frames.json";
            } else {
                $path = $dumpTo;
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            for ($i = 0; $i < $frame; $i++) {
                $app->render();
                if ($i < $frame - 1) {
                    $app->scheduler->flushMicrotasks();
                }
            }
            $app->dumpLayoutToFile($path, true);
            return true;
        }

        return false;
    }

    public function __construct(
        Platform $platform,
        Scheduler $scheduler,
        ?LayoutResolver $layoutResolver = null,
        ?RenderTreeManager $renderTreeManager = null,
        ?LayoutOrchestrator $layoutOrchestrator = null,
    ) {
        $this->platform  = $platform;
        $this->scheduler = $scheduler;
        $this->layoutOrchestrator = $layoutOrchestrator ?? new LayoutOrchestrator();
        $this->useOrchestrator = true;
        $this->layoutResolver = $layoutResolver ?? new LayoutResolver();
        $this->renderTreeManager = $renderTreeManager ?? new RenderTreeManager();
        $this->scrollManager = new ScrollManager(
            $this->requestRender(...),
            function () { $this->directRender(); },
            $this->resolveComponentByGroupId(...),
            $this->renderTreeManager->findScrollContainerAt(...)
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

        // ── 鼠标滚轮：驱动滚动容器 ────────────
        if ($event->getAction() === 'wheel') {
            $this->scrollManager->handleScrollWheel($event);
            return;
        }

        // ── 鼠标拖动：滚动条拖拽 + 光标 hover + :hover 样式 ──
        if ($event->getAction() === 'move') {
            // 先处理滚动条拖拽
            $this->scrollManager->handleScrollbarDrag($event->getX(), $event->getY());

            // 然后检查光标状态（仅当不在拖拽状态时）
            if (!$this->scrollManager->isDragging()) {
                $hoverNode = $this->renderTreeManager->hitTest($event->getX(), $event->getY());
                $newCursor = '';
                if ($hoverNode !== null) {
                    $cursorStyle = $hoverNode->computedStyle?->cursor?->value ?? '';
                    if ($cursorStyle !== '') {
                        $newCursor = $cursorStyle;
                    }
                }
                if ($newCursor !== $this->currentCursor) {
                    $this->currentCursor = $newCursor;
                    $this->platform->setCursor($newCursor);
                }

                // ── :hover 伪类样式追踪 ──
                // 当 hover 节点变化时，更新新旧节点的 hovered 标志并触发渲染
                if ($hoverNode !== $this->hoveredNode) {
                    // 清除旧节点的 hover 状态（通过 InteractionState，不直接写 RenderNode）
                    if ($this->hoveredNode !== null) {
                        $this->getInteractionState($this->hoveredNode)->hovered = false;
                    }
                    // 设置新节点的 hover 状态
                    if ($hoverNode !== null) {
                        $this->getInteractionState($hoverNode)->hovered = true;
                    }
                    $this->hoveredNode = $hoverNode;
                    // 请求渲染以应用 :hover 样式变化
                    $this->requestRender();
                }
            } else {
                // 拖拽中：清除 hover 状态
                if ($this->hoveredNode !== null) {
                    $this->getInteractionState($this->hoveredNode)->hovered = false;
                    $this->hoveredNode = null;
                    // ⚠️ 拖拽中不触发 requestRender()，避免与 directRender() 竞争
                    // directRender() 已经处理了拖拽过程中的视觉更新
                }
            }
            return;
        }

        // ── 鼠标释放：结束拖拽，持久化滚动位置 ──
        if ($event->getAction() === 'up') {
            $this->scrollManager->handleMouseUp();
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
                }
            }
        }
    }

    private function handleKeyboardEvent(KeyboardEvent $event): void
    {
        if ($this->activeVNodeTree === null) {
            return;
        }

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
            }
        } elseif ($action === 'up') {
            $handler = $input->props['@keyup'] ?? null;
            if ($handler !== null) {
                $target->dispatchKey($handler, $action, $event->getKeyCode(), $event->getChar());
            }
        } elseif ($action === 'char') {
            $handler = $input->props['@enter'] ?? null;
            if ($handler !== null && $event->getKeyCode() === 13) {
                $target->dispatchKey($handler, $action, $event->getKeyCode(), $event->getChar());
            }
        }
    }

    // ── 组件注册表 ─────────────────────────────

    /**
     * 注册组件实例，按 groupId 索引。
     */
    public function registerComponent(string $groupId, ReactiveComponentInterface $component): void
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
        $w = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : Config::get('window_width', 1280);
        $h = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : Config::get('window_height', 720);

        // Stage 1: 让 platform 创建窗口 + 默认 RenderContext
        $title = defined('WINDOW_TITLE') ? WINDOW_TITLE : Config::get('debug_window_title', 'Px');
        $defaultCtx = $this->platform->init($title, $w, $h);

        // 无 C++ 绑定（PHP-only 测试）：使用 defaultCtx，跳过后端选择
        if (!function_exists('vue_begin_paint')) {
            $this->renderer = new VNodeRenderer($this->rootComponent, $defaultCtx);
            error_log('[DIAG] initRenderer: test mode, using defaultCtx');
            return;
        }

        unset($defaultCtx);  // 显式释放默认 RC，让 RuntimeBackendSelector 创建最优后端

        // Stage 2: 用 RuntimeBackendSelector 探测 + 选择最优后端
        // headless 模式同样走后端选择（hwnd=0 时 skia-cpu 使用内存离屏 surface）
        $hwnd = $this->platform->getHwnd();
        $selector  = new RuntimeBackendSelector();
        try {
            $backend = $selector->select($hwnd, $w, $h);
        } catch (\Throwable $e) {
            error_log('[Application] initRenderer: backend selection threw: ' . $e->getMessage());
            $this->renderer = new VNodeRenderer($this->rootComponent, new GdiRenderContext($hwnd));
            return;
        }
        $this->selectedBackendName = $backend->getName();
        error_log('[DIAG] initRenderer: selected backend=' . $this->selectedBackendName);

        // Stage 3: 包一层 ResilientRenderContext 支持渲染后端降级
        $baseCtx = new ResilientRenderContext($selector, $backend->getContext(), $hwnd, $w, $h);

        // Stage 4: 包一层 ResilientTextBackendProxy 支持文本引擎降级
        $renderCtx = new ResilientTextBackendProxy($baseCtx);

        $this->renderer = new VNodeRenderer($this->rootComponent, $renderCtx);
    }

    public function mount(ReactiveComponentInterface $root, string $appDir = ''): self
    {
        $this->rootComponent = $root;
        $this->rootComponent->setScheduler($this->scheduler);
        $this->rootComponent->setRenderCallback($this->handleRenderRequest(...));
        $this->registerComponent('app', $root);

        // 初始化图片管理器（必须早于任何图片加载）
        if ($appDir !== '') {
            ImageManager::setAppRoot($appDir);
        }

        // 初始化调试配置（从 project.yml 中读取 Px_debug_* 前缀项）
        if ($appDir !== '') {
            Config::init($appDir);
        }

        // 初始化文本后端（渲染层管理：TextBackendRegistry）
        TextBackendRegistry::initialize();

        $this->initRenderer();

        // 初始化主题系统
        $baseTheme = ThemeData::light();
        $platformStyling = PlatformAdapter::create(APP_PLATFORM, $baseTheme);
        $finalTheme = $platformStyling->apply($baseTheme);
        ThemeProvider::inject($finalTheme);

        // 编译时 class→style 合并已完成，无需运行时注册
        // （getClassStyles() 已从 gen 文件中移除）

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

        if (Config::get('debug_diag_enabled', false)) {
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
                // 1. 销毁 RenderNode 树（包含从父级移除、映射清除、动画取消）
                $oldRootRN = $instance->getRootRenderNode();
                if ($oldRootRN !== null) {
                    $this->renderTreeManager->destroyRenderNodeTree($oldRootRN);
                }
                // 2. 卸载组件（事件监听、生命周期）
                $instance->unmount();
                // 3. 从 Application 组件注册表中移除
                $this->unregisterComponent($id);
            }
        }

        $this->isRendering = false;
    }

    // ── 展开组件 ──────────────────────────────

    private function expandComponentNode(VNode $node, ReactiveComponentInterface $owner): VNode
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

        if (Config::get('debug_diag_enabled', false)) {
            error_log("[DIAG] expandComponentNode: class={$className} id={$instanceId}"
                . " owner=" . get_class($owner)
                . " hasPropVals=" . ($node->componentPropValues !== null ? 'YES' : 'NO'));
        }

        // ── VideoGridComponent 诊断：检查 mount 后的数据状态 ──
        if (Config::get('debug_diag_enabled', false) && $className === 'VideoGridComponent') {
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

        // 编译时 class→style 合并已完成，此处不再需要 getClassStyles()

        // 必须使用 getVNodeTree() 而非 render()，确保结果缓存到 vnodeCache。
        // 否则后续 updateFromVNode() 调用 $instance->getVNodeTree() 时会再次执行 render()，
        // 返回一个新 VNode 树，style 透传由 RenderTreeManager 处理。
        $childRoot = $instance->getVNodeTree();

        // ── B2 不可变性：克隆 VNode 再设置 children/componentInstance ──
        // 避免修改 getVNodeTree() 缓存的原始 VNode
        $expanded = clone $node;
        $expanded->componentInstance = $instance;
        $expanded->children = $childRoot;

        // Vue 3 标准：style 透传由 RenderTreeManager::updateFromVNode 处理
        // （父组件 props['style'] 全部合并到子组件根元素 RenderNode）

        $this->patchComponentTree($expanded->children, $instance, null);

        return $expanded;
    }

    private function patchComponentTree(
        VNode $newNode,
        ReactiveComponent $owner,
        ?VNode $oldNode = null
    ): ?VNode {
        // 将当前节点 groupId 设为 owner 的组件 ID，用于组件实例路由
        $newNode->groupId = $owner->getId();

        if ($newNode->isComponent()) {
            // B2 不可变性：matchComponentNode 返回克隆后的节点
            return $this->matchComponentNode($newNode, $owner, $oldNode);
        }

        $oldChildren = $oldNode !== null
            ? VNode::childrenToArray($oldNode->children)
            : [];

        // 使用引用遍历直接修改原 children 数组，避免临时数组 + 线性查找
        if (is_array($newNode->children)) {
            $oldIdx = 0;
            foreach ($newNode->children as &$child) {
                if (!($child instanceof VNode)) {
                    continue;
                }
                $oldMatch = $oldIdx < count($oldChildren) ? $oldChildren[$oldIdx] : null;
                $replacement = $this->patchComponentTree($child, $owner, $oldMatch);
                if ($replacement !== null && $replacement !== $child) {
                    $child = $replacement;
                }
                $oldIdx++;
            }
            unset($child);
        } elseif ($newNode->children instanceof VNode) {
            // Single VNode child（非数组情况）
            $oldMatch = !empty($oldChildren) ? $oldChildren[0] : null;
            $replacement = $this->patchComponentTree($newNode->children, $owner, $oldMatch);
            if ($replacement !== null && $replacement !== $newNode->children) {
                $newNode->children = $replacement;
            }
        }

        return null;
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

        if (Config::get('debug_diag_enabled', false)) {
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

            // Vue 3 标准：style 透传由 RenderTreeManager::updateFromVNode 处理

            $this->registerComponent($instance->getId(), $instance);

            $this->patchComponentTree(
                $matched->children,
                $instance,
                $oldChildren
            );

            return $matched;
        } else {
            // ── 不重用：旧组件必须彻底销毁 ──
            if ($oldNode !== null && $oldNode->componentInstance !== null) {
                $oldInst = $oldNode->componentInstance;
                $oldRootRN = $oldInst->getRootRenderNode();

                // 1. 彻底销毁 RenderNode 树（包含从父级移除、映射清除、动画取消）
                if ($oldRootRN !== null) {
                    $this->renderTreeManager->destroyRenderNodeTree($oldRootRN);
                }

                // 2. 卸载组件（事件监听、生命周期）
                $oldInst->unmount();

                // 3. 从 Application 组件注册表中移除
                $this->unregisterComponent($oldInst->getId());

                // 4. 清除旧节点上的 componentInstance 引用（帮助 GC）
                $oldNode->componentInstance = null;
            }

            // 5. 展开新组件
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
        if ($root === null) {
            if (Config::get('debug_diag_enabled', false)) {
                error_log("[DIAG] directRender: root is null - SKIP");
            }
            return;
        }

        if (Config::get('debug_diag_enabled', false)) {
            $this->logScrollContainerStates('[DIAG] directRender BEFORE');
            error_log("[DIAG] directRender: type={$root->type} children=" . count($root->children));
        }

        if ($this->useOrchestrator && $this->layoutOrchestrator !== null) {
            $this->layoutOrchestrator->layout($root);
        } else {
            $this->layoutResolver->resolve($root);
        }

        if (Config::get('debug_diag_enabled', false)) {
            $this->logScrollContainerStates('[DIAG] directRender AFTER');
        }

        $this->renderer->render($root);
    }

    /**
     * 导出布局快照到 JSON 文件。
     * 序列化 RenderNode 树的位置/尺寸/样式信息，用于分治测试和对比验证。
     */
    public function dumpLayoutToFile(string $path, bool $caseContentOnly = false): void
    {
        $root = $this->renderTreeManager->getRootRenderNode();
        if ($root === null) {
            file_put_contents($path, '[]');
            return;
        }
        $serializer = new RenderNodeSerializer();
        $exportNode = $root;
        if ($caseContentOnly) {
            // 导出仅为测试内容子树：找到 data-px-anchor='tl' 的父节点
            $tlParent = $this->findTestContentParent($root);
            if ($tlParent !== null) {
                // Recalculate content-based height (was stretched by flex)
                $cs2 = $tlParent->computedStyle;
                if ($cs2 !== null && $cs2->height->toPx() <= 0) {
                    $maxB = 0;
                    foreach ($tlParent->children as $ch2) {
                        $p2 = $ch2->computedStyle?->position?->value;
                        if ($p2 === "absolute" || $p2 === "fixed") continue;
                        $b2 = $ch2->y + $ch2->visualH;
                        if ($b2 > $maxB) $maxB = $b2;
                    }
                    $bt2 = $cs2->borderTopWidth ?? 0;
                    $pb2 = $cs2->padding?->top->toPx() ?? 0;
                    $ct2 = $bt2 + $pb2;
                    if ($maxB > $ct2) {
                        $ch2h = $maxB - $ct2;
                        if ($cs2->boxSizing?->value === "border-box") {
                            $ch2h += $cs2->padding?->bottom->toPx() + $cs2->borderBottomWidth;
                        }
                        $tlParent->h = $ch2h;
                        $tlParent->visualH = $cs2->visualHeight($tlParent->h);
                    }
                }
                $exportNode = $tlParent;
                $exportNode = $tlParent;
            }
        }
        // AOT 编译器不能正确处理带参数的 toArray() 调用
        // 绕过：使用 serializeNode 替代（不同方法签名避免 AOT bug）
        $data = $serializer->serializeNode($exportNode);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 在 RenderNode 树中查找测试内容根节点（即 data-px-anchor=tl 的父节点）。
     */
    private function findTestContentParent(RenderNode $node): ?RenderNode
    {
        // 递归搜索所有后代，找到包含 data-px-anchor='tl' 的任意子节点
        // 然后返回该子节点的父节点
        $tlNode = $this->findNodeByDataset($node, 'pxAnchor', 'tl');
        if ($tlNode !== null && $tlNode->parent !== null) {
            return $tlNode->parent;
        }
        return null;
    }

    /**
     * 递归搜索 RenderNode 树，查找 dataset 中指定键值对的节点。
     */
    private function findNodeByDataset(RenderNode $node, string $key, string $value): ?RenderNode
    {
        $ds = $node->dataset ?? [];
        if (isset($ds[$key]) && (string)$ds[$key] === $value) {
            return $node;
        }
        foreach ($node->children as $child) {
            $result = $this->findNodeByDataset($child, $key, $value);
            if ($result !== null) return $result;
        }
        return null;
    }

    /**
     * 保存当前渲染结果为 PNG 截图（headless 模式／调试用）。
     * 底层调用 sk_save_screenshot() 从窗口 DC 或 Skia 离屏缓冲区直接保存。
     */
    public function saveScreenshot(string $path): void
    {
        if ($this->renderer !== null && function_exists('sk_save_screenshot')) {
            $this->renderer->getRenderContext()->saveScreenshot($path);
        }
    }

    /**
     * 完整渲染流程：
     *   1. 重建 VNode 树（含组件展开）
     *   2. RenderTreeManager::updateFromVNode 转换并同步 bind 值
     *   3. LayoutResolver::resolve 计算坐标
     *   4. VNodeRenderer::render 生成 GDI 调用
     */
    public function render(): void
    {
        $this->debugFrameNumber++;
        $frame = $this->debugFrameNumber;

        $this->rebuildVNodeTree();

        // Phase 0.5: 独立 StyleRecalc 通行证（将样式解析从 RenderTreeManager 抽出）
        if ($this->activeVNodeTree !== null) {
            $styleRecalc = new StyleRecalcPass();
            $styleRecalc->recalc($this->activeVNodeTree);
        }

        // getRootRenderNodes() = 顶层 #root 所有旧子节点，作为 candidates 传递给 #root handler
        $oldRootChildren = $this->renderTreeManager->getRootRenderNodes();
        $candidates = !empty($oldRootChildren) ? $oldRootChildren : null;

        // 保存旧根 RenderNode 供 scrollTop 恢复使用
        $oldRootRenderNode = $this->renderTreeManager->getRootRenderNode();

        // VNode → RenderNode 转换 + bind 值同步（type+key 匹配复用）
        // 传递 'app' 作为根组件的 groupId（VNode.groupId 不再写入，依赖参数传播）
        $rootRenderNode = $this->renderTreeManager->updateFromVNode(
            $this->activeVNodeTree,
            null,
            $this->rootComponent,
            $this->componentByGroupId,
            $candidates,
            'app',
            '',
            []
        );
        if ($rootRenderNode === null) {
            return;
        }

        // 恢复 scrollTop：根组件的直属子树（如 App.vue 中的 .case-list）
        // 不经过 #component handler，因此不受 copyScrollTopFromOld 覆盖。
        // 此处对根 RenderNode 整体执行一次 scrollTop 恢复。
        if ($oldRootRenderNode !== null) {
            $this->renderTreeManager->copyScrollTopFromOld($rootRenderNode, $oldRootRenderNode);
        }

        if (Config::get('debug_diag_enabled', false)) {
            error_log('[DIAG] render() frame=' . $frame . ' BEFORE resolve');
            $this->logScrollContainerStates('[DIAG] render BEFORE');
        }

        // LayoutResolver/LayoutOrchestrator 处理 RenderNode
        if ($this->useOrchestrator && $this->layoutOrchestrator !== null) {
            $this->layoutOrchestrator->layout($rootRenderNode);
        } else {
            $this->layoutResolver->resolve($rootRenderNode);
        }

        if (Config::get('debug_diag_enabled', false)) {
            $this->logScrollContainerStates('[DIAG] render AFTER');
        }

        // VNodeRenderer 处理 RenderNode（利用 paintDirty 增量）
        $this->renderer->render($rootRenderNode);
    }

    /**
     * 输出 RenderNode 树的快照（仅 diag_enabled 时调用）
     */
    private function debugDumpRenderNode(RenderNode $node, string $prefix, int $frame): void
    {
        // #root 节点不产生元素，但需要展示子节点
        if ($node->type === '#root') {
            foreach ($node->children as $child) {
                $this->debugDumpRenderNode($child, $prefix, $frame);
            }
            return;
        }

        $scrollInfo = '';
        if ($node->isScrollContainer) {
            $scrollInfo = " scroll[st={$node->scrollTop} ch={$node->contentHeight}]";
        }
        $contentInfo = '';
        if ($node->content !== null && $node->content !== '') {
            $c = (string)$node->content;
            if (strlen($c) > 30) $c = substr($c, 0, 30) . '...';
            $contentInfo = " text='{$c}'";
        }
        error_log("[DIAG] Frame #{$prefix}{$node->type}({$node->x},{$node->y} {$node->w}x{$node->h}) layer={$node->layer} gid={$node->groupId}{$scrollInfo}{$contentInfo}");

        foreach ($node->children as $child) {
            $this->debugDumpRenderNode($child, $prefix . "  ", $frame);
        }
    }

    private function doFirstRender(): void
    {
        error_log('[DIAG] doFirstRender: Frame 1 start');
        $this->render();
        error_log('[DIAG] doFirstRender: Frame 1 done');

        if (Config::get('debug_diag_enabled', false)) {
            $rootRN = $this->renderTreeManager->getRootRenderNode();
            error_log('[DIAG] doFirstRender: Frame1 renderRequested=' . ($this->renderRequested ? 'yes' : 'no'));
        }

        $this->scheduler->flushMicrotasks();

        if (Config::get('debug_diag_enabled', false)) {
            error_log('[DIAG] doFirstRender: after flush renderRequested=' . ($this->renderRequested ? 'yes' : 'no'));
        }

        if ($this->renderRequested) {
            $this->renderRequested = false;
            error_log('[DIAG] doFirstRender: Frame 2 start');
            $this->render();
            error_log('[DIAG] doFirstRender: Frame 2 done');
        }
    }

    // ── 事件循环 ──────────────────────────────

    public function run(): void
    {
        $this->doFirstRender();

        while ($this->running) {
            try {
                $rawEvents = $this->platform->pollEvents();
                foreach ($rawEvents as $ev) {
                    if ($ev instanceof MouseEvent) {
                        $this->handleMouseEvent($ev);
                    } elseif ($ev instanceof KeyboardEvent) {
                        $this->handleKeyboardEvent($ev);
                    } elseif ($ev instanceof WindowEvent && $ev->action === 'paint') {
                        // WM_PAINT：窗口需要重绘，触发渲染
                        error_log('[DIAG] WM_PAINT event received, triggering render');
                        $this->requestRender();
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
            } catch (\Throwable $e) {
                error_log('[Application] Uncaught exception in event loop: ' . $e->getMessage());
            }
        }
        // 释放所有图片资源
        ImageManager::freeAll();

        $this->platform->shutdown();
    }

    // ── 键盘事件辅助 ──────────────────────────

    /**
     * 查找 VNode 树中第一个有键盘处理器的 input 元素。
     */
    /**
     * 诊断：输出所有滚动容器的 scrollTop/contentHeight/h 状态。
     */
    private function logScrollContainerStates(string $prefix): void
    {
        $root = $this->renderTreeManager->getRootRenderNode();
        if ($root === null) return;
        $this->traverseLogScrollContainers($root, $prefix, 0);
    }

    private function traverseLogScrollContainers(RenderNode $node, string $prefix, int $depth): void
    {
        if ($node->isScrollContainer) {
            $indent = str_repeat('  ', $depth);
            error_log("{$prefix} {$indent}scrollContainer type={$node->type} scrollTop={$node->scrollTop} contentH={$node->contentHeight} h={$node->h} layoutDirty=" . ($node->layoutDirty ? '1' : '0'));
        }
        foreach ($node->children as $child) {
            $this->traverseLogScrollContainers($child, $prefix, $depth + 1);
        }
    }

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
