<?php
/**
 * AOT Named Arguments — 最小复现样例
 *
 * 展示 AOT v1054 中 named arguments 被静默丢弃的 bug：
 * 构造函数调用时使用命名参数，AOT 下等价于无参构造，
 * 所有参数使用默认值。
 *
 * 编译:
 *   cd f:/work/Px && build.bat aot-named-args-test
 * 运行:
 *   apps/aot-named-args-test/bin/aot_named_args_test.exe
 *
 * 对比:
 *   - 命名参数 new Box(w: 100, h: 200)
 *   - 位置参数 new Box(100, 200)
 *   - getter 方法（AOT 安全方式）
 *   - 加总法验证（确保所有构造方式的最终产出一致）
 */

use native_types;

// ================================================================
// 被测类 — 模拟 ConstraintSpace
// ================================================================
class Box
{
    public readonly int $w;
    public readonly int $h;
    public readonly int $area;

    public function __construct(
        int $w = -1,
        int $h = -1,
        int $area = -999,
    ) {
        $this->w = $w;
        $this->h = $h;
        $this->area = $area !== -999 ? $area : $w * $h;
    }

    public function getW(): int { return $this->w; }
    public function getH(): int { return $this->h; }
    public function getArea(): int { return $this->area; }
}

// ================================================================
// 方式 A: 命名参数（BUG 触发路径）
// ================================================================
function testNamedArgs(): array
{
    $b = new Box(w: 100, h: 200);
    return [$b->getW(), $b->getH(), $b->getArea(), 'named'];
}

// ================================================================
// 方式 B: 位置参数（正确路径）
// ================================================================
function testPositionalArgs(): array
{
    $b = new Box(100, 200);
    return [$b->getW(), $b->getH(), $b->getArea(), 'positional'];
}

// ================================================================
// 方式 C: 混合参数（命名+位置混合）
// ================================================================
function testMixedArgs(): array
{
    $b = new Box(100, h: 200);
    return [$b->getW(), $b->getH(), $b->getArea(), 'mixed'];
}

// ================================================================
// 方式 D: 部分命名参数（使用默认值）
// ================================================================
function testPartialNamedArgs(): array
{
    $b = new Box(w: 50, area: 9999);
    return [$b->getW(), $b->getH(), $b->getArea(), 'partial_named'];
}

// ================================================================
// AOT 必需常量
// ================================================================
const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'Named Args Test';

// ================================================================
// 入口
// ================================================================
function main(): int
{
    $rA = testNamedArgs();
    $rB = testPositionalArgs();
    $rC = testMixedArgs();
    $rD = testPartialNamedArgs();

    // 验证结果
    $passA = ($rA[0] === 100 && $rA[1] === 200 && $rA[2] === 20000);
    $passB = ($rB[0] === 100 && $rB[1] === 200 && $rB[2] === 20000);
    $passC = ($rC[0] === 100 && $rC[1] === 200 && $rC[2] === 20000);
    // 方式 D: area=9999 有显式值，w=50, h 默认 -1
    $passD = ($rD[0] === 50 && $rD[1] === -1 && $rD[2] === 9999);

    $s = "+----------------------------------------------------------+\n";
    $s .= "|  AOT Named Arguments 测试报告                            |\n";
    $s .= "+----------------------------------------------------------+\n\n";

    $s .= "--- 测试配置 ---\n";
    $s .= "  类: Box (use native_types)\n";
    $s .= "  public readonly int \$w, \$h, \$area\n";
    $s .= "  __construct(\$w=-1, \$h=-1, \$area=-999)\n\n";

    $s .= "=== 构造方式对比 ===\n\n";

    $s .= "A) 命名参数: new Box(w: 100, h: 200)\n";
    $s .= "   w={$rA[0]} h={$rA[1]} area={$rA[2]}  -> " . ($passA ? "✅ PASS" : "❌ FAIL (期望 100,200,20000)") . "\n";

    $s .= "B) 位置参数: new Box(100, 200) [正确基线]\n";
    $s .= "   w={$rB[0]} h={$rB[1]} area={$rB[2]}  -> " . ($passB ? "✅ PASS" : "❌ FAIL") . "\n";

    $s .= "C) 混合参数: new Box(100, h: 200)\n";
    $s .= "   w={$rC[0]} h={$rC[1]} area={$rC[2]}  -> " . ($passC ? "✅ PASS" : "❌ FAIL") . "\n";

    $s .= "D) 部分命名: new Box(w: 50, area: 9999)\n";
    $s .= "   w={$rD[0]} h={$rD[1]} area={$rD[2]}  -> " . ($passD ? "✅ PASS" : "❌ FAIL") . "\n";

    $s .= "\n=== 判定 ===\n";
    if (!$passA && $passB) {
        $s .= "命名参数被 AOT 丢弃: AOT 将 new Box(w:100, h:200) 编译为 new Box()\n";
        $s .= "所有参数使用默认值 \$w=-1, \$h=-1, \$area=-999\n";
        $s .= "→ bug 确认: Named Arguments 被 AOT v1054 静默丢弃\n";
    } elseif ($passA && $passB) {
        $s .= "当前 AOT 版本正确处理了命名参数，未复现 bug。\n";
    }

    $s .= "\n测试时间: " . date('Y-m-d H:i:s') . "\n";

    echo $s;
    file_put_contents(getcwd() . '/aot_named_args_report.log', $s);
    echo "\n报告已写入: " . getcwd() . '/aot_named_args_report.log' . "\n";

    return 0;
}
