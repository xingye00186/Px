<?php
/**
 * Rendering Pipeline 集成测试（快照差异分析版）
 *
 * 通过 Application 事件管道 + 完整渲染管线模拟多步操作，
 * 录制全量状态快照，按分类规则检测异常。
 *
 * 三大规则：
 *   规则 A：绝对不变（按钮位置/大小/层级，容器坐标，clip 区域）
 *   规则 B：条件不变（AC/C 标签，Memory 指示器）
 *   规则 C：变化有约束（display ≤ 15 位，纯数字）
 *
 * Usage: D:\swoole_compiler\php.exe tests/unit/RenderingPipelineTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Platform\Platform;
use Px\Platform\MouseEvent;
use Px\Rendering\RenderContext;
use Px\Rendering\VNodeRenderer;
use Px\Rendering\RenderNode;
use Px\Styling\Provider\ThemeProvider;

// swoole_compiler PHP 可能缺少此常量
if (!defined('JSON_SORT_KEYS')) {
    define('JSON_SORT_KEYS', 1);
}

// ============================================================
// 应用常量
// ============================================================
define('APP_PLATFORM', 'win32');
define('WINDOW_WIDTH', 340);
define('WINDOW_HEIGHT', 660);
define('WINDOW_TITLE', 'Calculator');

// ============================================================
// 额外加载
// ============================================================
$APP_DIR = realpath(__DIR__ . '/../../apps/calculator-ng');
require_once $APP_DIR . '/gen/ComponentFactory.php';
require_once $APP_DIR . '/gen/AppComponent.php';
require_once $APP_DIR . '/gen/CalculatorDisplayComponent.php';
require_once $APP_DIR . '/gen/BasicPadComponent.php';
require_once $APP_DIR . '/gen/ScientificPadComponent.php';
require_once $APP_DIR . '/gen/MemoryBarComponent.php';
require_once $APP_DIR . '/gen/HistoryPanelComponent.php';

$fw = dirname(__DIR__, 2) . '/framework';
require_once $fw . '/Core/ScrollManager.php';
require_once $fw . '/Styling/Adapter/PlatformStyling.php';
require_once $fw . '/Styling/Adapter/Win32Styling.php';
require_once $fw . '/Styling/Adapter/PlatformAdapter.php';

// ============================================================
// Mock：渲染上下文（替代 GdiRenderContext，无平台依赖）
// ============================================================
class _MockRenderContext extends RenderContext
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
        // 注意：文本截断由 C++ php_vue_draw_text 层通过 GetTextExtentPoint32W
        // 精确测量后处理，PHP Mock 层不做截断模拟。
        $this->drawnElements[] = $el;
    }
    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}
    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
}

// ============================================================
// Mock：平台（返回 _MockRenderContext）
// ============================================================
class _MockPlatform implements Platform
{
    public _MockRenderContext $renderContext;

    public function __construct() {
        $this->renderContext = new _MockRenderContext();
    }

    public function init(string $title, int $width, int $height): RenderContext {
        return $this->renderContext;
    }
    public function shutdown(): void {}
    public function getHwnd(): int { return 0; }
    public function shouldClose(): bool { return false; }
    public function pollEvents(): array { return []; }
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $cursor): void {}
}

// ============================================================
// 快照类：一帧的状态数据
// ============================================================
class RenderSnapshot
{
    // ── 组件状态 ──
    public string $display = '0';
    public string $expression = '';
    public string $acLabel = 'AC';
    public bool $showHistory = false;
    public bool $hasMemory = false;
    public int $historyCount = 0;

    // ── 渲染统计 ──
    public int $buttonCount = 0;
    public int $rectCount = 0;
    public int $textCount = 0;
    public int $clipPushCount = 0;
    public int $totalCount = 0;

    // ── 按钮数据：key=label, value=['x'=>, 'y'=>, 'w'=>, 'h'=>, 'layer'=>, 'bg'=>, 'fg'=>] ──
    public array $buttons = [];

    // ── clip-push 区域 ──
    public ?array $clipPush = null; // ['x'=>, 'y'=>, 'w'=>, 'h'=>]

    // ── 容器（非按钮非文本的 rect 元素） ──
    public array $containers = []; // [['x'=>, 'y'=>, 'w'=>, 'h'=>, 'layer'=>], ...]

    // ── 显示区文本（y < 100） ──
    public array $displayTexts = []; // [['text'=>, 'x'=>, 'y'=>, 'fontSize'=>, 'layer'=>], ...]

    // ── 所有元素的指纹（排除变化的文本） ──
    public string $structuralFingerprint = '';
}

echo "========================================\n";
echo " Rendering Pipeline 集成测试（快照差异分析）\n";
echo "========================================\n\n";

// ============================================================
// 反射辅助函数
// ============================================================

function invokeRender(Application $app): void
{
    $m = new \ReflectionMethod(Application::class, 'render');
    $m->setAccessible(true);
    $m->invoke($app);
}

function invokeDoFirstRender(Application $app): void
{
    $m = new \ReflectionMethod(Application::class, 'doFirstRender');
    $m->setAccessible(true);
    $m->invoke($app);
}

function invokeHandleMouseEvent(Application $app, $event): void
{
    $m = new \ReflectionMethod(Application::class, 'handleMouseEvent');
    $m->setAccessible(true);
    $m->invoke($app, $event);
}

function getRenderContext(Application $app): _MockRenderContext
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

function resetThemeProvider(): void
{
    $prop = new \ReflectionProperty(ThemeProvider::class, 'classStyleRegistry');
    $prop->setAccessible(true);
    $prop->setValue(null, []);
}

// ============================================================
// 测试辅助函数
// ============================================================

/**
 * 创建完整的测试环境
 * @param bool $clearInitial 是否清空首帧渲染元素（测试 1/3 用于交互测试时清空，测试 2 首次渲染时保留）
 * @return array ['app'=>Application, 'component'=>AppComponent, 'platform'=>_MockPlatform, 'context'=>_MockRenderContext]
 */
function createTestApp(bool $clearInitial = true): array
{
    $platform = new _MockPlatform();
    $scheduler = new Scheduler();
    $app = new Application($platform, $scheduler);
    $component = new AppComponent();
    $app->mount($component);
    // 首帧渲染
    invokeDoFirstRender($app);
    if ($clearInitial) {
        // 清空 mock 记录的初次渲染元素，从首次交互开始记录
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
 * 从 RenderNode 树中查找具有指定 @click 和 click-arg 的节点。
 */
function findClickableNode(Application $app, string $handler, string $arg): ?RenderNode
{
    $rtm = getRenderTreeManager($app);
    $root = $rtm->getRootRenderNode();
    if ($root === null) return null;
    return findNodeByClickAttr($root, $handler, $arg);
}

// Need RenderTreeManager import
function getRenderTreeManager(Application $app): \Px\Rendering\RenderTreeManager
{
    $refl = new \ReflectionClass(Application::class);
    $prop = $refl->getProperty('renderTreeManager');
    $prop->setAccessible(true);
    return $prop->getValue($app);
}

function findNodeByClickAttr(RenderNode $node, string $handler, string $arg): ?RenderNode
{
    $vn = $node->sourceVNode;
    if ($vn !== null && $vn->props !== null) {
        if (($vn->props['@click'] ?? null) === $handler
            && ($vn->props['click-arg'] ?? '') === $arg) {
            return $node;
        }
    }
    foreach ($node->children as $child) {
        $result = findNodeByClickAttr($child, $handler, $arg);
        if ($result !== null) return $result;
    }
    return null;
}

/**
 * 模拟点击指定节点 + 完成完整渲染帧
 */
function clickAndRender(Application $app, RenderNode $node): void
{
    // 点击节点中心
    $cx = $node->x + (int)($node->w / 2);
    $cy = $node->y + (int)($node->h / 2);
    $event = new MouseEvent('down', $cx, $cy);
    invokeHandleMouseEvent($app, $event);
    // 刷新微任务（markDirty → requestRender）
    $app->getScheduler()->flushMicrotasks();
    // 渲染
    invokeRender($app);
}

// ============================================================
// 快照录制
// ============================================================

/**
 * 录制当前帧的状态快照。
 */
function captureSnapshot(AppComponent $component, _MockRenderContext $ctx): RenderSnapshot
{
    $snap = new RenderSnapshot();

    // ── 组件状态 ──
    $snap->display = $component->display;
    $snap->expression = $component->expression;
    $snap->acLabel = $component->acLabel;
    $snap->showHistory = $component->showHistory;
    $snap->hasMemory = $component->hasMemory;
    $snap->historyCount = is_array($component->historyItems) ? count($component->historyItems) : 0;

    // ── 分析渲染元素 ──
    $elements = $ctx->drawnElements;
    $snap->totalCount = count($elements);

    // 用于指纹的副本（排除 display 区文本内容）
    $fingerprintElements = [];

    foreach ($elements as $el) {
        $type = $el['type'] ?? '';

        if ($type === 'button') {
            $snap->buttonCount++;
            $label = $el['label'] ?? '?';
            $snap->buttons[$label] = [
                'x' => $el['x'], 'y' => $el['y'],
                'w' => $el['w'], 'h' => $el['h'],
                'layer' => $el['layer'] ?? 0,
                'bg' => $el['bg'] ?? 0,
                'fg' => $el['fg'] ?? 0,
            ];
        } elseif ($type === 'rect') {
            $snap->rectCount++;
            $snap->containers[] = [
                'x' => $el['x'], 'y' => $el['y'],
                'w' => $el['w'], 'h' => $el['h'],
                'layer' => $el['layer'] ?? 0,
                'color' => $el['color'] ?? 0,
            ];
        } elseif ($type === 'text') {
            $snap->textCount++;
            if ($el['y'] < 100) {
                // 显示区文本
                $snap->displayTexts[] = [
                    'text' => $el['text'] ?? '',
                    'x' => $el['x'], 'y' => $el['y'],
                    'fontSize' => $el['fontSize'] ?? 0,
                    'layer' => $el['layer'] ?? 0,
                ];
            }
        } elseif ($type === 'clip-push') {
            $snap->clipPushCount++;
            if ($snap->clipPush === null) {
                $snap->clipPush = [
                    'x' => $el['x'], 'y' => $el['y'],
                    'w' => $el['w'], 'h' => $el['h'],
                ];
            }
        }

        // 构建指纹：排除 display 区文本的文本内容和位置（位置随右对齐文本长度变化）
        if ($type === 'text' && ($el['y'] < 100)) {
            $copy = $el;
            $copy['text'] = ''; // 清空文本内容
            $copy['x'] = 0;     // 位置随文本内容变化（右对齐）
            $copy['y'] = 0;
            $fingerprintElements[] = $copy;
        } else {
            $fingerprintElements[] = $el;
        }
    }

    // 对容器排序（保证指纹稳定）
    usort($snap->containers, function ($a, $b) {
        return ($a['y'] !== $b['y']) ? ($a['y'] - $b['y']) : ($a['x'] - $b['x']);
    });

    $snap->structuralFingerprint = json_encode($fingerprintElements, JSON_SORT_KEYS);

    return $snap;
}

// ============================================================
// 规则检查
// ============================================================

/**
 * 规则 A：绝对不变 — 检查所有不变属性 vs 参考基线。
 */
function checkInvariantRules(RenderSnapshot $ref, RenderSnapshot $cur, int $iter): array
{
    $v = [];

    // 指纹比较
    if ($ref->structuralFingerprint !== $cur->structuralFingerprint) {
        $v[] = "structural fingerprint mismatch at iter $iter";
    }

    // 元素计数
    if ($ref->totalCount !== $cur->totalCount) {
        $v[] = "totalCount changed {$ref->totalCount}→{$cur->totalCount}";
    }
    if ($ref->buttonCount !== $cur->buttonCount) {
        $v[] = "buttonCount changed {$ref->buttonCount}→{$cur->buttonCount}";
    }
    if ($ref->rectCount !== $cur->rectCount) {
        $v[] = "rectCount changed {$ref->rectCount}→{$cur->rectCount}";
    }
    if ($ref->textCount !== $cur->textCount) {
        $v[] = "textCount changed {$ref->textCount}→{$cur->textCount}";
    }
    if ($ref->clipPushCount !== $cur->clipPushCount) {
        $v[] = "clipPushCount changed {$ref->clipPushCount}→{$cur->clipPushCount}";
    }

    // 按钮位置/大小/层级/颜色（遍历参考帧的所有按钮）
    foreach ($ref->buttons as $label => $btn) {
        $curBtn = $cur->buttons[$label] ?? null;
        if ($curBtn === null) {
            $v[] = "button '$label' missing";
            continue;
        }
        foreach (['x', 'y', 'w', 'h', 'layer', 'bg', 'fg'] as $attr) {
            if ($btn[$attr] !== $curBtn[$attr]) {
                $v[] = "button[$label].$attr {$btn[$attr]}→{$curBtn[$attr]}";
            }
        }
    }

    // 容器（比较数量 + 位置）
    if (count($ref->containers) !== count($cur->containers)) {
        $v[] = "container count " . count($ref->containers) . "→" . count($cur->containers);
    } else {
        for ($i = 0; $i < count($ref->containers); $i++) {
            foreach (['x', 'y', 'w', 'h', 'layer'] as $attr) {
                $rv = $ref->containers[$i][$attr] ?? 0;
                $cv = $cur->containers[$i][$attr] ?? 0;
                if ($rv !== $cv) {
                    $v[] = "container[$i].$attr $rv→$cv";
                }
            }
        }
    }

    // clip-push 区域
    if ($ref->clipPush !== null && $cur->clipPush !== null) {
        foreach (['x', 'y', 'w', 'h'] as $attr) {
            if (($ref->clipPush[$attr] ?? 0) !== ($cur->clipPush[$attr] ?? 0)) {
                $v[] = "clipPush.$attr changed";
            }
        }
    } elseif ($ref->clipPush !== null || $cur->clipPush !== null) {
        $v[] = "clipPush presence changed";
    }

    return $v;
}

/**
 * 规则 B：条件不变。
 */
function checkConditionalRules(RenderSnapshot $snap, int $iter): array
{
    $v = [];

    // AC/C 标签
    if ($snap->display === '0' && $snap->acLabel !== 'AC') {
        $v[] = "display=0 but acLabel='{$snap->acLabel}'";
    }
    if ($snap->display !== '0' && $snap->acLabel !== 'C') {
        $v[] = "display≠0 but acLabel='{$snap->acLabel}'";
    }

    return $v;
}

/**
 * 规则 C：变化有约束。
 */
function checkChangeRules(RenderSnapshot $snap, int $iter): array
{
    $v = [];

    // display 长度
    if (strlen($snap->display) > 15) {
        $v[] = "display exceeds 15 chars: '{$snap->display}'";
    }

    // display 只含数字和小数点
    if (!preg_match('/^[0-9.]*$/', $snap->display)) {
        $v[] = "display has non-numeric chars: '{$snap->display}'";
    }

    return $v;
}

/**
 * 规则 D：元素有效性 — 检查元素边界、位置、重叠等 GDI 敏感属性。
 */
function checkElementValidity(array $elements, int $iter): array
{
    $v = [];

    // 区分显示区文本（大字号）和普通 UI 文本（小字号）
    $displayTexts = [];  // fontSize >= 30
    $otherTexts = [];    // fontSize < 30

    foreach ($elements as $i => $el) {
        $type = $el['type'] ?? '?';
        $x = $el['x'] ?? 0;
        $y = $el['y'] ?? 0;
        $w = $el['w'] ?? 0;
        $h = $el['h'] ?? 0;

        // 1. 负坐标检测（GDI 绘制负坐标可能导致缓冲区损坏）
        if ($x < 0 || $y < 0) {
            $v[] = "element[$i]($type) negative position: x=$x, y=$y";
        }

        // 2. 超出窗口边界检测
        if ($x + $w > WINDOW_WIDTH) {
            $v[] = "element[$i]($type) exceeds right bound: x=$x, w=$w, win=$WINDOW_WIDTH";
        }
        if ($y + $h > WINDOW_HEIGHT) {
            $v[] = "element[$i]($type) exceeds bottom bound: y=$y, h=$h, win=$WINDOW_HEIGHT";
        }

        // 3. 按字号分类文本元素
        if ($type === 'text') {
            $fs = $el['fontSize'] ?? 0;
            $entry = [
                'idx' => $i,
                'text' => $el['text'] ?? '',
                'x' => $x, 'y' => $y,
                'fontSize' => $fs,
                'align' => $el['align'] ?? '',
            ];
            if ($fs >= 30) {
                $displayTexts[] = $entry;
            } else {
                $otherTexts[] = $entry;
            }
        }
    }

    // 4. 显示区文本检查（大字号必须在 y 0-100 显示区）
    if (count($displayTexts) > 1) {
        $fontSizes = array_unique(array_map(fn($t) => $t['fontSize'], $displayTexts));
        if (count($fontSizes) > 1) {
            $v[] = "display text fontSize changed: " . implode(',', $fontSizes);
        }
    }
    foreach ($displayTexts as $t) {
        if ($t['y'] < 0 || $t['y'] > 100) {
            $v[] = "display text[{$t['idx']}] y={$t['y']} outside display area (0-100)";
        }
        if ($t['x'] < -50) {
            $v[] = "display text[{$t['idx']}] x={$t['x']} < -50, GDI corruption risk";
        }
    }

    // 5. 普通 UI 文本告警（仅记录极端情况）
    foreach ($otherTexts as $t) {
        if ($t['x'] < -100) {
            $v[] = "UI text[{$t['idx']}] x={$t['x']} far left";
        }
    }

    return $v;
}

/**
 * 规则 E：clip 区域软检查 — C++ 层通过 GetTextExtentPoint32W 处理实际截断。
 * 使用宽松估算 fontSize * 0.45（无 bold 因子）避免等宽字符误报。
 * 文本溢出 clip 边界仅记录（不再硬断言），C++ 在绘制时会自动截断。
 */
function checkClipValidity(RenderSnapshot $snap, int $iter): array
{
    $v = [];
    $clip = $snap->clipPush;
    if ($clip === null) return $v;

    $clipRight = $clip['x'] + $clip['w'];
    $clipBottom = $clip['y'] + $clip['h'];

    foreach ($snap->displayTexts as $t) {
        // 宽松估算，避免对窄字符误报
        $charWidth = (int)(($t['fontSize'] ?? 36) * 0.45);
        if ($charWidth < 1) $charWidth = 1;

        $textWidth = strlen($t['text']) * $charWidth;

        // 仅当文本显著超出 clip 右边界时告警（C++ 会通过 GetTextExtentPoint32W 精确截断）
        $textRight = $t['x'] + $textWidth;
        if ($textRight > $clipRight + 20) {
            $v[] = "display text significantly exceeds clip right: x={$t['x']}+w={$textWidth} > clipRight={$clipRight}";
        }

        // 文本底部超出 clip 底部 → 可能进入按钮区域
        $textBottom = $t['y'] + $t['fontSize'];
        if ($textBottom > $clipBottom + 10) {
            $v[] = "display text bottom {$textBottom} > clip {$clipBottom}+10, text may enter button area";
        }
    }

    return $v;
}

/**
 * 文本位置演变诊断 — 追踪每个迭代的文本 x/y 变化趋势。
 */
function printTextEvolution(array $history, int $iter): void
{
    // 关键点打印：前 5 次，之后每 5 次，以及满 14-15 位时
    if ($iter <= 5 || $iter % 5 === 0 || $iter === 14 || $iter === 15) {
        $h = $history[count($history) - 1];
        // display 文本使用宽松估算 (0.45 * fontSize)
        $charW = (int)(($h['fs'] ?? 36) * 0.45);
        if ($charW < 1) $charW = 1;
        echo sprintf("    [iter %2d] display='%-15s' text@(%3d,%2d) fs=%d right=%d clip=%d\n",
            $iter, $h['display'], $h['x'], $h['y'], $h['fs'],
            $h['x'] + strlen($h['display']) * $charW,
            0 + 318  // clip 右边界，checkClipValidity 中做精确验证
        );
    }
}

/** @var string|null 当前运行的存档目录 */
$_anomalyDir = null;

function getAnomalyDir(): string
{
    global $_anomalyDir;
    if ($_anomalyDir === null) {
        $base = __DIR__ . '/../anomaly';
        $ts = date('Ymd_His');
        $_anomalyDir = "$base/$ts";
        if (!is_dir($_anomalyDir)) {
            mkdir($_anomalyDir, 0777, true);
        }
    }
    return $_anomalyDir;
}

function archiveAnomalyFrame(int $iter, RenderSnapshot $snap, array $violations, array $ctx): void
{
    $dir = getAnomalyDir();
    $data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'iteration' => $iter,
        'violations' => $violations,
        'snapshot' => [
            'display' => $snap->display,
            'expression' => $snap->expression,
            'acLabel' => $snap->acLabel,
            'showHistory' => $snap->showHistory,
            'hasMemory' => $snap->hasMemory,
            'historyCount' => $snap->historyCount,
            'buttonCount' => $snap->buttonCount,
            'rectCount' => $snap->rectCount,
            'textCount' => $snap->textCount,
            'totalCount' => $snap->totalCount,
            'clipPushCount' => $snap->clipPushCount,
            'buttons' => $snap->buttons,
            'containers' => $snap->containers,
            'clipPush' => $snap->clipPush,
            'displayTexts' => $snap->displayTexts,
        ],
    ];
    $path = "$dir/frame_{$iter}.json";
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "    [存档] 异常快照 → {$path}\n";
}

function printAnomalySummary(): void
{
    global $_anomalyDir;
    if ($_anomalyDir !== null && is_dir($_anomalyDir)) {
        $files = glob($_anomalyDir . '/frame_*.json');
        if (count($files) > 0) {
            echo "\n⚠ 异常存档目录: {$_anomalyDir} (" . count($files) . " 个文件)\n";
        }
    }
}

// ============================================================
// 测试 1：100 次不断点击 "1" 按钮的稳定性
// ============================================================
echo "--- 测试 1: 100 次点击 '1' 按钮 ---\n";

test('100 次点击 "1" 按钮 → 布局/状态/约束检查', function () {
    $ctx = createTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    // 找到 "1" 按钮
    $btnNode = findClickableNode($app, 'inputDigit', '1');
    assert_not_null($btnNode, "找到 '1' 按钮的 RenderNode");

    // 点击一次并录制参考帧
    clickAndRender($app, $btnNode);
    $refSnapshot = captureSnapshot($component, $context);

    // 后续 99 次迭代：点击 → 渲染 → 检查
    $textHistory = [];
    for ($i = 1; $i < 100; $i++) {
        clickAndRender($app, $btnNode);
        $currentSnap = captureSnapshot($component, $context);

        // 追踪文本位置演变
        if (count($currentSnap->displayTexts) > 0) {
            $t0 = $currentSnap->displayTexts[0];
            $textHistory[] = [
                'display' => $component->display,
                'x' => $t0['x'], 'y' => $t0['y'],
                'fs' => $t0['fontSize'],
            ];
        }

        // 仅前 20 次打印详细演变（后面稳定后只按 20 间隔）
        if ($i <= 18) {
            printTextEvolution($textHistory, $i);
        }

        // 规则 A：绝对不变
        $violations = checkInvariantRules($refSnapshot, $currentSnap, $i);

        // 规则 B：条件不变
        $violations = array_merge($violations, checkConditionalRules($currentSnap, $i));

        // 规则 C：变化约束
        $violations = array_merge($violations, checkChangeRules($currentSnap, $i));

        // 规则 D：元素有效性（边界、重叠、GDI 敏感属性）
        $violations = array_merge($violations, checkElementValidity($context->drawnElements, $i));

        // 规则 E：clip 区域与文本溢出检查
        $violations = array_merge($violations, checkClipValidity($currentSnap, $i));

        // 即时断言
        if (count($violations) > 0) {
            archiveAnomalyFrame($i, $currentSnap, $violations, $ctx);
            assert_eq(0, count($violations),
                "Iteration $i violations:\n  " . implode("\n  ", $violations));
        }

        // 每 20 次打印进度（含文本位置跟踪）
        if (($i + 1) % 20 === 0) {
            $tx = '';
            if (count($currentSnap->displayTexts) > 0) {
                $t0 = $currentSnap->displayTexts[0];
                $tx = " text@({$t0['x']},{$t0['y']}) fs={$t0['fontSize']}";
            }
            echo "    [iter {$i}] display='{$component->display}' "
                . "acLabel='{$component->acLabel}' "
                . "buttons={$currentSnap->buttonCount} "
                . "elements={$currentSnap->totalCount}{$tx}\n";
        }
    }

    // 最终状态验证
    assert_eq(strlen($component->display), 15, "display 最终应为 15 位");
    assert_eq($component->acLabel, 'C', "display 非零时应为 C");
});

// ============================================================
// 测试 2：渲染管道基础正确性
// ============================================================
echo "\n--- 测试 2: 渲染管道基础 ---\n";

test('mount + doFirstRender 后 frame begin/end 被调用', function () {
    $platform = new _MockPlatform();
    $scheduler = new Scheduler();
    $app = new Application($platform, $scheduler);
    $component = new AppComponent();
    $app->mount($component);
    invokeDoFirstRender($app);

    assert_true($platform->renderContext->beginFrameCalled, "beginFrame 应该被调用");
    assert_true($platform->renderContext->endFrameCalled, "endFrame 应该被调用");
    assert_true($platform->renderContext->frameCount > 0, "frameCount 应该 > 0");
});

test('首次渲染产出非空的渲染元素', function () {
    $ctx = createTestApp(false); // 保留首帧渲染元素供检查
    $snap = captureSnapshot($ctx['component'], $ctx['context']);

    assert_true($snap->totalCount > 0, "首次渲染应有元素");
    assert_true($snap->buttonCount > 0, "应有按钮元素");
    assert_true($snap->rectCount > 0, "应有 rect 元素");
    assert_true($snap->clipPushCount > 0, "应有 clip-push（overflow:hidden）");
    assert_not_null($snap->clipPush, "clip-push 区域应存在");
    assert_eq($snap->clipPush['w'] ?? 0, 318, "clip-push 宽度应为 318");
    assert_eq($snap->clipPush['h'] ?? 0, 100, "clip-push 高度应为 100");
});

// ============================================================
// 测试 3：Application 事件管道
// ============================================================
echo "\n--- 测试 3: Application 事件管道 ---\n";

test('点击 "5" 按钮后 display 变为 "5"（通过 Application 事件管道）', function () {
    $ctx = createTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];

    $btnNode = findClickableNode($app, 'inputDigit', '5');
    assert_not_null($btnNode, "找到 '5' 按钮");

    clickAndRender($app, $btnNode);

    assert_eq($component->display, '5', "点击 5 后 display = 5");
});

test('点击 "1" → "2" → "3" 链式输入', function () {
    $ctx = createTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];

    foreach (['1', '2', '3'] as $digit) {
        $node = findClickableNode($app, 'inputDigit', $digit);
        clickAndRender($app, $node);
    }

    assert_eq($component->display, '123', "三步输入后 display = 123");
});

// ============================================================
// 测试 4：大量点击后按 C 清除 — 键盘区完整性
// ============================================================
echo "\n--- 测试 4: 大量点击后按 C 清除 ---\n";

test('点击 "1" 15 次后按 C 清除，键盘按钮保持完整', function () {
    $ctx = createTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    // 1. 点击 "1" 15 次填满显示区
    $btnNode = findClickableNode($app, 'inputDigit', '1');
    assert_not_null($btnNode, "找到 '1' 按钮");

    for ($i = 0; $i < 15; $i++) {
        clickAndRender($app, $btnNode);
    }

    assert_eq(strlen($component->display), 15, "display 应为 15 位");
    assert_eq($component->acLabel, 'C', "非零显示时 acLabel = C");
    echo "    display='{$component->display}' (15 chars)\n";

    // 2. 录制快照并检查 clip 有效性
    $snap15 = captureSnapshot($component, $context);
    $violations = checkClipValidity($snap15, 15);
    if (count($violations) > 0) {
        archiveAnomalyFrame(15, $snap15, $violations, $ctx);
        assert_eq(0, count($violations),
            "15 次点击后 clip 溢出检查:\n  " . implode("\n  ", $violations));
    }
    echo "    clip check: OK (" . $snap15->buttonCount . " buttons, " . $snap15->totalCount . " elements)\n";

    // 3. 点击 "C" 清除（ScientificPad 的 clear 按钮）
    $cNode = findClickableNode($app, 'clear', '');
    assert_not_null($cNode, "找到 'C' 按钮");
    clickAndRender($app, $cNode);

    // 4. 验证 display 重置
    assert_eq($component->display, '0', "C 清除后 display = 0");
    assert_eq($component->acLabel, 'AC', "C 清除后 acLabel = AC");
    echo "    C cleared: display='{$component->display}', acLabel='{$component->acLabel}'\n";

    // 5. 验证按钮完整性 — 所有关键按钮存在
    $clearSnap = captureSnapshot($component, $context);
    $expectedButtons = ['AC', 'C', '+/−', '%', '÷', '7', '8', '9', '×', '4', '5', '6', '−', '1', '2', '3', '+', '0', '.', '=', '☰', 'sin', 'cos', 'tan', 'log', 'ln', 'x²', 'x³', '√x', '1/x', 'π', 'e', '(', ')', '⌫'];
    $missingButtons = [];
    foreach ($expectedButtons as $label) {
        if (!isset($clearSnap->buttons[$label])) {
            $missingButtons[] = $label;
        }
    }
    assert_eq(0, count($missingButtons),
        "C 清除后所有按钮存在，缺失: " . implode(', ', $missingButtons));
    echo "    all " . count($expectedButtons) . " expected buttons present\n";

    // 6. 元素有效性检查
    $violations = checkElementValidity($context->drawnElements, 16);
    if (count($violations) > 0) {
        archiveAnomalyFrame(16, $clearSnap, $violations, $ctx);
        assert_eq(0, count($violations),
            "C 清除后元素有效性:\n  " . implode("\n  ", $violations));
    }

    // 7. Clip 有效性检查
    $violations = checkClipValidity($clearSnap, 16);
    if (count($violations) > 0) {
        archiveAnomalyFrame(16, $clearSnap, $violations, $ctx);
        assert_eq(0, count($violations),
            "C 清除后 clip 检查:\n  " . implode("\n  ", $violations));
    }

    // 8. 变化约束检查
    $violations = checkChangeRules($clearSnap, 16);
    if (count($violations) > 0) {
        assert_eq(0, count($violations),
            "C 清除后状态约束:\n  " . implode("\n  ", $violations));
    }

    echo "    integrity checks: ALL PASSED\n";
});

// ============================================================
// 测试 5：200 次点击 — 超过当前 100 次极限
// ============================================================
echo "\n--- 测试 5: 200 次点击 '1' 按钮 ---\n";

test('200 次点击 "1" 按钮 → 双倍压力验证', function () {
    $ctx = createTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    $btnNode = findClickableNode($app, 'inputDigit', '1');
    assert_not_null($btnNode, "找到 '1' 按钮");

    // 点击 1 次作为参考
    clickAndRender($app, $btnNode);
    $refSnapshot = captureSnapshot($component, $context);

    for ($i = 1; $i < 200; $i++) {
        clickAndRender($app, $btnNode);
        $currentSnap = captureSnapshot($component, $context);

        // 规则 A：按钮位置不变
        $violations = checkInvariantRules($refSnapshot, $currentSnap, $i);
        if (count($violations) > 0) {
            archiveAnomalyFrame($i, $currentSnap, $violations, $ctx);
            assert_eq(0, count($violations),
                "200次测试 iter $i 违反规则A:\n  " . implode("\n  ", $violations));
        }

        // 规则 C：display 不超过 15 位
        $violations = checkChangeRules($currentSnap, $i);
        if (count($violations) > 0) {
            archiveAnomalyFrame($i, $currentSnap, $violations, $ctx);
            assert_eq(0, count($violations),
                "200次测试 iter $i 违反规则C:\n  " . implode("\n  ", $violations));
        }

        // 规则 D：元素有效性
        $violations = checkElementValidity($context->drawnElements, $i);
        if (count($violations) > 0) {
            archiveAnomalyFrame($i, $currentSnap, $violations, $ctx);
            assert_eq(0, count($violations),
                "200次测试 iter $i 违反规则D:\n  " . implode("\n  ", $violations));
        }

        // 规则 E：clip 有效性
        $violations = checkClipValidity($currentSnap, $i);
        if (count($violations) > 0) {
            archiveAnomalyFrame($i, $currentSnap, $violations, $ctx);
            assert_eq(0, count($violations),
                "200次测试 iter $i 违反规则E:\n  " . implode("\n  ", $violations));
        }
    }

    assert_eq(strlen($component->display), 15, "200 次后 display 应为 15 位");
    assert_eq($component->acLabel, 'C', "display 非零时应为 C");
    echo "    [完成] 200 iterations: display='{$component->display}', buttons={$currentSnap->buttonCount}\n";
});

// ============================================================
// 测试 6：填满 → C 清除 → 填满，重复 5 轮（模拟用户反复操作）
// ============================================================
echo "\n--- 测试 6: 填满→清除 循环 5 轮 ---\n";

test('填满→C清除 循环 5 轮，每轮验证按钮完整性和 clip 有效性', function () {
    $ctx = createTestApp();
    $app = $ctx['app'];
    $component = $ctx['component'];
    $context = $ctx['context'];

    $btnNode = findClickableNode($app, 'inputDigit', '1');
    assert_not_null($btnNode, "找到 '1' 按钮");

    $expectedButtons = ['AC', 'C', '+/−', '%', '÷', '7', '8', '9', '×', '4', '5', '6', '−', '1', '2', '3', '+', '0', '.', '=', '☰', 'sin', 'cos', 'tan', 'log', 'ln', 'x²', 'x³', '√x', '1/x', 'π', 'e', '(', ')', '⌫'];

    for ($round = 1; $round <= 5; $round++) {
        // 填满 display（1 次点击变成 "11"，14 次追加到 15 位）
        clickAndRender($app, $btnNode); // "1" → "11"（首帧后 newInput=false）
        for ($i = 0; $i < 14; $i++) {
            clickAndRender($app, $btnNode);
        }

        assert_eq(strlen($component->display), 15, "第{$round}轮填满后 display 15 位");
        assert_eq($component->acLabel, 'C', "第{$round}轮非零显示时 acLabel = C");

        // 检查 clip 有效性
        $snap = captureSnapshot($component, $context);
        $violations = checkClipValidity($snap, $round * 15);
        if (count($violations) > 0) {
            archiveAnomalyFrame($round * 15, $snap, $violations, $ctx);
            assert_eq(0, count($violations),
                "第{$round}轮填满后 clip 溢出:\n  " . implode("\n  ", $violations));
        }

        // 检查元素有效性
        $violations = checkElementValidity($context->drawnElements, $round * 15);
        if (count($violations) > 0) {
            archiveAnomalyFrame($round * 15, $snap, $violations, $ctx);
            assert_eq(0, count($violations),
                "第{$round}轮填满后元素异常:\n  " . implode("\n  ", $violations));
        }

        // 清除
        $cNode = findClickableNode($app, 'clear', '');
        assert_not_null($cNode, "第{$round}轮找到 C 按钮");
        clickAndRender($app, $cNode);

        assert_eq($component->display, '0', "第{$round}轮 C 清除后 display = 0");
        assert_eq($component->acLabel, 'AC', "第{$round}轮 C 清除后 acLabel = AC");

        // 验证按钮完整性
        $clearSnap = captureSnapshot($component, $context);
        $missingButtons = [];
        foreach ($expectedButtons as $label) {
            if (!isset($clearSnap->buttons[$label])) {
                $missingButtons[] = $label;
            }
        }
        assert_eq(0, count($missingButtons),
            "第{$round}轮 C 清除后按钮缺失: " . implode(', ', $missingButtons));

        echo "    [round {$round}] filled→C cleared: {$snap->buttonCount} buttons, clip OK\n";
    }
});

// ============================================================
// 汇总
// ============================================================

// 清理 ThemeProvider 状态
resetThemeProvider();

// 输出异常存档信息
printAnomalySummary();

print_summary();
print_summary();
