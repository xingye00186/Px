<?php

namespace Px\Core;
use Px\Css\ComputedStyle;

use native_types;

use Px\Platform\Platform;
use Px\Platform\PlatformEvent;
use Px\Platform\PointerEvent;
use Px\Platform\KeyEvent;
use Px\Platform\LifecycleEvent;
use Px\Platform\MetricsEvent;
use Px\Platform\RedrawEvent;
use Px\Platform\PlatformFactory;
use Px\Paint\Backend\ResilientRenderContext;
use Px\Paint\Backend\RuntimeBackendSelector;
use Px\Paint\Backend\CapturingRenderContext;
use Px\Text\TextBackendRegistry;
use Px\Text\ResilientTextBackendProxy;
use Px\Dom\VNode;
use Px\Render\RenderNode;
use Px\Paint\PaintPipeline;
use Px\Layout\LayoutOrchestrator;
use Px\Css\StyleRecalcPass;
use Px\Css\StyleEngine;
use Px\Layout\PhysicalFragment;
use Px\Core\Diag;
use Px\Paint\InteractionState;
use Px\Css\CssMappings;
use Px\Paint\ImageManager;
use Px\Render\RenderTreeManager;
use Px\Animation\ClickEffects;
use Px\Component\Contracts\ReactiveComponentInterface;
use Px\Component\ReactiveComponent;
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
 *   → LayoutOrchestrator::layout（RenderNode → Fragment 坐标计算）
 *   → PaintPipeline::render（Fragment 树 → GDI 调用）
 */
class Application
{
    private Platform $platform;
    private Scheduler $scheduler;
    private ?PaintPipeline $paintPipeline = null;
        private ?LayoutOrchestrator $layoutOrchestrator = null;
    private RenderTreeManager $renderTreeManager;

    /** @var string 最近一次 layout 的 Fragment JSON 快照（供 dumpLayoutToFile 直接读取，避免重算） */
    private string $lastLayoutDumpJson = '';

    /** @var PhysicalFragment|null 最近一次 layout 的 Fragment 树（paint 儿何权威源，供测试 dump） */
    private ?PhysicalFragment $lastFragmentTree = null;

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

    public function removeInteractionState(RenderNode $node): void
    {
        unset($this->interactionStates[spl_object_id($node)]);
    }

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

    /** 帧调度器：帧计时 + 动画驱动（P1.3）。动画默认关闭，行为等价。 */
    private FrameScheduler $frameScheduler;

    // ── 动画压测模式（Px_anim_autotest）：10Hz 合成点击 + 逐帧时间戳日志 ──
    private bool $animAutotestActive = false;
    private int $animAutotestClicks = 0;
    private int $animAutotestNextAtUs = 0;
    /** @var int[] 动画帧墙钟时间戳（μs） */
    private array $animFrameLog = [];
    /** @var int[] inputDigit 按钮中心 X（与 Ys 平行；经 hitTest 网格扫描发现） */
    private array $animAutotestBtnXs = [];
    /** @var int[] inputDigit 按钮中心 Y */
    private array $animAutotestBtnYs = [];

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
            } elseif (str_starts_with($arg, '--diag-layout=')) {
                $diagLevel = max(0, (int)substr($arg, strlen('--diag-layout=')));
                \Px\Core\Diag::initFromCli($diagLevel);
            } elseif (str_starts_with($arg, '--diag-log-path=')) {
                \Px\Core\Diag::setLogPath(substr($arg, strlen('--diag-log-path=')));
            }
        }

        // Auto-default: --case=xxx → test_case/{xxx}/ref/engine_layout_aot.json
        // 直接运行 exe 即为 AOT 模式，使用 _aot 后缀
        if ($dumpTo === '') {
            if ($caseName !== '') {
                $dumpTo = $appDir . '/test_case/' . $caseName . '/ref/engine_layout_aot.json';
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
            // 确保文件名带 _aot 后缀（直接 exe 调用就是 AOT 模式）
            if (!str_contains($path, '_aot.') && !str_contains($path, '_php.') && !str_contains($path, '_after_')) {
                $ext = '.json';
                $base = substr($path, 0, -strlen($ext));
                $path = $base . '_aot' . $ext;
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
        ?RenderTreeManager $renderTreeManager = null,
        ?LayoutOrchestrator $layoutOrchestrator = null,
    ) {
        $this->platform  = $platform;
        $this->scheduler = $scheduler;
        $this->layoutOrchestrator = $layoutOrchestrator ?? new LayoutOrchestrator();
        $this->renderTreeManager = $renderTreeManager ?? new RenderTreeManager();
        $this->scrollManager = new ScrollManager(
            $this->requestRender(...),
            function () { $this->directRender(); },
            $this->resolveComponentByGroupId(...),
            $this->renderTreeManager->findScrollContainerAt(...)
        );
        // 注入 ScrollManager 到 RenderTreeManager（scroll bind 路由）
        $this->renderTreeManager->setScrollManager($this->scrollManager);
        // 帧调度器：动画默认关闭，mount() 时按 project.yml 的 Px_animation_enabled 调整
        $this->frameScheduler = new FrameScheduler();
        // PaintPipeline 依赖 RenderContext，在 initRenderer() 中初始化
    }

    // ── 平台事件处理器 ─────────────────────────

    private function handleRenderRequest(): void
    {
        $this->renderRequested = true;
    }

    private function handlePointerEvent(PointerEvent $event): void
    {
        if ($this->activeVNodeTree === null) {
            return;
        }

        $action = $event->getAction();

        // ── 指针滚轮：驱动滚动容器 ────────────
        if ($event->getAction() === 'wheel') {
            $this->scrollManager->handleScrollWheel($event);
            return;
        }

        // ── 指针移动：滚动条拖拽 + 光标 hover + :hover 样式 ──
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
                // 当 hover 节点变化时，更新新旧节点的 hovered 标志、标记脏位、触发渲染
                // 对标 Blink Element::PseudoStateFlags：RenderNode.hovered 作为单一权威源（不再双写 InteractionState）
                if ($hoverNode !== $this->hoveredNode) {
                    // 清除旧节点的 hover 状态
                    if ($this->hoveredNode !== null) {
                        $this->hoveredNode->hovered = false;
                        $this->hoveredNode->markStyleDirty();  // 使 cachedFragment 失效
                    }
                    // 设置新节点的 hover 状态
                    if ($hoverNode !== null) {
                        $hoverNode->hovered = true;
                        $hoverNode->markStyleDirty();          // 使 cachedFragment 失效
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

        // ── 指针抬起：结束拖拽，持久化滚动位置 ──
        if ($event->getAction() === 'up') {
            $this->scrollManager->handleMouseUp();
            return;
        }

        // ── 指针按下：优先检测滚动条，其次 @click ──
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
                    // 点击特效（Px_anim_click_effects，依赖动画总开关）：
                    // 彩虹光晕渐隐 + 标签飞升到 fly-to 目标（如 display）
                    if ($this->frameScheduler->isAnimationEnabled()
                        && Config::get('anim_click_effects', false) === true) {
                        ClickEffects::trigger(
                            $renderNode,
                            $this->renderTreeManager->getLastHitFragment(),
                            $this->renderTreeManager->findFragmentByBind(
                                (string)Config::get('anim_fly_to_bind', '')
                            )
                        );
                    }
                }
            }
        }
    }

    private function handleKeyEvent(KeyEvent $event): void
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
        // C2.5-full 生产激活：注册组件的编译期规则存储（StyleSheetContents）
        // 到 StyleEngine。registerComponent 是根+子组件的单一汇聚点。
        // 等价门控已过（tools/c25_equivalence_gate.php：56 cases / 6344 属性 /
        // MISMATCH 0）；引擎声明与烘焙同值，叠加后幂等。
        // 双通道并行期：烘焙仍为基底，引擎额外提供结构伪类等超集能力。
        StyleEngine::registerComponentRules($component);
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

    /** 帧调度器访问器（供测试与外部切换动画开关）。 */
    public function getFrameScheduler(): FrameScheduler
    {
        return $this->frameScheduler;
    }

    private function initRenderer(): void
    {
        $w = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : Config::get('window_width', 1280);
        $h = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : Config::get('window_height', 720);

        // Stage 1: Embedder 只造表面（P1.3 Surface 解耦，对标 Flutter）。
        // 渲染上下文不再由平台生产：下方由框架侧从 RenderSurface 构造，
        // 光栅产物归引擎所有（测试经 getPaintPipeline()->getRenderContext() 读回）。
        $title = defined('WINDOW_TITLE') ? WINDOW_TITLE : Config::get('debug_window_title', 'Px');
        $this->platform->init($title, $w, $h);
        $surface = $this->platform->getSurface();
        $hwnd = $surface->getHandle();

        // PHP-only（无 C++ 绑定）：框架侧构造捕获后端（对应 Flutter 软件渲染路径）。
        // 注：CLI 下 stub 定义了 vue_begin_paint，故本分支在 stub 环境不触发，
        // CLI 测试继续走 Stage 2（与改造前行为等价，element 捕获仍为空）。
        // 激活 CLI 捕获需同批再生成 336 基线（当前全为 "(no elements)"），
        // 属独立批次（见计划文档 P1.3 第二段未证边界）。
        if (!function_exists('vue_begin_paint')) {
            $this->paintPipeline = new PaintPipeline($this->rootComponent, new CapturingRenderContext());
            error_log('[DIAG] initRenderer: offscreen test mode, using CapturingRenderContext');
            return;
        }

        // Stage 2: 用 RuntimeBackendSelector 探测 + 选择最优后端
        // headless 模式同样走后端选择（handle=0 时 skia-cpu 使用内存离屏 surface）
        // 原生句柄经 RenderSurface 中转，Framework 层不直接接触 HWND 概念
        $selector  = new RuntimeBackendSelector();
        try {
            $backend = $selector->select($hwnd, $w, $h);
        } catch (\Throwable $e) {
            error_log('[Application] initRenderer: backend selection threw: ' . $e->getMessage());
            $this->paintPipeline = new PaintPipeline($this->rootComponent, new GdiRenderContext($hwnd));
            return;
        }
        $this->selectedBackendName = $backend->getName();
        error_log('[DIAG] initRenderer: selected backend=' . $this->selectedBackendName);

        // Stage 3: 包一层 ResilientRenderContext 支持渲染后端降级
        $baseCtx = new ResilientRenderContext($selector, $backend->getContext(), $hwnd, $w, $h);

        // Stage 4: 包一层 ResilientTextBackendProxy 支持文本引擎降级
        $renderCtx = new ResilientTextBackendProxy($baseCtx);

        $this->paintPipeline = new PaintPipeline($this->rootComponent, $renderCtx);
    }

    /** 绘制管线访问器（测试从引擎侧读回光栅产物；对标 Flutter — 光栅器属 engine）。 */
    public function getPaintPipeline(): ?PaintPipeline
    {
        return $this->paintPipeline;
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

        // 帧调度器动画开关：project.yml 的 Px_animation_enabled（默认关闭 → 行为等价）
        $this->frameScheduler->setAnimationEnabled(Config::get('animation_enabled', false) === true);

        // 初始化文本后端（渲染层管理：TextBackendRegistry）
        TextBackendRegistry::initialize();

        // 注册 RenderNode 销毁回调（统一清理 ScrollManager/InteractionState orphan 条目）
        $this->renderTreeManager->onDestroyNode(function (RenderNode $rn): void {
            $this->removeInteractionState($rn);
            $this->scrollManager->removeScrollState($rn);
        });

        $this->initRenderer();

        // C1.5：主题注入段删除——ThemeData 族为生产僵尸（注入后零读取，
        // 审计 §2.1），ThemeData/PlatformAdapter/PlatformStyling 等 9 文件同批删除。
        // 编译时 class→style 合并已完成，无需运行时注册
        //（getClassStyles() 已从 gen 文件中移除）。

        $this->rootComponent->mount();
        
        // 定时器仅供 onTimerTick（carousel 等秒级功能）。
        // 动画帧不走 WM_TIMER（分辨率 ~15.6ms + 消息合并，到不了稳定 60FPS），
        // 由 run() 主循环 FrameScheduler 自节拍驱动（对标 Flutter Ticker）。
        // 优化：仅当根组件定义了 onTimerTick 时才触发 requestRender，
        // 避免静态页面空转渲染浪费 CPU。
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
            // 常规组件路径：求值表达式并存储到 componentPropValues
            // 以便下一帧 matchComponentNode 能比对求值后的值（而非表达式字符串）
            $evaluatedValues = [];
            foreach ($node->componentProps as $childKey => $parentExpr) {
                if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                    $staticValue = substr($parentExpr, 7);
                    $instance->setBindValue($childKey, $staticValue);
                    $evaluatedValues[$childKey] = $staticValue;
                } else {
                    $parentValue = $owner->getBindValue($parentExpr);
                    $instance->setBindValue($childKey, $parentValue);
                    $evaluatedValues[$childKey] = $parentValue;
                }
            }
            $node->componentPropValues = $evaluatedValues;
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

            // ── 方案 A：VNode 树身份修复 ──
            // 当 oldNode === newNode（根 VNode 树缓存命中，同一对象）时，
            // 必须在替换 children 之前保存旧 children 引用，
            // 否则 $oldNode->children 会被 $newNode->children = getVNodeTree() 覆盖，
            // 导致递归 patchComponentTree 的 oldNode->children 和 newNode->children 指向同一个新树，
            // 所有子 #component 节点的 $oldNode === $newNode → componentInstance 全为 null → 全部走 EXPAND。
            // 保存旧 children 可以保证子组件的匹配走正常的 REUSE 路径。
            $oldChildren = ($oldNode !== null) ? $oldNode->children : null;

            // ── 组件级跳过：props 求值未变且组件未 dirty → 直接复用旧 VNode 树 ──
            // Vue 3 对标：hasChanged 检查在 trigger 之前；Px 在此预求值并比对。
            // 关键：setBindValue 必须在此检查之后调用，否则会触发响应式系统
            // 将子组件标记为 dirty（renderDirty=true），导致 updateFromVNode 无法跳过。
            // componentProps 存储的是表达式字符串（每帧相同），不能直接用于判断值是否变化；
            // 必须求值后与上一帧的 componentPropValues 比对。
            $newPropValues = null;
            if ($newNode->componentPropValues !== null) {
                // v-for 预计算值路径
                $newPropValues = $newNode->componentPropValues;
            } elseif ($newNode->componentProps !== null) {
                $newPropValues = [];
                foreach ($newNode->componentProps as $childKey => $parentExpr) {
                    if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                        $newPropValues[$childKey] = substr($parentExpr, 7);
                    } else {
                        $newPropValues[$childKey] = $owner->getBindValue($parentExpr);
                    }
                }
            }
            $oldPropValues = $oldNode?->componentPropValues ?? null;
            $propsChanged = ($newPropValues !== $oldPropValues);

            // 存储求值后的 props 供下一帧比对
            $newNode->componentPropValues = $newPropValues;

            if (!$propsChanged && !$instance->dirty && $oldChildren !== null) {
                $matched = clone $newNode;
                $matched->componentInstance = $instance;
                $matched->children = $oldChildren;
                $this->registerComponent($instance->getId(), $instance);
                return $matched;
            }

            // props 已变更或组件 dirty → 传递新 props 值到子组件实例
            if ($newPropValues !== null) {
                foreach ($newPropValues as $childKey => $value) {
                    $instance->setBindValue($childKey, $value);
                }
            }

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

        $fragmentTree = $this->layoutOrchestrator->layout($root);
        // 命中测试几何同源（动画帧/滚动帧同样刷新）
        $this->renderTreeManager->setPaintedFragmentTree($fragmentTree);

        if (Config::get('debug_diag_enabled', false)) {
            $this->logScrollContainerStates('[DIAG] directRender AFTER');
        }

        if ($fragmentTree !== null) {
            $this->paintPipeline->render($fragmentTree);
        }
    }

    /**
     * 导出布局快照到 JSON 文件。
     * 序列化 RenderNode 树的位置/尺寸/样式信息，用于分治测试和对比验证。
     */
    public function dumpLayoutToFile(string $path, bool $caseContentOnly = false): void
    {
        // 快照由 render() 在每次 layout() 后通过 captureLayoutSnapshot() 写入（仅 debug_diag 模式）。
        // 非 diag 模式下按需从 lastFragmentTree 捕获（同一 Fragment 树，几何权威源一致）——
        // 此前恒写空字符串导致 css-test dump 产物 0 字节（测试基础设施缺陷）。
        if ($this->lastLayoutDumpJson === '' && $this->lastFragmentTree !== null) {
            $this->captureLayoutSnapshot($this->lastFragmentTree);
        }
        file_put_contents($path, $this->lastLayoutDumpJson);
    }

    /**
     * 以文本格式 dump 最近一次 layout 的 Fragment 树（供 css-test harness 对比）。
     * 格式与 RenderTreeManager::dumpRenderTree 一致，但几何取自 Fragment 树
     * （paint 实际渲染的权威源），避免 RenderNode.cachedFragment 的 Phase B 预布局偏差。
     */
    public function dumpFragmentTreeForTest(): string
    {
        if ($this->lastFragmentTree === null) {
            return '';
        }
        $output = "Frame #1 events=0 detail=normal\n";
        $root = $this->lastFragmentTree;
        // #root 节点不产生元素，展示子节点（与 dumpRenderTree 行为一致）
        if ($root->type === '#root') {
            foreach ($root->children as $child) {
                $output .= $this->renderTreeManager->dumpFragmentTree($child, '');
            }
        } else {
            $output .= $this->renderTreeManager->dumpFragmentTree($root, '');
        }
        return $output;
    }



    /**
     * 捕获 Fragment 快照为 JSON 字符串（与渲染使用同一 Fragment 树，保证一致性）。
     * JSON 字符串存储在 Application 字段中，避免 AOT use native_types 对象引用无法持久化的问题。
     */
    private function captureLayoutSnapshot(PhysicalFragment $frag): void
    {
        $data = $this->fragmentToArray($frag);
        $this->lastLayoutDumpJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (isset($data['children'][0])) {
            error_log("[DIAG_FRAG] child[0] w=" . $data['children'][0]['w'] . " h=" . $data['children'][0]['h']);
        }
        Diag::log(1, 'snapshot:done', ['jsonSize' => strlen($this->lastLayoutDumpJson)]);
    }

    /**
     * PhysicalFragment 树 → 数组（与 RenderNodeSerializer 输出格式兼容）。
     * 几何直接取自 Fragment，不需要 RenderNode 回写。
     */
    private function fragmentToArray(PhysicalFragment $frag): array
    {
        // 直接读取 geomtry（AOT 下通过 getter 确保跨类 readonly 正确）
        $arr = [
            'x' => (int)$frag->getX(),
            'y' => (int)$frag->getY(),
            'w' => (int)$frag->getW(),
            'h' => (int)$frag->getH(),
            'visualW' => (int)$frag->getVisualW(),
            'visualH' => (int)$frag->getVisualH(),
            'layer' => (int)$frag->getLayer(),
            'contentWidth' => (int)$frag->getContentWidth(),
            'contentHeight' => (int)$frag->getContentHeight(),
        ];
        if ($frag->getIsScrollContainer()) {
            $arr['scrollTop'] = (int)$frag->getScrollTop();
            $arr['scrollLeft'] = (int)$frag->getScrollLeft();
        }

        // Override children with full fragmentToArray (adds sourceNode fields)
        $children = [];
        foreach ($frag->children as $child) {
            $children[] = $this->fragmentToArray($child);
        }
        $arr['children'] = $children;

        // Add Fragment-derived fields
        $arr['type'] = $frag->type !== '' ? $frag->type : ($frag->sourceNode !== null ? $frag->sourceNode->type : 'div');
        $arr['content'] = $frag->content !== null ? $frag->content : ($frag->sourceNode !== null ? $frag->sourceNode->content : null);
        $arr['key'] = $frag->sourceNode !== null ? $frag->sourceNode->key : null;
        $arr['groupId'] = $frag->sourceNode !== null ? $frag->sourceNode->groupId : null;
        $arr['dataset'] = $frag->dataset;

        // Style serialization
        if ($frag->style !== null) {
            $arr['style'] = $this->fragmentStyleToArray($frag->style);
        }

        return $arr;
    }

    /**
     * ComputedStyle → 简单数组（与 RenderNodeSerializer 的 style flatten 兼容）。
     * 只提取标量/简单值，跳过嵌套对象（CssLength/CssColor 等由正常化阶段处理）。
     */
    private function fragmentStyleToArray(\Px\Css\ComputedStyle $style): array
    {
        $result = [];
        // CssKeyword/CssLength/CssColor/CssRect/CssFlex 对象导出其值
        $vars = get_object_vars($style);
        foreach ($vars as $k => $v) {
            if ($v === null || $v === '') continue;
            if (is_scalar($v) || is_array($v)) {
                $result[$k] = $v;
            } elseif ($v instanceof \Px\Css\CssKeyword) {
                $result[$k] = $v->value;
            } elseif ($v instanceof \Px\Css\CssLength) {
                $result[$k] = $v->toPx();
            } elseif ($v instanceof \Px\Css\CssColor) {
                // C3a.3：导出 full argb（保 alpha 高字节）；opaque 色高字节 0 与
                // toBgr() 等价（向后兼容），半透明色携 alpha 供 Normalizer 输出 rgba。
                $result[$k] = $v->argb;
            } elseif ($v instanceof \Px\Css\CssFlex) {
                $result[$k] = $v->grow . ' ' . $v->shrink . ' ' . $v->basis->toPx();
            } elseif ($v instanceof \Px\Css\CssRect) {
                $result[$k . 'Top'] = $v->top->toPx();
                $result[$k . 'Right'] = $v->right->toPx();
                $result[$k . 'Bottom'] = $v->bottom->toPx();
                $result[$k . 'Left'] = $v->left->toPx();
                // margin:auto 哨兵（toPx 把 auto 打成 0，used 值导出侧无从
                // 判别；007/014 B 报 used px vs E 0px，几何推断已否定）：
                // Normalizer 据此 + rect 反推 used margin。
                if ($k === 'margin') {
                    if ($v->left->isAuto()) { $result['marginLeftAuto'] = true; }
                    if ($v->right->isAuto()) { $result['marginRightAuto'] = true; }
                }
            }
        }
        return $result;
    }

    /**
     * 在数组树中查找 data-px-anchor='tl' 的父容器子树。
     */
    private function trimToTestContent(array $node): ?array
    {
        $ds = $node['dataset'] ?? [];
        if (isset($ds['pxAnchor']) && $ds['pxAnchor'] === 'tl') {
            return null; // 找到锚点节点自身，返回 null 表示应使用其父节点
        }
        foreach ($node['children'] ?? [] as $child) {
            $childDs = $child['dataset'] ?? [];
            if (isset($childDs['pxAnchor']) && $childDs['pxAnchor'] === 'tl') {
                return $node; // 子节点是锚点，当前节点即父容器
            }
            $result = $this->trimToTestContent($child);
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
        if ($this->paintPipeline !== null && function_exists('sk_save_screenshot')) {
            $this->paintPipeline->getRenderContext()->saveScreenshot($path);
        }
    }

    /**
     * 完整渲染流程：
     *   1. 重建 VNode 树（含组件展开）
     *   2. RenderTreeManager::updateFromVNode 转换并同步 bind 值
     *   3. LayoutOrchestrator::layout 计算坐标
     *   4. PaintPipeline::render 生成 GDI 调用
     */
    public function render(): void
    {
        \Px\Core\PerfCounter::start('stage:full_render');
        $this->debugFrameNumber++;
        $frame = $this->debugFrameNumber;

        \Px\Core\PerfCounter::start('stage:vnode_tree');
        $this->rebuildVNodeTree();
        \Px\Core\PerfCounter::end('stage:vnode_tree');

        // Phase 0.5: 独立 StyleRecalc 通行证（将样式解析从 RenderTreeManager 抽出）
        if ($this->activeVNodeTree !== null) {
            \Px\Core\PerfCounter::start('stage:style_recalc');
            $styleRecalc = new StyleRecalcPass();
            $styleRecalc->recalc($this->activeVNodeTree);
            \Px\Core\PerfCounter::end('stage:style_recalc');
        }

        // getRootRenderNodes() = 顶层 #root 所有旧子节点，作为 candidates 传递给 #root handler
        $oldRootChildren = $this->renderTreeManager->getRootRenderNodes();
        $candidates = !empty($oldRootChildren) ? $oldRootChildren : null;

        // 保存旧根 RenderNode 供 scrollTop 恢复使用
        $oldRootRenderNode = $this->renderTreeManager->getRootRenderNode();

        // VNode → RenderNode 转换 + bind 值同步（type+key 匹配复用）
        \Px\Core\PerfCounter::start('stage:update_from_vnode');
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
        \Px\Core\PerfCounter::end('stage:update_from_vnode');
        if ($rootRenderNode === null) {
            \Px\Core\PerfCounter::end('stage:full_render');
            return;
        }

        // Dirty 传播：自底向上标记父链（确保布局不跳过有脏子树的父节点）
        \Px\Core\PerfCounter::start('sub:dirty_propagate');
        $this->renderTreeManager->propagateLayoutDirty($rootRenderNode);
        \Px\Core\PerfCounter::end('sub:dirty_propagate');

        // scroll 状态已通过 ScrollManager 管理，无需复制（copyScrollTopFromOld 已删除）

        if (Config::get('debug_diag_enabled', false)) {
            error_log('[DIAG] render() frame=' . $frame . ' BEFORE resolve');
            $this->logScrollContainerStates('[DIAG] render BEFORE');
        }

        // LayoutOrchestrator 处理 RenderNode 并收集 Fragment 树
        \Px\Core\PerfCounter::start('stage:layout');
        $fragmentTree = $this->layoutOrchestrator->layout($rootRenderNode);
        $this->lastFragmentTree = $fragmentTree;
        // 命中测试几何同源（对标 Blink：HitTest 走 PhysicalFragment 树）
        $this->renderTreeManager->setPaintedFragmentTree($fragmentTree);
        \Px\Core\PerfCounter::end('stage:layout');

        if (Config::get('debug_diag_enabled', false)) {
            $this->logScrollContainerStates('[DIAG] render AFTER');
        }

        Diag::log(1, 'render:done', ['fragExists' => $fragmentTree !== null ? 'yes' : 'no']);

        // 捕获 Fragment 快照（仅调试模式）
        \Px\Core\PerfCounter::start('stage:capture_snapshot');
        if (Config::get('debug_diag_enabled', false)) {
            $this->captureLayoutSnapshot($fragmentTree);
        }
        \Px\Core\PerfCounter::end('stage:capture_snapshot');

        // PaintPipeline 从 Fragment 树渲染
        \Px\Core\PerfCounter::start('stage:paint');
        $this->paintPipeline->render($fragmentTree);
        \Px\Core\PerfCounter::end('stage:paint');
        \Px\Core\PerfCounter::end('stage:full_render');
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
        if (Config::get('debug_diag_enabled', false)) {
            error_log('[DIAG] doFirstRender: Frame 1 start');
        }
        $this->render();
        if (Config::get('debug_diag_enabled', false)) {
            error_log('[DIAG] doFirstRender: Frame 1 done');
        }

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

        // 动画压测模式：需动画总开关 + Px_anim_autotest 同时开启
        $this->animAutotestActive = $this->frameScheduler->isAnimationEnabled()
            && Config::get('anim_autotest', false) === true;
        if ($this->animAutotestActive) {
            // 首击延迟 500ms，让首帧/缓存稳定
            $this->animAutotestNextAtUs = (int)(microtime(true) * 1000000) + 500000;
            error_log('[ANIM-AUTOTEST] armed: 30 clicks @10Hz');
        }

        while ($this->running) {
            try {
                $rawEvents = $this->platform->pollEvents();
                foreach ($rawEvents as $ev) {
                    if ($ev instanceof PointerEvent) {
                        $this->handlePointerEvent($ev);
                    } elseif ($ev instanceof KeyEvent) {
                        $this->handleKeyEvent($ev);
                    } elseif ($ev instanceof RedrawEvent) {
                        // 表面内容失效（Win32 WM_PAINT）：触发重绘
                        error_log('[DIAG] RedrawEvent received, triggering render');
                        $this->requestRender();
                    } elseif ($ev instanceof MetricsEvent) {
                        // 视图度量变化（尺寸/DPR/安全区域）：重绘以重算布局
                        $this->requestRender();
                    } elseif ($ev instanceof LifecycleEvent) {
                        // 宿主销毁：终止事件循环（其余状态桌面端不产出）
                        if ($ev->isDetached()) {
                            $this->running = false;
                        }
                    }
                }

                $this->scheduler->flushMicrotasks();

                if ($this->renderRequested) {
                    $this->renderRequested = false;
                    $this->render();
                }

                $hasMacro = $this->scheduler->runOneMacrotask();

                // 动画帧自节拍（对标 Flutter Ticker）：WM_TIMER 到不了稳定 60FPS，
                // 由主循环以 frameDue 判定驱动；tick 推进插值后 directRender
                //（跳过 VNode 重建，帧成本 ≈ layout+paint ≈ 1-3ms）。
                if ($this->frameScheduler->isAnimationEnabled()
                    && $this->frameScheduler->hasActiveAnimations()
                    && $this->frameScheduler->frameDue()) {
                    $this->frameScheduler->tick();
                    $this->directRender();
                    if ($this->animAutotestActive) {
                        $this->animFrameLog[] = (int)(microtime(true) * 1000000);
                    }
                }

                // 动画压测：10Hz 合成点击（走完整 hitTest+dispatch 链）
                if ($this->animAutotestActive) {
                    $this->animAutotestStep();
                }

                if (!$hasMacro && !$this->renderRequested && count($rawEvents) === 0
                    && !$this->frameScheduler->hasActiveAnimations()) {
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

    // ── 动画压测（Px_anim_autotest）────────────────

    /**
     * 10Hz 合成点击步进：30 次后排空动画写 FPS 报告。
     * 合成 PointerEvent 走完整 handlePointerEvent 链（含 hitTest），
     * 仅跳过 OS 消息队列翻译层（成本 <0.1ms，不影响结论）。
     */
    private function animAutotestStep(): void
    {
        $nowUs = (int)(microtime(true) * 1000000);
        if ($this->animAutotestClicks >= 30) {
            // 排空：等全部动画结束再写报告并关闭压测
            if (!$this->frameScheduler->hasActiveAnimations()) {
                $this->animAutotestWriteReport();
                $this->animAutotestActive = false;
            }
            return;
        }
        if ($nowUs < $this->animAutotestNextAtUs) {
            return;
        }
        $btnCount = (int)count($this->animAutotestBtnXs);
        if ($btnCount === 0) {
            // hitTest 网格扫描（纯生产路径，与真实点击同链路）：
            // 步长 24px 扫全窗，命中 @click=inputDigit 的坐标入表，
            // 按命中节点去重（spl_object_id）。
            $w = defined('WINDOW_WIDTH') ? WINDOW_WIDTH : 340;
            $h = defined('WINDOW_HEIGHT') ? WINDOW_HEIGHT : 660;
            $seen = [];
            for ($sy = 12; $sy < $h; $sy += 24) {
                for ($sx = 12; $sx < $w; $sx += 24) {
                    $hitNode = $this->renderTreeManager->hitTest($sx, $sy);
                    if ($hitNode === null || $hitNode->sourceVNode === null) {
                        continue;
                    }
                    if (!isset($hitNode->sourceVNode->props['@click'])) {
                        continue;
                    }
                    $click = (string)$hitNode->sourceVNode->props['@click'];
                    if ($click !== 'inputDigit') {
                        continue;
                    }
                    $oid = spl_object_id($hitNode);
                    if (isset($seen[$oid])) {
                        continue;
                    }
                    $seen[$oid] = true;
                    $this->animAutotestBtnXs[] = $sx;
                    $this->animAutotestBtnYs[] = $sy;
                }
            }
            $btnCount = (int)count($this->animAutotestBtnXs);
            if ($btnCount === 0) {
                // 避免每循环刷屏：改为 500ms 后重试
                $this->animAutotestNextAtUs = $nowUs + 500000;
                return;
            }
        }
        $idx = $this->animAutotestClicks % $btnCount;
        $cx = $this->animAutotestBtnXs[$idx];
        $cy = $this->animAutotestBtnYs[$idx];
        $this->handlePointerEvent(new PointerEvent('down', $cx, $cy));
        $this->handlePointerEvent(new PointerEvent('up', $cx, $cy));
        $this->animAutotestClicks++;
        $this->animAutotestNextAtUs = $nowUs + 100000; // 10Hz
    }

    /**
     * 逐帧间隔统计（μs）：avg / p95 / max + 换算 FPS，写
     * {appDir}/debug/anim_fps.json 并同时 error_log（exe 日志可直接 grep）。
     */
    private function animAutotestWriteReport(): void
    {
        $n = (int)count($this->animFrameLog);
        $intervals = [];
        for ($i = 1; $i < $n; $i++) {
            $intervals[] = $this->animFrameLog[$i] - $this->animFrameLog[$i - 1];
        }
        $report = ['frames' => $n, 'clicks' => $this->animAutotestClicks];
        $ic = (int)count($intervals);
        if ($ic > 0) {
            sort($intervals);
            $sum = 0;
            foreach ($intervals as $iv) {
                $sum += $iv;
            }
            $avg = intdiv($sum, $ic);
            $p95Idx = intdiv($ic * 95, 100);
            if ($p95Idx >= $ic) {
                $p95Idx = $ic - 1;
            }
            $report['avg_interval_us'] = $avg;
            $report['p95_interval_us'] = $intervals[$p95Idx];
            $report['max_interval_us'] = $intervals[$ic - 1];
            $report['avg_fps'] = $avg > 0 ? intdiv(1000000, $avg) : 0;
        }
        $dir = Config::getOutputDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($dir . '/anim_fps.json', json_encode($report));
        error_log('[ANIM-AUTOTEST] report: ' . json_encode($report));
    }
}
