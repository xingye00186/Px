<?php
/**
 * AOT Cross-Class Readonly — 最小复现样例
 *
 * 展示 AOT v1054 中跨类读取 public readonly int 属性返回 0 的 bug：
 * 从另一个 use native_types 类读取 $frag->x 返回 0，
 * 但同类内 $this->x 读取正确，getter 方法也正确。
 *
 * 模拟真实场景: Application::fragmentToArray() 读取 PhysicalFragment::$x
 *
 * 编译:
 *   cd f:/work/Px && build.bat aot-cross-readonly
 * 运行:
 *   apps/aot-cross-readonly/bin/aot_cross_readonly.exe
 */

use native_types;

// ================================================================
// 被测类 — 模拟 PhysicalFragment
// ================================================================
class Fragment
{
    public readonly int $x;
    public readonly int $y;
    public readonly int $w;
    public readonly int $h;
    public readonly int $secret;

    public function __construct(
        int $x,
        int $y,
        int $w,
        int $h,
        int $secret = 42,
    ) {
        $this->x = $x;
        $this->y = $y;
        $this->w = $w;
        $this->h = $h;
        $this->secret = $secret;
    }

    // 同类读取（方法 A — 应正确）
    public function readSelfX(): int { return $this->x; }
    public function readSelfY(): int { return $this->y; }
    public function readSelfW(): int { return $this->w; }
    public function readSelfH(): int { return $this->h; }

    // toArray 同类读取
    public function toArray(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'w' => $this->w,
            'h' => $this->h,
            'secret' => $this->secret,
        ];
    }
}

// ================================================================
// 读取器 — 模拟 Application::fragmentToArray()
// 从另一个 use native_types 类读取 Fragment 的 readonly 属性
// ================================================================
class Reader
{
    // 方式 1: 跨类直接属性读取（BUG 路径）
    public function readDirect(Fragment $f): array
    {
        return [
            'x' => $f->x,
            'y' => $f->y,
            'w' => $f->w,
            'h' => $f->h,
            'secret' => $f->secret,
        ];
    }

    // 方式 2: 跨类 getter 方法访问（已知正确路径）
    public function readViaGetter(Fragment $f): array
    {
        return [
            'x' => $f->readSelfX(),
            'y' => $f->readSelfY(),
            'w' => $f->readSelfW(),
            'h' => $f->readSelfH(),
            'secret' => $f->secret,  // secret 是同类可读
        ];
    }

    // 方式 3: 调用 toArray 方法（同类读取，应正确）
    public function readViaToArray(Fragment $f): array
    {
        return $f->toArray();
    }
}

// ================================================================
// AOT 必需常量
// ================================================================
const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'Cross Readonly Test';

// ================================================================
// 入口
// ================================================================
function main(): int
{
    $f = new Fragment(100, 200, 800, 600, 42);
    $reader = new Reader();

    // 方式 1
    $r1 = $reader->readDirect($f);
    // 方式 2
    $r2 = $reader->readViaGetter($f);
    // 方式 3
    $r3 = $reader->readViaToArray($f);

    // 预期值
    $expect = ['x' => 100, 'y' => 200, 'w' => 800, 'h' => 600, 'secret' => 42];

    $pass1 = ($r1 === $expect);
    $pass2 = ($r2 === $expect);
    $pass3 = ($r3 === $expect);

    $s = "+----------------------------------------------------------+\n";
    $s .= "|  AOT Cross-Class Readonly 测试报告                       |\n";
    $s .= "+----------------------------------------------------------+\n\n";

    $s .= "--- 测试配置 ---\n";
    $s .= "  类: Fragment (use native_types)\n";
    $s .= "  public readonly int \$x=100, \$y=200, \$w=800, \$h=600, \$secret=42\n";
    $s .= "  类: Reader (use native_types)\n\n";

    $s .= "=== 访问方式对比 ===\n\n";

    $s .= "A) 跨类直接 \$f->x\n";
    $s .= "   x={$r1['x']} y={$r1['y']} w={$r1['w']} h={$r1['h']} s={$r1['secret']}\n";
    $s .= "   -> " . ($pass1 ? "✅ PASS" : "❌ FAIL") . "\n";

    $s .= "B) 跨类 getter \$f->readSelfX()\n";
    $s .= "   x={$r2['x']} y={$r2['y']} w={$r2['w']} h={$r2['h']} s={$r2['secret']}\n";
    $s .= "   -> " . ($pass2 ? "✅ PASS" : "❌ FAIL") . "\n";

    $s .= "C) toArray() 同类读取\n";
    $s .= "   x={$r3['x']} y={$r3['y']} w={$r3['w']} h={$r3['h']} s={$r3['secret']}\n";
    $s .= "   -> " . ($pass3 ? "✅ PASS" : "❌ FAIL") . "\n";

    $s .= "\n=== 判定 ===\n";
    if (!$pass1 && $pass2 && $pass3) {
        $s .= "跨类 readonly int 访问失败:\n";
        $s .= "  直接 \$f->x 在 Reader 中返回 0\n";
        $s .= "  getter \$f->readSelfX() 正确 (同类内 \$this->x 正常)\n";
        $s .= "  toArray() 正确 (同类读取)\n";
        $s .= "→ bug 确认: use native_types 跨类 readonly int 读取被 AOT 错误编译\n";
    } elseif ($pass1 && $pass2 && $pass3) {
        $s .= "所有方式均正确，未复现 bug。\n";
    } elseif ($pass1) {
        $s .= "直接读取正确，Bug 未复现。\n";
    } else {
        $s .= "部分路径失败，详细见上。\n";
    }

    $s .= "\n测试时间: " . date('Y-m-d H:i:s') . "\n";

    echo $s;
    file_put_contents(getcwd() . '/aot_cross_readonly_report.log', $s);
    echo "\n报告已写入: " . getcwd() . '/aot_cross_readonly_report.log' . "\n";

    return 0;
}
