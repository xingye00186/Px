<?php
/**
 * AOT 最小化验证 — 跨对象 typed int 属性赋值是否需要 (int) cast
 *
 * 复刻 framework/Layout/MarginStrut.php 的 copy() 模式：
 *   class X { public int $a = 0; public int $b = 0; }
 *   $s = new X();
 *   $s->a = $this->a;          // 无 cast — 待验证是否触发 C2440
 *   $s->a = (int)$this->a;     // 有 cast — 现有代码写法
 *
 * 编译:
 *   build.bat aot-cross-int-write
 * 运行:
 *   apps/aot-cross-int-write/bin/aot_cross_int_write.exe
 *
 * 判定：
 *   - 编译通过 + 两种 copy 结果相等且正确 → (int) cast 非必需（可移除）
 *   - 无 cast 版编译失败(C2440) → (int) cast 必需（保留）
 *   - 编译通过但无 cast 版结果为 0/空 → (int) cast 事实必需（保留）
 */

use native_types;

// ── 精确复刻 MarginStrut 形状 ──
class Strut
{
    public int $positiveMargin = 0;
    public int $negativeMargin = 0;

    public function append(int $margin): void
    {
        if ($margin > 0) {
            if ($margin > $this->positiveMargin) $this->positiveMargin = $margin;
        } elseif ($margin < 0) {
            if ($margin < $this->negativeMargin) $this->negativeMargin = $margin;
        }
    }

    /** 变体 A: 无 (int) cast — 直接跨对象赋值 */
    public function copyNoCast(): Strut
    {
        $s = new Strut();
        $s->positiveMargin = $this->positiveMargin;
        $s->negativeMargin = $this->negativeMargin;
        return $s;
    }

    /** 变体 B: 有 (int) cast — 现有代码写法 */
    public function copyWithCast(): Strut
    {
        $s = new Strut();
        $s->positiveMargin = (int)$this->positiveMargin;
        $s->negativeMargin = (int)$this->negativeMargin;
        return $s;
    }

    /** 对照：cross-class 静态方法读取（类似 appendStrut 的 $other->positiveMargin） */
    public static function crossReadNoCast(Strut $src, Strut $dst): void
    {
        $dst->positiveMargin = $src->positiveMargin;
        $dst->negativeMargin = $src->negativeMargin;
    }

    public static function crossReadWithCast(Strut $src, Strut $dst): void
    {
        $dst->positiveMargin = (int)$src->positiveMargin;
        $dst->negativeMargin = (int)$src->negativeMargin;
    }
}

// ── AOT 必需常量 ──
const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'AOT Cross Int Write Verify';

function fmt(string $tag, int $expPos, int $expNeg, Strut $s): string
{
    $okP = ($s->positiveMargin === $expPos);
    $okN = ($s->negativeMargin === $expNeg);
    $ok  = $okP && $okN;
    return sprintf(
        "  [%s] %-24s pos=%d(exp %d, %s)  neg=%d(exp %d, %s)\n",
        $ok ? 'OK' : '--', $tag,
        $s->positiveMargin, $expPos, $okP ? 'OK' : 'FAIL',
        $s->negativeMargin, $expNeg, $okN ? 'OK' : 'FAIL'
    );
}

function main(): int
{
    $mode = function_exists('sk_create_window_context') ? 'AOT' : 'PHP';
    echo "AOT Cross-Object Int-Property Write Verify [$mode mode]\n";
    echo "═══════════════════════════════════════════════════════\n\n";

    // 场景 1: 有值累积后复制
    $src = new Strut();
    $src->append(20);   // positiveMargin = 20
    $src->append(-15);  // negativeMargin = -15
    $src->append(30);   // positiveMargin = 30 (取 max)
    $src->append(-25);  // negativeMargin = -25 (取 min)

    echo "源对象: pos={$src->positiveMargin} neg={$src->negativeMargin}\n";
    echo "\n--- 场景 1: instance method copy ---\n";
    echo fmt('copyNoCast',    30, -25, $src->copyNoCast());
    echo fmt('copyWithCast',  30, -25, $src->copyWithCast());

    echo "\n--- 场景 2: static method cross-read ---\n";
    $dstA = new Strut();
    Strut::crossReadNoCast($src, $dstA);
    echo fmt('crossReadNoCast',   30, -25, $dstA);

    $dstB = new Strut();
    Strut::crossReadWithCast($src, $dstB);
    echo fmt('crossReadWithCast', 30, -25, $dstB);

    echo "\n═══════════════════════════════════════════════════════\n";
    echo "判定:\n";
    echo "  若 4 行全 OK → (int) cast 在此形状下非必需，可移除\n";
    echo "  若 NoCast 行 FAIL(结果 0) → cast 是事实必需，保留\n";
    echo "  若整个 exe 无法生成 → cast 是编译必需（C2440），保留\n";

    return 0;
}
