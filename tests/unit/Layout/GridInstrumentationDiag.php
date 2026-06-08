<?php
/**
 * GridInstrumentationDiag — 深度仪器化诊断
 * 
 * 跟踪 grid RenderNode 子节点数在每个阶段的详细变化。
 * 使用 Application + MockPlatform，模拟完整事件循环，
 * 在每帧之后检查 grid RN 的子节点。
 */

require_once __DIR__ . '/../bootstrap.php';

define('APP_PLATFORM', 'win32');
define('WINDOW_WIDTH', 1440);
define('WINDOW_HEIGHT', 900);
define('WINDOW_TITLE', '哔哩哔哩 - 热门视频');

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Platform\Platform;
use Px\Rendering\RenderContext;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\RenderNode;
use Px\Rendering\VNode;

// 加载组件
require_once __DIR__ . '/../../../apps/bilibili/gen/ComponentFactory.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/AppComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/MainContentComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoGridComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoCardComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/BannerCarouselComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/CategoryTabsComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/NavBarComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/FloatingButtonComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcInputComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcButtonComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcAvatarComponent.php';

$fw = dirname(__DIR__, 3) . '/framework';
require_once $fw . '/Core/ScrollManager.php';
require_once $fw . '/Styling/Adapter/PlatformStyling.php';
require_once $fw . '/Styling/Adapter/Win32Styling.php';
require_once $fw . '/Styling/Adapter/PlatformAdapter.php';

// Instrumented RenderTreeManager that logs grid children
class InstrumentedRenderTreeManager extends \Px\Rendering\RenderTreeManager
{
    public array $gridLog = [];
    private int $frameNum = 0;
    
    public function setFrameNum(int $n): void
    {
        $this->frameNum = $n;
    }
    
    public function updateFromVNode(
        VNode $vnode,
        ?RenderNode $parent,
        \Px\ReactiveComponent $root,
        array $componentByGroupId,
        ?array $candidates = null,
        string $currentGroupId = 'app'
    ): ?RenderNode {
        // 对 #component 节点，检查 instance 状态
        if ($vnode->isComponent()) {
            $hasInstance = $vnode->componentInstance !== null;
            if (!$hasInstance) {
                echo "  [INSTRUMENT] Frame{$this->frameNum}: #component({$vnode->componentClass}) instance=NULL - SKIPPED!\n";
            }
        }
        
        $result = parent::updateFromVNode($vnode, $parent, $root, $componentByGroupId, $candidates, $currentGroupId);
        
        // 对普通 div 节点，检查是否是 grid
        if (!$vnode->isComponent() && $vnode->type !== '#root') {
            $style = $vnode->props['style'] ?? '';
            if (strpos($style, 'display:grid') !== false) {
                $childCount = $result !== null ? count($result->children) : -1;
                $gridId = spl_object_id($result);
                echo "  [INSTRUMENT] Frame{$this->frameNum}: grid div (obj={$gridId}) children={$childCount}\n";
                $this->gridLog[] = [
                    'frame' => $this->frameNum,
                    'children' => $childCount,
                    'gridObjId' => $gridId,
                ];
            }
        }
        
        return $result;
    }
}

class _InstMockRenderContext extends RenderContext
{
    public function beginFrame(): void {}
    public function endFrame(): void {}
    public function drawElement(array $el): void {}
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

class _InstMockPlatform implements Platform
{
    public bool $shouldClose = false;
    public function init(string $title, int $width, int $height): RenderContext {
        return new _InstMockRenderContext();
    }
    public function shutdown(): void {}
    public function pollEvents(): array { return []; }
    public function setTimer(callable $callback, int $ms): void {}
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $type): void {}
    public function getHwnd(): int { return 0; }
    public function shouldClose(): bool { return $this->shouldClose; }
}

use Px\Styling\Provider\ThemeProvider;
use Px\Styling\Theme\ThemeData;

echo "==========================================================\n";
echo " Grid 深度仪器化诊断\n";
echo "==========================================================\n\n";

// 初始化主题
$baseTheme = ThemeData::light();
ThemeProvider::inject($baseTheme);

// 创建自定义 Application 子类以使用 instrumented RTM
$instApp = new class(new _InstMockPlatform(), new Scheduler()) extends Application {
    public InstrumentedRenderTreeManager $instRtm;
    
    public function __construct($platform, $scheduler) {
        parent::__construct($platform, $scheduler);
        // Replace the RTM with instrumented version
        $this->instRtm = new InstrumentedRenderTreeManager();
        // Use reflection to replace private $renderTreeManager
        $refProp = new \ReflectionProperty(Application::class, 'renderTreeManager');
        $refProp->setAccessible(true);
        $refProp->setValue($this, $this->instRtm);
    }
    
    // Expose private methods for testing
    public function publicRender(): void {
        $refMethod = new \ReflectionMethod(Application::class, 'render');
        $refMethod->setAccessible(true);
        $refMethod->invoke($this);
    }
    
    public function getRenderRequested(): bool {
        $refProp = new \ReflectionProperty(Application::class, 'renderRequested');
        $refProp->setAccessible(true);
        return $refProp->getValue($this);
    }
    
    public function setRenderRequested(bool $v): void {
        $refProp = new \ReflectionProperty(Application::class, 'renderRequested');
        $refProp->setAccessible(true);
        $refProp->setValue($this, $v);
    }
    
    public function getFrameCounter(): int {
        $refProp = new \ReflectionProperty(Application::class, 'frameCounter');
        $refProp->setAccessible(true);
        return $refProp->getValue($this);
    }
};

// 注册组件 class styles
$allComponents = [
    'AppComponent', 'NavBarComponent', 'CategoryTabsComponent', 
    'MainContentComponent', 'BannerCarouselComponent', 'VideoGridComponent',
    'VideoCardComponent', 'FloatingButtonComponent',
    'VcInputComponent', 'VcButtonComponent', 'VcAvatarComponent'
];
foreach ($allComponents as $cls) {
    $inst = ComponentFactory::create($cls);
    if (method_exists($inst, 'getClassStyles')) {
        $cs = $inst->getClassStyles();
        if (!empty($cs)) {
            ThemeProvider::registerClassStyles($cls, $cs);
        }
    }
}

$root = new AppComponent();
$instApp->mount($root, realpath(__DIR__ . '/../../apps/bilibili'));

$scheduler = new \ReflectionProperty(Application::class, 'scheduler');
$scheduler->setAccessible(true);
$appScheduler = $scheduler->getValue($instApp);

echo "=== Phase 1: doFirstRender (Frames 1 & 2) ===\n\n";

// Frame 1
echo "--- Frame 1 ---\n";
$instApp->instRtm->setFrameNum(1);
$instApp->publicRender();
echo "  Frame 1 complete. Grid log:\n";
foreach ($instApp->instRtm->gridLog as $entry) {
    echo "    frame={$entry['frame']} children={$entry['children']} objId={$entry['gridObjId']}\n";
}

echo "\n  Microtask count before flush: " . $appScheduler->getMicrotaskCount() . "\n";

// Flush microtasks
$instApp->instRtm->gridLog = [];
$appScheduler->flushMicrotasks();

echo "  After flushMicrotasks:\n";
echo "  renderRequested: " . ($instApp->getRenderRequested() ? 'yes' : 'no') . "\n";
echo "  Microtask count: " . $appScheduler->getMicrotaskCount() . "\n";

// Frame 2
if ($instApp->getRenderRequested()) {
    echo "\n--- Frame 2 ---\n";
    $instApp->setRenderRequested(false);
    $instApp->instRtm->setFrameNum(2);
    $instApp->publicRender();
    echo "  Frame 2 complete. Grid log:\n";
    foreach ($instApp->instRtm->gridLog as $entry) {
        echo "    frame={$entry['frame']} children={$entry['children']} objId={$entry['gridObjId']}\n";
    }
}

echo "\n=== Phase 2: Simulate event loop (microtasks that may trigger Frame 3) ===\n\n";

// Check remaining microtasks (from VideoCard setBindValue during Frame 2 expand)
echo "  Microtask count after Frame 2: " . $appScheduler->getMicrotaskCount() . "\n";

// Flush remaining microtasks (simulate first event loop iteration)
$instApp->instRtm->gridLog = [];
$appScheduler->flushMicrotasks();
echo "  After flush: renderRequested=" . ($instApp->getRenderRequested() ? 'yes' : 'no') . "\n";
echo "  Microtask count: " . $appScheduler->getMicrotaskCount() . "\n";

if ($instApp->getRenderRequested()) {
    echo "\n--- Frame 3 (triggered by VideoCard microtasks) ---\n";
    $instApp->setRenderRequested(false);
    $instApp->instRtm->setFrameNum(3);
    $instApp->publicRender();
    echo "  Frame 3 complete. Grid log:\n";
    foreach ($instApp->instRtm->gridLog as $entry) {
        echo "    frame={$entry['frame']} children={$entry['children']} objId={$entry['gridObjId']}\n";
    }
}

// Check the final RenderNode tree
$rtm = $instApp->getRenderTreeManager();
$rootRN = $rtm->getRootRenderNode();

// Recursive function to find grid RN
function findGridRNDetailed($node, $depth=0) {
    $indent = str_repeat('  ', $depth);
    $dsp = $node->style['display'] ?? '';
    if ($dsp === 'grid') {
        $childCount = count($node->children);
        echo "{$indent}FINAL GRID: {$node->x},{$node->y} {$node->w}x{$node->h} children={$childCount}\n";
        if ($childCount > 0) {
            foreach ($node->children as $i => $ch) {
                echo "{$indent}  child[{$i}]: type={$ch->type} {$ch->w}x{$ch->h}\n";
            }
        } else {
            echo "{$indent}  *** WARNING: Grid has NO children! ***\n";
        }
        return true;
    }
    foreach ($node->children as $ch) {
        if (findGridRNDetailed($ch, $depth+1)) return true;
    }
    return false;
}

echo "\n=== Final Render Tree Analysis ===\n";
if ($rootRN !== null) {
    findGridRNDetailed($rootRN);
    
    echo "\n--- Dump Render Tree (minimal) ---\n";
    echo $rtm->dumpRenderTree($rootRN, $instApp->getFrameCounter(), [], 'minimal');
} else {
    echo "  Root RN is null!\n";
}

echo "\n==========================================================\n";
echo " 诊断完成\n";
echo "==========================================================\n";
