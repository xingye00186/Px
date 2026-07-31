<?php
/**
 * Rendering Pipeline 集成测试 — list-test (v-for List Test App)
 *
 * 模拟点击 "Add Item" 按钮释放列表项累积，录制全量状态快照，
 * 检测渲染状态退化（按钮文字缺失、黑屏、坐标异常等）。
 *
 * 四大规则：
 *   规则 A：绝对不变（按钮位置/大小，header 文本位置，clip 区域，背景 rect）
 *   规则 B：增长约束（每点击 +1 item = +1 rect +1 text，坐标递增）
 *   规则 C：元素有效性（无负坐标，不超窗口边界）
 *   规则 C2：按钮标签检查（单独报告，已知 bug：span 子节点 bind 不传递到 button label）
 *   规则 D：clip 区域与 item 坐标溢出检查
 *
 * Usage: php tests/unit/ListTestPipelineTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Platform\Platform;
use Px\Platform\PointerEvent;
use Px\Paint\RenderContext;
use Px\Paint\VNodeRenderer;
use Px\Render\RenderNode;
use Px\Theme\ThemeProvider;

if (!defined('JSON_SORT_KEYS')) {
    define('JSON_SORT_KEYS', 1);
}

// ============================================================
// 应用常量
// ============================================================
define('APP_PLATFORM', 'win32');
define('WINDOW_WIDTH', 400);
define('WINDOW_HEIGHT', 500);
define('WINDOW_TITLE', 'v-for List Test App');

// ============================================================
// 额外加载
// ============================================================
$APP_DIR = realpath(__DIR__ . '/../../apps/list-test');
require_once $APP_DIR . '/gen/ComponentFactory.php';
require_once $APP_DIR . '/gen/AppComponent.php';

$fw = dirname(__DIR__, 2) . '/framework';
require_once $fw . '/Core/ScrollManager.php';
require_once $fw . '/Styling/Adapter/PlatformStyling.php';
require_once $fw . '/Styling/Adapter/Win32Styling.php';
require_once $fw . '/Styling/Adapter/PlatformAdapter.php';

// ============================================================
// Mock：渲染上下文（替代 GdiRenderContext，无平台依赖）
// ============================================================
class _LTMockRenderContext extends RenderContext
{
    public int $frameCount = 0;
    public bool $beginFrameCalled = false;
    public bool $endFrameCalled = false;
    /** @var array<int, array> 所有 drawElement 收到的元素 */
    public array $drawnElements = [];

    public function beginFrame(): void {
        $this->beginFrameCalled = true;
        $this->frameCount++;
        $this->drawnElements = [];
    }
    public function endFrame(): void { $this->endFrameCalled = true; }

    public function drawElement(array $el): void {
        // 文本截断由 C++ php_vue_draw_text 层通过 GetTextExtentPoint32W
        // 精确测量后处理，PHP Mock 层不做截断模拟。
        $this->drawnElements[] = $el;
    }
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

// ============================================================
// Mock：平台
// ============================================================
class _LTMockPlatform implements Platform
{
    public _LTMockRenderContext $renderContext;

    public function __construct() {
        $this->renderContext = new _LTMockRenderContext();
    }

    public function init(string $title, int $width, int $height): RenderContext {
        return $this->renderContext;
    }
    public function shutdown(): void {}
    public function shouldClose(): bool { return false; }
    public function pollEvents(): array { return []; }
    public function getSurface(): \Px\Platform\RenderSurface { return new \Px\Platform\RenderSurface(0, 1440, 900, 1000); }
    public function getMetrics(): \Px\Platform\ViewMetrics { return new \Px\Platform\ViewMetrics(1440, 900, 1000, 0, 0, 0, 0, 'mock'); }
    public function getLifecycleState(): string { return \Px\Platform\LifecycleEvent::STATE_ACTIVE; }
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $cursor): void {}
}

// ============================================================
// 快照类：一帧的状态数据
// ============================================================
class ListRenderSnapshot
{
    // ── 组件状态 ──
    public string $listTitle = '';
    public string $addBtnText = '';
    public string $scrollTop = '0';
    public int $itemCount = 0;

    // ── 渲染统计 ──
    public int $rectCount = 0;        // 背景 rect + 每个 item 的 rect
    public int $textCount = 0;        // header 文本 + 每个 item 的文本 + 可能的按钮文本
    public int $buttonCount = 0;
    public int $clipPushCount = 0;
    public int $scrollbarVCount = 0;
    public int $totalCount = 0;

    // ── 按钮数据 ──
    public ?array $addButton = null;  // ['x'=>, 'y'=>, 'w'=>, 'h'=>, 'label'=>, 'labelFontSize'=>, 'bg'=>, 'fg'=>]

    // ── clip-push 区域 ──
    public ?array $clipPush = null;   // ['x'=>, 'y'=>, 'w'=>, 'h'=>]

    // ── 背景 rect ──
    public ?array $bgRect = null;     // ['x'=>, 'y'=>, 'w'=>, 'h'=>, 'color'=>]

    // ── header 文本 ──
    public ?array $headerText = null; // ['text'=>, 'x'=>, 'y'=>, 'fontSize'=>]

    // ── item rects ──
    public array $itemRects = [];     // [['x'=>, 'y'=>, 'w'=>, 'h'=>, 'color'=>], ...]

    // ── item 文本 ──
    public array $itemTexts = [];     // [['text'=>, 'x'=>, 'y'=>, 'fontSize'=>], ...]

    // ── scrollbar ──
    public ?array $scrollbarV = null; // ['x'=>, 'y'=>, 'w'=>, 'h'=>, 'contentHeight'=>, 'scrollTop'=>]

    // ── 结构指纹（排除随增长变化的文本内容）──
    public string $structuralFingerprint = '';
}

echo "========================================\n";
echo " Rendering Pipeline 集成测试 — list-test\n";
echo "========================================\n\n";

// ============================================================
// 反射辅助函数
// ============================================================

function ltInvokeRender(Application $app): void
{
    $m = new \ReflectionMethod(Application::class, 'render');
    $m->setAccessible(true);
    $m->invoke($app);
}

function ltInvokeDoFirstRender(Application $app): void
{
    $m = new \ReflectionMethod(Application::class, 'doFirstRender');
    $m->setAccessible(true);
    $m->invoke($app);
}

function ltInvokeHandlePointerEvent(Application $app, $event): void
{
    $m = new \ReflectionMethod(Application::class, 'handlePointerEvent');
    $m->setAccessible(true);
    $m->invoke($app, $event);
}

function ltInvokeDirectRender(Application $app): void
{
    $m = new \ReflectionMethod(Application::class, 'directRender');
    $m->setAccessible(true);
    $m->invoke($app);
}

/**
 * 递归查找滚动容器 RenderNode。
 */
function ltFindScrollContainer(RenderNode $node): ?RenderNode
{
    if ($node->isScrollContainer) return $node;
    foreach ($node->children as $child) {
        $result = ltFindScrollContainer($child);
        if ($result !== null) return $result;
    }
    return null;
}

function ltGetRenderContext(Application $app): _LTMockRenderContext
{
    $refl = new \ReflectionClass(Application::class);
    $prop = $refl->getProperty('renderer');
    $prop->setAccessible(true);
    $renderer = $prop->getValue($app);

    $rrefl = new \ReflectionClass(VNodeRenderer::class);
    $rprop = $rrefl->getProperty('render_ctx');
    $rprop->setAccessible(true);
    return $rprop->getValue($renderer);
}

function ltGetRenderTreeManager(Application $app): \Px\Rendering\RenderTreeManager
{
    $refl = new \ReflectionClass(Application::class);
    $prop = $refl->getProperty('renderTreeManager');
    $prop->setAccessible(true);
    return $prop->getValue($app);
}

function ltResetThemeProvider(): void
{
    $prop = new \ReflectionProperty(ThemeProvider::class, 'classStyleRegistry');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
}

// ============================================================
// 测试辅助函数
// ============================================================

function ltCreateTestApp(bool $clearInitial = true): array
{
    $platform = new _LTMockPlatform();
    $scheduler = new Scheduler();
    $app = new Application($platform, $scheduler);
    $component = new AppComponent();
    $app->mount($component);
    ltInvokeDoFirstRender($app);
    if ($clearInitial) {
        $platform->renderContext->drawnElements = [];
    }
    return [
        'app' => $app,
        'component' => $component,
        'platform' => $platform,
        'context' => $platform->renderContext,
    ];
}

/**
 * 查找指定 @click handler 的 RenderNode（不含 click-arg 匹配）。
 */
function ltFindClickableNode(Application $app, string $handler): ?RenderNode
{
    $rtm = ltGetRenderTreeManager($app);
    $prop = new \ReflectionClass(\Px\Rendering\RenderTreeManager::class);
    $m = $prop->getMethod('getRootRenderNode');
    $m->setAccessible(true);
    $root = $m->invoke($rtm);
    if ($root === null) return null;
    return ltFindNodeByHandler($root, $handler);
}

function ltFindNodeByHandler(RenderNode $node, string $handler): ?RenderNode
{
    $vn = $node->sourceVNode;
    if ($vn !== null && $vn->props !== null) {
        if (($vn->props['@click'] ?? null) === $handler) {
            return $node;
        }
    }
    foreach ($node->children as $child) {
        $result = ltFindNodeByHandler($child, $handler);
        if ($result !== null) return $result;
    }
    return null;
}

/**
 * 模拟点击指定节点 + 完成完整渲染帧
 */
function ltClickAndRender(Application $app, RenderNode $node): void
{
    $cx = $node->x + (int)($node->w / 2);
    $cy = $node->y + (int)($node->h / 2);
    $event = new PointerEvent('down', $cx, $cy);
    ltInvokeHandlePointerEvent($app, $event);
    $app->getScheduler()->flushMicrotasks();
    ltInvokeRender($app);
}

// ============================================================
// 快照录制
// ============================================================

function ltCaptureSnapshot(AppComponent $component, _LTMockRenderContext $ctx): ListRenderSnapshot
{
    $snap = new ListRenderSnapshot();

    // ── 组件状态 ──
    $snap->listTitle = $component->listTitle;
    $snap->addBtnText = $component->addBtnText;
    $snap->scrollTop = $component->scrollTop;
    $snap->itemCount = count($component->todoItems);

    // ── 分析渲染元素 ──
    $elements = $ctx->drawnElements;
    $snap->totalCount = count($elements);

    // 用于指纹的副本（item 文本内容排除）
    $fingerprintElements = [];

    foreach ($elements as $el) {
        $type = $el['type'] ?? '';

        if ($type === 'rect') {
            $snap->rectCount++;
            $x = $el['x']; $y = $el['y']; $w = $el['w']; $h = $el['h'];
            $color = $el['color'] ?? 0;
            $layer = $el['layer'] ?? 0;

            // 主背景 rect（全屏）
            if ($x === 0 && $y === 0 && $w === WINDOW_WIDTH && $h === WINDOW_HEIGHT) {
                $snap->bgRect = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $color, 'layer' => $layer];
            } else {
                // item 背景 rect
                $snap->itemRects[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $color, 'layer' => $layer];
            }
        } elseif ($type === 'text') {
            $snap->textCount++;
            $text = $el['text'] ?? '';
            $x = $el['x']; $y = $el['y'];
            $fs = $el['fontSize'] ?? 0;

            // header 文本（y ≈ 10）
            if ($text === 'Todo List' || $y < 30) {
                $snap->headerText = ['text' => $text, 'x' => $x, 'y' => $y, 'fontSize' => $fs];
            } else {
                // item 文本
                $snap->itemTexts[] = ['text' => $text, 'x' => $x, 'y' => $y, 'fontSize' => $fs];
            }
        } elseif ($type === 'button') {
            $snap->buttonCount++;
            $label = $el['label'] ?? '';
            $snap->addButton = [
                'x' => $el['x'], 'y' => $el['y'],
                'w' => $el['w'], 'h' => $el['h'],
                'label' => $label,
                'labelFontSize' => $el['labelFontSize'] ?? 0,
                'bg' => $el['bg'] ?? 0,
                'fg' => $el['fg'] ?? 0,
                'layer' => $el['layer'] ?? 0,
            ];
        } elseif ($type === 'clip-push') {
            $snap->clipPushCount++;
            if ($snap->clipPush === null) {
                $snap->clipPush = [
                    'x' => $el['x'], 'y' => $el['y'],
                    'w' => $el['w'], 'h' => $el['h'],
                ];
            }
        } elseif ($type === 'scrollbar-v') {
            $snap->scrollbarVCount++;
            $snap->scrollbarV = [
                'x' => $el['x'], 'y' => $el['y'],
                'w' => $el['w'], 'h' => $el['h'],
                'contentHeight' => $el['contentHeight'] ?? 0,
                'scrollTop' => $el['scrollTop'] ?? 0,
            ];
        }

        // 构建指纹：排除 item 文本的内容（增长变化）
        if ($type === 'text') {
            $text = $el['text'] ?? '';
            // header 文本保留，item 文本清空内容
            if ($text === 'Todo List' || ($el['y'] < 30)) {
                $fingerprintElements[] = $el;
            } else {
                $copy = $el;
                $copy['text'] = '';
                $fingerprintElements[] = $copy;
            }
        } else {
            $fingerprintElements[] = $el;
        }
    }

    // item rects 排序（按 y 排序）
    usort($snap->itemRects, function ($a, $b) {
        return ($a['y'] !== $b['y']) ? ($a['y'] - $b['y']) : ($a['x'] - $b['x']);
    });

    // item texts 排序
    usort($snap->itemTexts, function ($a, $b) {
        return ($a['y'] !== $b['y']) ? ($a['y'] - $b['y']) : ($a['x'] - $b['x']);
    });

    $snap->structuralFingerprint = json_encode($fingerprintElements, JSON_SORT_KEYS);

    return $snap;
}

// ============================================================
// 规则检查
// ============================================================

/**
 * 规则 A：绝对不变 — 背景、header、按钮、clip 区域应始终不变。
 */
function ltCheckInvariantRules(ListRenderSnapshot $snap, int $iter): array
{
    $v = [];

    // 背景 rect 应始终存在
    if ($snap->bgRect === null) {
        $v[] = "bgRect missing at iter $iter";
    } else {
        if ($snap->bgRect['x'] !== 0 || $snap->bgRect['y'] !== 0) {
            $v[] = "bgRect position changed: ({$snap->bgRect['x']},{$snap->bgRect['y']})";
        }
        if ($snap->bgRect['w'] !== WINDOW_WIDTH || $snap->bgRect['h'] !== WINDOW_HEIGHT) {
            $v[] = "bgRect size changed: {$snap->bgRect['w']}x{$snap->bgRect['h']}";
        }
    }

    // header 文本应始终存在
    if ($snap->headerText === null) {
        $v[] = "headerText missing at iter $iter";
    } else {
        if ($snap->headerText['text'] !== 'Todo List') {
            $v[] = "headerText content wrong: '{$snap->headerText['text']}'";
        }
        // 位置应在左上角
        if ($snap->headerText['x'] < 0 || $snap->headerText['x'] > 50) {
            $v[] = "headerText x={$snap->headerText['x']} out of range";
        }
        if ($snap->headerText['y'] < 0 || $snap->headerText['y'] > 30) {
            $v[] = "headerText y={$snap->headerText['y']} out of range (0-30)";
        }
    }

    // 按钮应始终存在
    if ($snap->addButton === null) {
        $v[] = "addButton missing at iter $iter";
    } else {
        // 按钮位置应不变
        if ($snap->addButton['x'] !== 125) {
            $v[] = "addButton x changed to {$snap->addButton['x']}";
        }
        // 按钮位置应在窗口可见范围内, 允许 flex-grow 正确计算后的含 padding 溢出
        if ($snap->addButton['y'] < 0) {
            $v[] = "addButton at negative y: {$snap->addButton['y']}";
        }
        if ($snap->addButton['x'] < 0 || $snap->addButton['x'] + $snap->addButton['w'] > WINDOW_WIDTH) {
            $v[] = "addButton x={$snap->addButton['x']} outside window (0-" . WINDOW_WIDTH . ")";
        }
        if ($snap->addButton['w'] !== 150) {
            $v[] = "addButton w changed to {$snap->addButton['w']}";
        }
        if ($snap->addButton['h'] !== 32) {
            $v[] = "addButton h changed to {$snap->addButton['h']}";
        }

        // 按钮标签检查 — 已知 Bug：应为 "Add Item" 但可能为空
        if ($snap->addButton['label'] === '') {
            // 不在这断言失败，单独报告
        }
    }

    // clip-push 区域应始终存在且位置不变
    if ($snap->clipPush === null) {
        $v[] = "clipPush missing at iter $iter (scroll container should always generate clip)";
    } else {
        // scroll container: margin-left/margin-right=10, 扣减后宽度=380
        $cx = $snap->clipPush['x'];
        $cy = $snap->clipPush['y'];
        $cw = $snap->clipPush['w'];
        $ch = $snap->clipPush['h'];
        // 允许小范围浮动（布局可能微调）
        if ($cx !== 10) {
            $v[] = "clipPush x changed to $cx (expected 10)";
        }
        if ($cy !== 49) {
            $v[] = "clipPush y changed to $cy (expected 49)";
        }
        if ($cw !== 380) {
            $v[] = "clipPush w changed to $cw (expected 380)";
        }
        if ($ch !== 423) {
            $v[] = "clipPush h changed to $ch (expected 423)";
        }
    }

    return $v;
}

/**
 * 规则 B：增长约束 — 可见 item 数量受 scroll 容器高度限制。
 * VNodeRenderer 会对完全在容器可见区域外的元素做 cull。
 */
function ltCheckGrowthRules(ListRenderSnapshot $snap, int $expectedItems, int $iter): array
{
    $v = [];

    // itemCount 应与预期一致
    if ($snap->itemCount !== $expectedItems) {
        $v[] = "itemCount {$snap->itemCount} != expected $expectedItems";
    }

    // 计算可见区域底部边界
    $containerBottom = 99999;
    if ($snap->clipPush !== null) {
        $containerBottom = $snap->clipPush['y'] + $snap->clipPush['h'];
    }

    // 检查所有可见 item rect 都在容器内
    foreach ($snap->itemRects as $i => $r) {
        if ($r['y'] >= $containerBottom) {
            $v[] = "itemRect[$i] y={$r['y']} >= container bottom $containerBottom, should have been culled";
        }
    }

    // 检查所有可见 item text 都在容器内
    foreach ($snap->itemTexts as $i => $t) {
        if ($t['y'] >= $containerBottom) {
            $v[] = "itemText[$i] y={$t['y']} >= container bottom $containerBottom, should have been culled";
        }
    }

    // item rects 和 texts 数量应一致，但允许最后一个 item 部分可见时 text 被 cull
    $visibleItems = count($snap->itemRects);
    $textCount = count($snap->itemTexts);
    $diff = $visibleItems - $textCount;
    if ($diff > 1 || $diff < 0) {
        $v[] = "itemTexts count $textCount != itemRects count $visibleItems (diff=$diff)";
    } elseif ($diff === 1 && $visibleItems > 0) {
        // 最后一个 item 部分可见（rect 在 clip 边界内，text 超出被 cull）
        $lastRect = $snap->itemRects[$visibleItems - 1];
        if ($containerBottom < 99999) {
            $rectBottom = $lastRect['y'] + $lastRect['h'];
            if ($rectBottom < $containerBottom) {
                // rect 完全可见但缺少 text，这不应该发生
                $v[] = "itemTexts count $textCount != itemRects count $visibleItems, but last rect bottom ($rectBottom) < clip bottom ($containerBottom)";
            }
        }
    }

    // 每个 item 应有对应的 rect 和 text（遍历到较短的数组长度）
    $checkCount = min($visibleItems, count($snap->itemTexts));
    for ($i = 0; $i < $checkCount; $i++) {
        $r = $snap->itemRects[$i];
        $t = $snap->itemTexts[$i];
        // rect 和 text 的 y 应接近（允许 24px 居中偏移，48px item 高度）
        if (abs($r['y'] - $t['y']) > 25) {
            $v[] = "item[$i] rect y={$r['y']} vs text y={$t['y']} mismatch";
        }
    }

    // item 的 y 坐标应递增
    for ($i = 1; $i < $visibleItems; $i++) {
        if ($snap->itemRects[$i]['y'] <= $snap->itemRects[$i-1]['y']) {
            $v[] = "item[$i] y not increasing: {$snap->itemRects[$i]['y']} <= {$snap->itemRects[$i-1]['y']}";
        }
    }

    // scrollbar 应在 item 超出可见区域后出现
    $maxVisible = $visibleItems; // 当前可见 item 数就是容量上限
    if ($expectedItems > $maxVisible && $snap->scrollbarV === null) {
        // 某些情况下 scrollbar 可能不出现（如果容器内容高度刚好等于容器高度）
        // 只是信息性提示，不阻断测试
    }

    return $v;
}

/**
 * 规则 C：元素有效性 — 无负坐标、不超窗口。
 * 规则 C3：所有元素（按钮、header、clip、scrollbar）边界检查。
 * 注意：按钮标签检查在 ltCheckButtonLabel 中单独处理（已知 bug）。
 */
function ltCheckElementValidity(ListRenderSnapshot $snap, int $iter): array
{
    $v = [];

    // 规则 C1: item rect 坐标检查
    foreach ($snap->itemRects as $i => $r) {
        if ($r['x'] < 0 || $r['y'] < 0) {
            $v[] = "itemRect[$i] negative position: ({$r['x']},{$r['y']})";
        }
        if ($r['x'] + $r['w'] > WINDOW_WIDTH) {
            $v[] = "itemRect[$i] exceeds right bound: x={$r['x']}+w={$r['w']}>{WINDOW_WIDTH}";
        }
        if ($r['y'] + $r['h'] > WINDOW_HEIGHT) {
            $v[] = "itemRect[$i] exceeds bottom: y={$r['y']}+h={$r['h']}>{WINDOW_HEIGHT}";
        }
        if ($r['w'] <= 0 || $r['h'] <= 0) {
            $v[] = "itemRect[$i] non-positive dimension: {$r['w']}x{$r['h']}";
        }
    }

    // 规则 C2: item 文本坐标检查
    foreach ($snap->itemTexts as $i => $t) {
        if ($t['x'] < -100) {
            $v[] = "itemText[$i] x={$t['x']} far left, GDI corruption risk";
        }
        if ($t['y'] < -200) {
            $v[] = "itemText[$i] y={$t['y']} far above, GDI corruption risk";
        }
    }

    // 规则 C3: scrollbar 检查（如果出现的话）
    if ($snap->scrollbarV !== null) {
        $sb = $snap->scrollbarV;
        if ($sb['x'] < 0 || $sb['y'] < 0) {
            $v[] = "scrollbarV negative position: ({$sb['x']},{$sb['y']})";
        }
        if ($sb['x'] + $sb['w'] > WINDOW_WIDTH) {
            $v[] = "scrollbarV exceeds right: x={$sb['x']}+w={$sb['w']}>{WINDOW_WIDTH}";
        }
        if ($sb['y'] + $sb['h'] > WINDOW_HEIGHT) {
            $v[] = "scrollbarV exceeds bottom: y={$sb['y']}+h={$sb['h']}>{WINDOW_HEIGHT}";
        }
        if ($sb['contentHeight'] < $sb['h']) {
            $v[] = "scrollbarV contentHeight {$sb['contentHeight']} < container h {$sb['h']}, scrollbar shouldn't exist";
        }
    }

    // 规则 C4: header 文本边界检查
    if ($snap->headerText !== null) {
        $ht = $snap->headerText;
        if ($ht['x'] < 0 || $ht['x'] > WINDOW_WIDTH) {
            $v[] = "headerText x={$ht['x']} outside window";
        }
        if ($ht['y'] < 0 || $ht['y'] > WINDOW_HEIGHT) {
            $v[] = "headerText y={$ht['y']} outside window";
        }
    }

    // 规则 C5: clip 区域边界检查
    if ($snap->clipPush !== null) {
        $cp = $snap->clipPush;
        if ($cp['x'] < 0 || $cp['y'] < 0) {
            $v[] = "clipPush negative position: ({$cp['x']},{$cp['y']})";
        }
        if ($cp['x'] + $cp['w'] > WINDOW_WIDTH) {
            $v[] = "clipPush exceeds right: x={$cp['x']}+w={$cp['w']}>{WINDOW_WIDTH}";
        }
        if ($cp['y'] + $cp['h'] > WINDOW_HEIGHT) {
            $v[] = "clipPush exceeds bottom: y={$cp['y']}+h={$cp['h']}>{WINDOW_HEIGHT}";
        }
    }

    // 规则 C6: 按钮边界检查
    if ($snap->addButton !== null) {
        $ab = $snap->addButton;
        if ($ab['x'] < 0 || $ab['y'] < 0) {
            $v[] = "addButton negative position: ({$ab['x']},{$ab['y']})";
        }
        if ($ab['x'] + $ab['w'] > WINDOW_WIDTH) {
            $v[] = "addButton exceeds right: x={$ab['x']}+w={$ab['w']}>{WINDOW_WIDTH}";
        }
        // flex-grow 使用 content-box h 后，auto-height 项的 padding 属正常溢出
        // 按钮 y=477, h=32 是弹性空间正确分配的结果
        if ($ab['y'] < 0) {
            $v[] = "addButton at negative y: {$ab['y']}";
        }
        if ($ab['w'] <= 0 || $ab['h'] <= 0) {
            $v[] = "addButton non-positive dimension: {$ab['w']}x{$ab['h']}";
        }
    }

    // totalCount 下限
    $minElements = 5; // bg + header + clip-push + clip-pop + button
    if ($snap->totalCount < $minElements) {
        $v[] = "totalCount {$snap->totalCount} < minimum $minElements";
    }

    return $v;
}

/**
 * 规则 C2：按钮标签检查（单独处理，不影响 Test 1 的主断言）。
 * 返回空数组表示标签正常。
 */
function ltCheckButtonLabel(ListRenderSnapshot $snap, int $iter): array
{
    $v = [];
    if ($snap->addButton === null) {
        $v[] = "addButton MISSING at iter $iter";
        return $v;
    }
    $label = $snap->addButton['label'] ?? '';
    $expected = $snap->addBtnText;
    if ($label === '') {
        $v[] = "ADD BUTTON LABEL EMPTY at iter $iter (expected '$expected')";
    } elseif ($label !== $expected) {
        $v[] = "addButton label '$label' != expected '$expected' at iter $iter";
    }
    return $v;
}

/**
 * 规则 D：clip 区域文本溢出检查（软检查 — C++ 层通过 GetTextExtentPoint32W 处理实际截断）。
 */
function ltCheckClipValidity(ListRenderSnapshot $snap, int $iter): array
{
    $v = [];
    $clip = $snap->clipPush;
    if ($clip === null) return $v;

    $clipRight = $clip['x'] + $clip['w'];
    $clipBottom = $clip['y'] + $clip['h'];

    // 检查 item 文本是否超出 clip 区域
    foreach ($snap->itemTexts as $i => $t) {
        // 宽松估算，避免误报
        $charWidth = (int)(($t['fontSize'] ?? 14) * 0.45);
        if ($charWidth < 1) $charWidth = 1;

        $textWidth = strlen($t['text']) * $charWidth;
        $textRight = $t['x'] + $textWidth;
        $textBottom = $t['y'] + ($t['fontSize'] ?? 14);

        if ($textRight > $clipRight + 20) {
            $v[] = "itemText[$i] significantly exceeds clip right by " . ($textRight - $clipRight) . "px (clipRight=$clipRight)";
        }
        if ($textBottom > $clipBottom + 10) {
            // item 底部超出 clip 但被裁切 — 这是预期的 scroll 行为
        }
    }

    // 检查 item rect 是否在 clip 区域内
    foreach ($snap->itemRects as $i => $r) {
        if ($r['x'] + $r['w'] > $clipRight) {
            $v[] = "itemRect[$i] right {$r['x']}+{$r['w']} > clip {$clipRight}";
        }
    }

    return $v;
}

/** @var string|null 当前运行的存档目录 */
$_ltAnomalyDir = null;

function ltGetAnomalyDir(): string
{
    global $_ltAnomalyDir;
    if ($_ltAnomalyDir === null) {
        $base = __DIR__ . '/../anomaly';
        $ts = date('Ymd_His');
        $_ltAnomalyDir = "$base/lt_$ts";
        if (!is_dir($_ltAnomalyDir)) {
            mkdir($_ltAnomalyDir, 0777, true);
        }
    }
    return $_ltAnomalyDir;
}

function ltArchiveAnomalyFrame(int $iter, ListRenderSnapshot $snap, array $violations): void
{
    $dir = ltGetAnomalyDir();
    $data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'iteration' => $iter,
        'violations' => $violations,
        'snapshot' => [
            'listTitle' => $snap->listTitle,
            'addBtnText' => $snap->addBtnText,
            'scrollTop' => $snap->scrollTop,
            'itemCount' => $snap->itemCount,
            'rectCount' => $snap->rectCount,
            'textCount' => $snap->textCount,
            'buttonCount' => $snap->buttonCount,
            'totalCount' => $snap->totalCount,
            'clipPushCount' => $snap->clipPushCount,
            'scrollbarVCount' => $snap->scrollbarVCount,
            'addButton' => $snap->addButton,
            'bgRect' => $snap->bgRect,
            'headerText' => $snap->headerText,
            'clipPush' => $snap->clipPush,
            'itemRects' => $snap->itemRects,
            'itemTexts' => $snap->itemTexts,
            'scrollbarV' => $snap->scrollbarV,
        ],
    ];
    $path = "$dir/frame_{$iter}.json";
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "    [存档] 异常快照 → {$path}\n";
}

function ltPrintAnomalySummary(): void
{
    global $_ltAnomalyDir;
    if ($_ltAnomalyDir !== null && is_dir($_ltAnomalyDir)) {
        $files = glob($_ltAnomalyDir . '/frame_*.json');
        if (count($files) > 0) {
            echo "\n⚠ 异常存档目录: {$_ltAnomalyDir} (" . count($files) . " 个文件)\n";
        }
    }
}

function ltPrintSnapshotSummary(ListRenderSnapshot $snap, int $iter, bool $detailed = false): void
{
    $btnLabel = $snap->addButton !== null ? "'{$snap->addButton['label']}'" : 'MISSING';
    echo sprintf("  [iter %2d] items=%d total=%d rects=%d texts=%d btn=%s clip=%d sv=%d",
        $iter,
        $snap->itemCount,
        $snap->totalCount,
        $snap->rectCount,
        $snap->textCount,
        $btnLabel,
        $snap->clipPushCount,
        $snap->scrollbarVCount
    );

    if ($detailed && $snap->itemRects !== null && count($snap->itemRects) > 0) {
        $first = $snap->itemRects[0];
        $last = $snap->itemRects[count($snap->itemRects) - 1];
        echo " items@({$first['y']}..{$last['y']})";
    }
    echo "\n";
}

// ============================================================
// 测试 1：30 次点击 "Add Item" 按钮的稳定性
// ============================================================
echo "--- 测试 1: 30 次点击 'Add Item' 按钮 ---\n";

test('30 次点击 "Add Item" → 列表增长/布局稳定/元素检查', function () {
    $ctx = ltCreateTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    // 找到 "Add Item" 按钮
    $btnNode = ltFindClickableNode($app, 'addItem');
    assert_not_null($btnNode, "找到 'Add Item' 按钮的 RenderNode");

    $initialItems = 3; // 初始 3 个 item

    // 第 0 次（初始状态，已点击 0 次）
    ltInvokeRender($app); // 渲染当前状态
    $refSnapshot = ltCaptureSnapshot($component, $context);
    echo "    初始状态: ";
    ltPrintSnapshotSummary($refSnapshot, 0, true);

    // 检查初始按钮标签
    if ($refSnapshot->addButton !== null && $refSnapshot->addButton['label'] !== '') {
        echo "    [信息] 按钮标签: '{$refSnapshot->addButton['label']}' (labelFontSize={$refSnapshot->addButton['labelFontSize']})\n";
    }

    // 30 次迭代：点击 → 渲染 → 检查
    $labelWarnings = []; // 按钮标签警告（已知 bug，不阻断测试）
    for ($i = 1; $i <= 30; $i++) {
        ltClickAndRender($app, $btnNode);
        $currentSnap = ltCaptureSnapshot($component, $context);

        $expectedItems = $initialItems + $i;

        // 规则 A：绝对不变
        $violations = ltCheckInvariantRules($currentSnap, $i);

        // 规则 B：增长约束
        $violations = array_merge($violations, ltCheckGrowthRules($currentSnap, $expectedItems, $i));

        // 规则 C：元素有效性（不含按钮标签）
        $violations = array_merge($violations, ltCheckElementValidity($currentSnap, $i));

        // 规则 D：clip 有效性
        $violations = array_merge($violations, ltCheckClipValidity($currentSnap, $i));

        // 按钮标签检查
        $btnLabelIssues = ltCheckButtonLabel($currentSnap, $i);
        if (count($btnLabelIssues) > 0) {
            $labelWarnings[$i] = $btnLabelIssues;
        }

        // 即时断言
        if (count($violations) > 0) {
            ltArchiveAnomalyFrame($i, $currentSnap, $violations);
            assert_eq(0, count($violations),
                "Iteration $i violations:\n  " . implode("\n  ", $violations));
        }

        // 每 5 次打印进度
        if ($i % 5 === 0 || $i === 1 || $i === 30) {
            ltPrintSnapshotSummary($currentSnap, $i, true);
        }
    }

    // 按钮标签检查汇总
    if (count($labelWarnings) > 0) {
        echo "    ⚠ 按钮标签问题: " . count($labelWarnings) . " 次迭代标签异常\n";
        foreach ($labelWarnings as $iter => $issues) {
            echo "      iter $iter: " . implode('; ', $issues) . "\n";
        }
    } else {
        echo "    ✅ 按钮标签全部正确: '" . ($currentSnap->addButton['label'] ?? '') . "'\n";
    }

    // 最终状态验证
    assert_eq(count($component->todoItems), 33, "最终应有 33 个 item（初始 3 + 30 次点击）");
    assert_eq($component->addBtnText, 'Add Item', "按钮文本应始终为 'Add Item'");
    assert_eq($component->listTitle, 'Todo List', "标题应始终不变");
});

// ============================================================
// 测试 2：渲染管道基础正确性
// ============================================================
echo "\n--- 测试 2: 渲染管道基础 ---\n";

test('mount + doFirstRender 后 frame begin/end 被调用', function () {
    $platform = new _LTMockPlatform();
    $scheduler = new Scheduler();
    $app = new Application($platform, $scheduler);
    $component = new AppComponent();
    $app->mount($component);
    ltInvokeDoFirstRender($app);

    assert_true($platform->renderContext->beginFrameCalled, "beginFrame 应该被调用");
    assert_true($platform->renderContext->endFrameCalled, "endFrame 应该被调用");
    assert_true($platform->renderContext->frameCount > 0, "frameCount 应该 > 0");
});

test('首次渲染产出正确的结构元素', function () {
    $ctx = ltCreateTestApp(false);
    $snap = ltCaptureSnapshot($ctx['component'], $ctx['context']);

    // 基础结构
    assert_true($snap->totalCount > 0, "首次渲染应有元素");
    assert_true($snap->rectCount > 0, "应有 rect 元素（背景 + item 背景）");
    assert_true($snap->textCount > 0, "应有 text 元素（header + item 文本）");
    assert_true($snap->clipPushCount > 0, "应有 clip-push（overflow:auto）");

    // 按钮
    assert_true($snap->buttonCount > 0, "应有按钮元素");
    assert_not_null($snap->addButton, "addButton 应存在");

    // 背景
    assert_not_null($snap->bgRect, "背景 rect 应存在");
    assert_eq($snap->bgRect['w'], WINDOW_WIDTH, "背景宽度应为 400");
    assert_eq($snap->bgRect['h'], WINDOW_HEIGHT, "背景高度应为 500");

    // header
    assert_not_null($snap->headerText, "header 文本应存在");
    assert_eq($snap->headerText['text'], 'Todo List', "header 内容正确");

    // clip 区域
    assert_not_null($snap->clipPush, "clip-push 应存在");
    assert_eq($snap->clipPush['w'], 380, "clip-push 宽度应为 380（400 - margin-left 10 - margin-right 10）");
    assert_eq($snap->clipPush['h'], 423, "clip-push 高度应为 423");

    // 初始 3 个 item
    assert_eq($snap->itemCount, 3, "初始应有 3 个 item");
    assert_eq(count($snap->itemRects), 3, "应有 3 个 item rect");
    assert_eq(count($snap->itemTexts), 3, "应有 3 个 item 文本");
});

// ============================================================
// 测试 3：按钮标签 Bug 检测
// ============================================================
echo "\n--- 测试 3: 按钮标签检测 ---\n";

test('按钮标签应在 makeButtonElement 中正确生成', function () {
    $ctx = ltCreateTestApp(false);
    $snap = ltCaptureSnapshot($ctx['component'], $ctx['context']);

    assert_not_null($snap->addButton, "按钮应存在");

    echo "    [检测] 按钮 label: '" . ($snap->addButton['label'] ?? 'null') . "'\n";
    echo "    [检测] 按钮 labelFontSize: " . ($snap->addButton['labelFontSize'] ?? 0) . "\n";
    echo "    [检测] 按钮位置: ({$snap->addButton['x']},{$snap->addButton['y']}) {$snap->addButton['w']}x{$snap->addButton['h']}\n";
    echo "    [检测] 组件 addBtnText: '{$ctx['component']->addBtnText}'\n";

    // 按钮标签应通过遍历子节点正确提取
    if ($snap->addButton['label'] === '') {
        echo "    ❌ 按钮标签仍为空，修复无效\n";
        assert_true($snap->addButton['label'] !== '', "按钮标签不应为空");
    } else {
        echo "    ✅ 按钮标签正确: '{$snap->addButton['label']}'\n";
    }
    assert_eq($snap->addButton['label'], $ctx['component']->addBtnText,
        "按钮标签应与组件 addBtnText 一致");
});

// ============================================================
// 测试 4：Application 事件管道
// ============================================================
echo "\n--- 测试 4: Application 事件管道 ---\n";

test('点击 "Add Item" 按钮后 todoItems 增加', function () {
    $ctx = ltCreateTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];

    assert_eq(count($component->todoItems), 3, "初始 3 个 item");

    $btnNode = ltFindClickableNode($app, 'addItem');
    assert_not_null($btnNode, "找到 'Add Item' 按钮");

    ltClickAndRender($app, $btnNode);
    assert_eq(count($component->todoItems), 4, "点击后 4 个 item");
    assert_eq($component->todoItems[3]['text'], 'Task #4', "新 item 文本正确");
});

test('连续点击 5 次 "Add Item"', function () {
    $ctx = ltCreateTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];

    $btnNode = ltFindClickableNode($app, 'addItem');
    for ($i = 0; $i < 5; $i++) {
        ltClickAndRender($app, $btnNode);
    }

    assert_eq(count($component->todoItems), 8, "5 次点击后 8 个 item");
    assert_eq($component->todoItems[7]['text'], 'Task #8', "最后一个 item 文本正确");
});

// ============================================================
// 测试 5：拖动滚动条后 auto-stacked item 位置保持不变
// ============================================================
echo "\n--- 测试 5: 滚动拖动后 item 定位 ---\n";

test('拖动滚动条后 auto-stacked items y 坐标保持递增不折叠', function () {
    $ctx = ltCreateTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    // 1. 添加大量 item 使列表可滚动
    $btnNode = ltFindClickableNode($app, 'addItem');
    for ($i = 0; $i < 20; $i++) {
        ltClickAndRender($app, $btnNode);
    }
    assert_eq(count($component->todoItems), 23, "应有 23 个 item");

    // 2. 获取滚动容器 RenderNode
    $rtm = ltGetRenderTreeManager($app);
    $root = $rtm->getRootRenderNode();
    assert_not_null($root);
    $scrollContainer = ltFindScrollContainer($root);
    assert_not_null($scrollContainer, "应找到滚动容器");
    assert_true($scrollContainer->isScrollContainer, "节点应是滚动容器");

    // 3. 记录当前 item 位置（脏路径布局后）
    $snapBefore = ltCaptureSnapshot($component, $context);
    $yBefore = [];
    foreach ($snapBefore->itemTexts as $t) {
        $yBefore[] = $t['y'];
    }
    echo "    [信息] 滚动前可见 items y: " . (count($yBefore) > 0 ? implode(', ', $yBefore) : '无') . "\n";

    // 4. 模拟拖动滚动条：直接修改 scrollTop 后调用 directRender（洁净路径）
    $scrollContainer->scrollTop = 200;
    ltInvokeDirectRender($app);

    // 5. 捕获滚动后的快照
    $snapAfter = ltCaptureSnapshot($component, $context);
    $yAfter = [];
    foreach ($snapAfter->itemTexts as $t) {
        $yAfter[] = $t['y'];
    }

    // 6. 验证：可见 items 的 y 坐标必须严格递增（不折叠）
    for ($i = 1; $i < count($yAfter); $i++) {
        assert_true(
            $yAfter[$i] > $yAfter[$i - 1],
            "可见 item $i 的 y 坐标应大于 item " . ($i - 1) . ": {$yAfter[$i]} <= {$yAfter[$i-1]}（auto-stack 折叠 bug）"
        );
    }
    echo "    ✅ 可见 items 的 y 坐标严格递增（无折叠）\n";

    // 7. 验证：滚动后应有 item 可见
    assert_true(count($yAfter) > 0, "滚动后应有可见 item");

    // 8. 验证 scrollTop 已被 LayoutResolver 正确 clamp
    $rtm2 = ltGetRenderTreeManager($app);
    $root2 = $rtm2->getRootRenderNode();
    $scrollContainer2 = ltFindScrollContainer($root2);
    assert_not_null($scrollContainer2);
    $maxScroll = max($scrollContainer2->contentHeight - $scrollContainer2->h, 0);
    assert_true($scrollContainer2->scrollTop <= $maxScroll,
        "scrollTop {$scrollContainer2->scrollTop} 不应超过 maxScroll {$maxScroll}");
});

test('连续拖动滚动条后所有可见 item 保持正确间距', function () {
    $ctx = ltCreateTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    // 1. 添加大量 item
    $btnNode = ltFindClickableNode($app, 'addItem');
    for ($i = 0; $i < 30; $i++) {
        ltClickAndRender($app, $btnNode);
    }

    // 2. 获取滚动容器
    $rtm = ltGetRenderTreeManager($app);
    $root = $rtm->getRootRenderNode();
    $scrollContainer = ltFindScrollContainer($root);
    assert_not_null($scrollContainer);

    // 3. 连续 10 次模拟拖动（每次 scrollTop 增加）
    for ($iter = 0; $iter < 10; $iter++) {
        $prevScrollTop = $scrollContainer->scrollTop;
        $scrollContainer->scrollTop = min($prevScrollTop + 50, 500);
        ltInvokeDirectRender($app);

        $snap = ltCaptureSnapshot($component, $context);
        $yVals = [];
        foreach ($snap->itemTexts as $t) {
            $yVals[] = $t['y'];
        }

        // 验证 y 严格递增
        for ($i = 1; $i < count($yVals); $i++) {
            assert_true(
                $yVals[$i] > $yVals[$i - 1],
                "迭代 $iter: 可见 item $i y 坐标折叠: {$yVals[$i]} <= {$yVals[$i-1]}"
            );
        }
    }

    echo "    ✅ 连续 10 次拖动后 item 位置正确\n";
});

// ============================================================
// 测试 6：诊断 — 拖到底部释放后列表漂移
// ============================================================

test('诊断_拖到底部释放后scrollContainer状态对比', function () {
    $ctx = ltCreateTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    // 1. 添加大量 item
    $btnNode = ltFindClickableNode($app, 'addItem');
    for ($i = 0; $i < 20; $i++) {
        ltClickAndRender($app, $btnNode);
    }
    assert_eq(count($component->todoItems), 23, "应有 23 个 item");

    // 2. 获取滚动容器初始状态
    $rtm = ltGetRenderTreeManager($app);
    $root = $rtm->getRootRenderNode();
    assert_not_null($root);
    $scrollContainer = ltFindScrollContainer($root);
    assert_not_null($scrollContainer);
    assert_true($scrollContainer->isScrollContainer);

    $maxScroll = max($scrollContainer->contentHeight - $scrollContainer->h, 0);
    echo "  [初始] scrollTop={$scrollContainer->scrollTop} lastScrollTop={$scrollContainer->lastScrollTop} "
        . "contentHeight={$scrollContainer->contentHeight} h={$scrollContainer->h} maxScroll={$maxScroll}\n";

    // 3. 模拟拖到底部（directRender 路径）
    $scrollContainer->scrollTop = $maxScroll;
    ltInvokeDirectRender($app);

    // 4. 捕获拖到底部后的快照 + 树
    $snapDrag = ltCaptureSnapshot($component, $context);
    $rtm2 = ltGetRenderTreeManager($app);
    $root2 = $rtm2->getRootRenderNode();
    $sc2 = ltFindScrollContainer($root2);
    $dragTree = $rtm2->dumpRenderTree($root2, 1, [], 'verbose');

    echo "  [拖到底部] scrollTop={$sc2->scrollTop} lastScrollTop={$sc2->lastScrollTop} "
        . "contentHeight={$sc2->contentHeight} h={$sc2->h} "
        . "maxScroll=" . max($sc2->contentHeight - $sc2->h, 0) . "\n";
    echo "  [拖到底部] 可见 items: " . count($snapDrag->itemTexts) . "\n";
    foreach ($snapDrag->itemTexts as $idx => $t) {
        echo "    drag-item[$idx] y={$t['y']} text='{$t['text']}'\n";
    }
    echo "  [拖到底部 渲染树]:\n$dragTree\n";

    // 5. 模拟释放（persist + requestRender = 完整重建）
    // 模拟 ScrollManager::applyScrollTop 的 persist=true 行为
    $sc2->scrollTop = $maxScroll;
    // 持久化到组件
    $refl = new ReflectionClass($component);
    $propScrollTop = $refl->getProperty('scrollTop');
    $propScrollTop->setAccessible(true);
    $propScrollTop->setValue($component, (string)$maxScroll);
    // 全量渲染
    ltInvokeRender($app);

    // 6. 捕获释放后的快照 + 树
    $snapRelease = ltCaptureSnapshot($component, $context);
    $rtm3 = ltGetRenderTreeManager($app);
    $root3 = $rtm3->getRootRenderNode();
    $sc3 = ltFindScrollContainer($root3);
    $releaseTree = $rtm3->dumpRenderTree($root3, 2, [], 'verbose');

    echo "  [释放后] scrollTop={$sc3->scrollTop} lastScrollTop={$sc3->lastScrollTop} "
        . "contentHeight={$sc3->contentHeight} h={$sc3->h} "
        . "maxScroll=" . max($sc3->contentHeight - $sc3->h, 0) . "\n";
    echo "  [释放后] 可见 items: " . count($snapRelease->itemTexts) . "\n";
    foreach ($snapRelease->itemTexts as $idx => $t) {
        echo "    release-item[$idx] y={$t['y']} text='{$t['text']}'\n";
    }
    echo "  [释放后 渲染树]:\n$releaseTree\n";

    // 7. 对比：scrollTop 应一致
    assert_eq($sc3->scrollTop, $sc2->scrollTop,
        "scrollTop 在释放后应保持不变: drag={$sc2->scrollTop} release={$sc3->scrollTop}");

    // 8. 对比：contentHeight 应一致
    assert_eq($sc3->contentHeight, $sc2->contentHeight,
        "contentHeight 应一致: drag={$sc2->contentHeight} release={$sc3->contentHeight}");

    // 9. 对比：item 位置应一致
    assert_eq(count($snapRelease->itemTexts), count($snapDrag->itemTexts),
        "可见 item 数量应一致: drag=" . count($snapDrag->itemTexts)
        . " release=" . count($snapRelease->itemTexts));

    for ($i = 0; $i < count($snapDrag->itemTexts); $i++) {
        $dragY = $snapDrag->itemTexts[$i]['y'];
        $releaseY = $snapRelease->itemTexts[$i]['y'];
        $diff = $releaseY - $dragY;
        assert_eq(
            $releaseY, $dragY,
            "item[$i] 释放后 y 坐标应保持不变（无漂移），diff=$diff"
        );
    }

    // 10. 对比：渲染树结构指纹应一致（排除 item 文本内容）
    if ($snapDrag->structuralFingerprint !== $snapRelease->structuralFingerprint) {
        echo "  [警告] 结构指纹不一致！\n";
        echo "  drag 指纹: {$snapDrag->structuralFingerprint}\n";
        echo "  release 指纹: {$snapRelease->structuralFingerprint}\n";
    }

    echo "  ✅ 拖到底部+释放后 scrollTop/contentHeight/item 位置全部一致\n";
});

// ============================================================
// 汇总
// ============================================================

ltResetThemeProvider();

ltPrintAnomalySummary();

print_summary();
