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
use Px\Rendering\CssMappings;
use Px\Rendering\ImageManager;
use Px\Rendering\RenderTreeManager;
use Px\Interfaces\ReactiveComponentInterface;
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

    private ?ReactiveComponentInterface $rootComponent = null;
    private ?VNode $activeVNodeTree = null;
    private bool $renderRequested = false;
    private bool $running = true;

    /** 当前鼠标光标类型：'' 默认, 'pointer' 手型 */
    private string $currentCursor = '';

    /** 当前 hover 的 RenderNode（用于 :hover 样式切换） */
    private ?RenderNode $hoveredNode = null;

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
            $app->dumpLayoutToFile($path);
            return true;
        }

        return false;
    }

    public function __construct(
        Platform $platform,
        Scheduler $scheduler,
        ?LayoutResolver $layoutResolver = null,
        ?RenderTreeManager $renderTreeManager = null
    ) {
        $this->platform  = $platform;
        $this->scheduler = $scheduler;
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
                    $cursorStyle = $hoverNode->style['cursor'] ?? '';
                    if ($cursorStyle === 'pointer' || $cursorStyle === 'hand') {
                        $newCursor = 'pointer';
                    }
                }
                if ($newCursor !== $this->currentCursor) {
                    $this->currentCursor = $newCursor;
                    $this->platform->setCursor($newCursor);
                }

                // ── :hover 伪类样式追踪 ──
                // 当 hover 节点变化时，更新新旧节点的 hovered 标志并触发渲染
                if ($hoverNode !== $this->hoveredNode) {
                    // 清除旧节点的 hover 状态
                    if ($this->hoveredNode !== null) {
                        $this->hoveredNode->hovered = false;
                    }
                    // 设置新节点的 hover 状态
                    if ($hoverNode !== null) {
                        $hoverNode->hovered = true;
                    }
                    $this->hoveredNode = $hoverNode;
                    // 请求渲染以应用 :hover 样式变化
                    $this->requestRender();
                }
            } else {
                // 拖拽中：清除 hover 状态
                if ($this->hoveredNode !== null) {
                    $this->hoveredNode->hovered = false;
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

        // Stage 1: 让 platform 创建窗口 + 默认 RenderContext（测试环境直接使用此 context）
        $title = defined('WINDOW_TITLE') ? WINDOW_TITLE : Config::get('debug_window_title', 'Px');
        $defaultCtx = $this->platform->init($title, $w, $h);

        // 检查 C++ 绑定是否可用：无 vue_begin_paint 说明是测试环境（PHP-only），跳过后端选择
        if (!function_exists('vue_begin_paint')) {
            $this->renderer = new VNodeRenderer($this->rootComponent, $defaultCtx);
            error_log('[DIAG] initRenderer: test mode (no vue_begin_paint), using defaultCtx');
            return;
        }

        unset($defaultCtx);  // 显式释放默认 RC，让 RuntimeBackendSelector 创建最优后端

        // Stage 2: 用 RuntimeBackendSelector 探测 + 选择最优后端
        $hwnd = $this->platform->getHwnd();
        $selector  = new RuntimeBackendSelector();
        $backend   = $selector->select($hwnd, $w, $h);
        if ($backend === null) {
            error_log('[Application] initRenderer: backend selection failed, using fallback');
            $this->renderer = new VNodeRenderer($this->rootComponent, $defaultCtx);
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
                $instance->unmount();
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

        if (method_exists($instance, 'getClassStyles')) {
            $cs = $instance->getClassStyles();
            ThemeProvider::registerClassStyles(
                get_class($instance),
                $cs
            );
        }

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

        $this->layoutResolver->resolve($root);

        if (Config::get('debug_diag_enabled', false)) {
            $this->logScrollContainerStates('[DIAG] directRender AFTER');
        }

        $this->renderer->render($root);
    }

    /**
     * 导出布局快照到 JSON 文件。
     * 序列化 RenderNode 树的位置/尺寸/样式信息，用于分治测试和对比验证。
     */
    public function dumpLayoutToFile(string $path): void
    {
        $root = $this->renderTreeManager->getRootRenderNode();
        if ($root === null) {
            file_put_contents($path, '[]');
            return;
        }
        $data = $this->serializeRenderNode($root);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
     * 递归序列化 RenderNode 为数组。
     */
    private function serializeRenderNode(?RenderNode $node, array $parentStyle = []): ?array
    {
        if ($node === null) return null;
        $result = [
            'type' => $node->type,
            'x' => $node->x,
            'y' => $node->y,
            'w' => $node->w,
            'h' => $node->h,
            'visualW' => $node->visualW,
            'visualH' => $node->visualH,
            'layer' => $node->layer,
            'isScrollContainer' => $node->isScrollContainer,
            'scrollTop' => $node->scrollTop,
            'scrollLeft' => $node->scrollLeft,
            'contentHeight' => $node->contentHeight,
            'contentWidth' => $node->contentWidth,
            'renderOffsetX' => $node->renderOffsetX,
            'renderOffsetY' => $node->renderOffsetY,
            'content' => $node->content,
            'textRenderInfo' => $node->textRenderInfo,
        ];
        // 包含关键样式属性用于对比
        // 不导出 per-side border 属性：它们与 borderWidth/borderColor 简写重复，
        // 且浏览器只导出简写不单独导出各边。对比层通过浏览器 skip 列表忽略。
        $styleKeys = ['bg', 'fg', 'bgFromGradient', 'fontSize', 'fontWeight', 'bold', 'borderWidth', 'borderColor',
            'borderRadius', 'borderStyle', 'textAlign', 'textIndent', 'textTransform',
            'lineHeight', 'whiteSpace', 'wordBreak', 'fontStyle', 'fontFamily', 'opacity', 'visibility',
            'display', 'position', 'paddingTop', 'paddingLeft', 'paddingRight', 'paddingBottom',
            'marginTop', 'marginLeft', 'marginRight', 'marginBottom',
            '_computedMarginLeft', '_computedMarginRight',
            'minHeight', 'maxHeight', 'minWidth', 'maxWidth',
            'gap', 'boxSizing', 'boxShadow',
            'width', 'height',
            'flexDirection', 'alignItems', 'justifyContent', 'flexWrap',
            'flexShrink', 'flexGrow', 'order',
            'gridTemplateColumns', 'gridTemplateRows', 'gridColumnGap', 'gridRowGap',
            'gridColumn', 'gridRow', 'gridAutoRows', 'gridTemplateAreas',
            'justifyItems', 'alignSelf', 'justifySelf', 'alignContent',
            'overflow', 'overflowX', 'overflowY', 'overflowWrap', 'backgroundRepeat', 'textShadow',
            'pointerEvents',
            'outlineWidth', 'outlineStyle', 'outlineColor',
            'textDecorationLine', 'textDecorationColor', 'textDecorationStyle', 'textDecorationThickness'];
       $style = [];
        foreach ($styleKeys as $k) {
            if (isset($node->style[$k]) && $node->style[$k] !== null) {
                $style[$k] = $node->style[$k];
            }
        }
        // CSS 继承属性补全：引擎在 resolveNodeStyle 中已做继承，
        // 但部分场景下（如两阶段重布局后）继承值可能丢失。
        // 从父节点样式补全当前节点缺失的继承属性。
        // 仅补全 textAlign，其他继承属性如 fontFamily/fontSize 等
        // 因引擎全局 style 与浏览器默认值不同，序列化后会引入新噪声。
        if (!isset($style['textAlign']) && isset($parentStyle['textAlign'])) {
            $style['textAlign'] = $parentStyle['textAlign'];
        }
        // CSS 百分比宽/高：当 widthPercent/heightPercent 存在时，
        // style.width/style.height 是原始 CSS 值（如 100% → parsePixels 返回 100），
        // 应使用引擎布局计算后的 $node->w / $node->h 作为导出值
        if (isset($node->style['widthPercent'])) {
            $style['width'] = $node->w;
        }
        if (isset($node->style['heightPercent'])) {
            $style['height'] = $node->h;
        }

        // bg 总是导出：显式设置的值正常导出，未设置时用 -1 表示"无显式背景/透明"
        // 这确保元素即使没设背景也能参与颜色对比，否则 bg 缺失时对比逻辑直接跳过此类漏洞
        if (!isset($style['bg'])) {
            $style['bg'] = -1;
        }
        // ── border-color: 4-side format export (CSS 2.2 §8.5.2) ──
        // Browser always exports borderColor as 4-side string via getComputedStyle.
        // Engine must also export 4-side format when any per-side color is set.
        // Per-side colors not explicitly set fall back to the shorthand borderColor.
        $bc = $style['borderColor'] ?? null;
        $bw = $style['borderWidth'] ?? 0;
        if ($bc !== null || $bw > 0) {
            $bTopC = $node->style['borderTopColor'] ?? $bc;
            $bRightC = $node->style['borderRightColor'] ?? $bc;
            $bBottomC = $node->style['borderBottomColor'] ?? $bc;
            $bLeftC = $node->style['borderLeftColor'] ?? $bc;
            // Always export as 4-side format when any per-side color exists or borderWidth>0
            if ($bTopC !== null && $bRightC !== null && $bBottomC !== null && $bLeftC !== null) {
                $style['borderColor'] = self::formatColorInt($bTopC) . ' '
                    . self::formatColorInt($bRightC) . ' '
                    . self::formatColorInt($bBottomC) . ' '
                    . self::formatColorInt($bLeftC);
            }
        }
        // 总是导出 display，默认 block（CSS 2.2 §9.2.4：块级元素默认 display:block）
        // 内联元素（span, b, code, br 等）默认 display:inline（CSS 2.2 §9.2.2）
        if (!isset($style['display'])) {
            $inlineTypes = ['span', '#text', 'b', 'strong', 'em', 'i', 'code', 'a', 'label', 'br'];
            if (in_array($node->type, $inlineTypes, true)) {
                $style['display'] = 'inline';
            } else {
                $style['display'] = 'block';
            }
        }
        if (count($style) > 0) {
            $result['style'] = $style;
        }
        $children = [];
        foreach ($node->children as $child) {
            $serialized = $this->serializeRenderNode($child, $style);
            if ($serialized !== null) {
                $children[] = $serialized;
            }
        }
        if (count($children) > 0) {
            $result['children'] = $children;
        }
        return $result;
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

        // LayoutResolver 处理 RenderNode（利用 layoutDirty 增量）
        $this->layoutResolver->resolve($rootRenderNode);

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

    /**
     * Convert COLORREF int to CSS rgb() string for serialization.
     * COLORREF: bits 0-7=B, 8-15=G, 16-23=R
     */
    private static function formatColorInt(int $color): string
    {
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
        return "rgb($r, $g, $b)";
    }
}
