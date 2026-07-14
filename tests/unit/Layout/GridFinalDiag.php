<?php
/**
 * GridAppFinalDiag — 使用 MockPlatform 完整模拟 Application::render 流程
 * 直接检查 grid RenderNode 的子节点数
 */

require_once __DIR__ . '/../bootstrap.php';

// 应用常量
define('APP_PLATFORM', 'win32');
define('WINDOW_WIDTH', 1440);
define('WINDOW_HEIGHT', 900);
define('WINDOW_TITLE', '哔哩哔哩 - 热门视频');

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Platform\Platform;
use Px\Platform\MouseEvent;
use Px\Paint\RenderContext;
use Px\Paint\VNodeRenderer;
use Px\Render\RenderNode;

// 加载 bilibili 组件
require_once __DIR__ . '/../../../apps/bilibili/gen/ComponentFactory.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/AppComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/MainContentComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoGridComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoCardComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/BannerCarouselComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/CategoryTabsComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/NavBarComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/FloatingButtonComponent.php';

// 加载 vc-ui 组件 (NavBar 依赖) — 它们在 bilibili/gen 目录下也有副本
require_once __DIR__ . '/../../../apps/bilibili/gen/VcInputComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcButtonComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcAvatarComponent.php';

$fw = dirname(__DIR__, 3) . '/framework';
require_once $fw . '/Core/ScrollManager.php';
require_once $fw . '/Styling/Adapter/PlatformStyling.php';
require_once $fw . '/Styling/Adapter/Win32Styling.php';
require_once $fw . '/Styling/Adapter/PlatformAdapter.php';

// Mock 渲染上下文
class _GridDiagMockRenderContext extends RenderContext
{
    public int $frameCount = 0;
    public function beginFrame(): void { $this->frameCount++; }
    public function endFrame(): void {}
    public function drawElement(array $el): void {}
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

// Mock 平台
class _GridDiagMockPlatform implements Platform
{
    public _GridDiagMockRenderContext $renderContext;
    public bool $shouldClose = false;
    
    public function __construct() {
        $this->renderContext = new _GridDiagMockRenderContext();
    }
    public function init(string $title, int $width, int $height): RenderContext {
        return new _GridDiagMockRenderContext();
    }
    public function shutdown(): void {}
    public function pollEvents(): array { return []; }
    public function setTimer(callable $callback, int $ms): void {}
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $type): void {}
    public function getHwnd(): int { return 0; }
    public function shouldClose(): bool { return $this->shouldClose; }
}

use Px\Theme\ThemeProvider;
use Px\Theme\ThemeData;

echo "==========================================================\n";
echo " Grid 最终诊断 — Application 级别\n";
echo "==========================================================\n\n";

// 初始化主题
$baseTheme = ThemeData::light();
ThemeProvider::inject($baseTheme);

// 创建应用（使用 mock platform）
$platform = new _GridDiagMockPlatform();
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);

$root = new AppComponent();
$app->mount($root, realpath(__DIR__ . '/../../apps/bilibili'));

// 注册所有组件 class styles
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

// Render 一次（这会触发 mount → onMount → markDirty → 微任务）
echo "--- Frame 1: 第一次 render() ---\n";

// 使用反射调用私有方法 render()
$renderMethod = new \ReflectionMethod($app, 'render');
$renderMethod->setAccessible(true);

// doFirstRender 的简化版
$renderMethod->invoke($app);
echo "  Frame 1 render complete\n";

// 刷新微任务
$scheduler->flushMicrotasks();

echo "  Microtasks flushed\n";

// 检查是否有第二次渲染请求
$renderRequestedProp = new \ReflectionProperty($app, 'renderRequested');
$renderRequestedProp->setAccessible(true);
$renderRequested = $renderRequestedProp->getValue($app);

echo "  renderRequested: " . ($renderRequested ? 'yes' : 'no') . "\n";

if ($renderRequested) {
    $renderRequestedProp->setValue($app, false);
    
    echo "\n--- Frame 2: 第二次 render() (triggered by dirty) ---\n";
    $renderMethod->invoke($app);
    echo "  Frame 2 render complete\n";
}

// 获取渲染树管理器
$rtm = $app->getRenderTreeManager();
$rootRN = $rtm->getRootRenderNode();

echo "\n--- 渲染树分析 ---\n";

// 递归查找 grid RenderNode
function findGridRNInTree($node, $depth=0) {
    $indent = str_repeat('  ', $depth);
    $dsp = $node->style['display'] ?? '';
    if ($dsp === 'grid') {
        $childCount = count($node->children);
        echo "${indent}FOUND GRID: {$node->x},{$node->y} {$node->w}x{$node->h} children={$childCount}\n";
        if ($childCount > 0) {
            foreach ($node->children as $i => $ch) {
                echo "${indent}  child[{$i}]: type={$ch->type} {$ch->w}x{$ch->h}\n";
            }
        } else {
            echo "${indent}  *** WARNING: Grid has NO children! ***\n";
        }
        return true;
    }
    foreach ($node->children as $ch) {
        if (findGridRNInTree($ch, $depth+1)) return true;
    }
    return false;
}

if ($rootRN !== null) {
    $found = findGridRNInTree($rootRN);
    if (!$found) {
        echo "  *** WARNING: Grid RN not found in tree! ***\n";
    }
    
    echo "\n--- 完整渲染树 (部分) ---\n";
    echo $rtm->dumpRenderTree($rootRN, 0, [], 'minimal');
} else {
    echo "  *** WARNING: Root RN is null! ***\n";
}

echo "\n==========================================================\n";
echo " 诊断完成\n";
echo "==========================================================\n";
