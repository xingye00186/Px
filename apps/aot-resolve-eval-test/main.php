<?php
/**
 * AOT resolve + eval 模式 — 最小复现
 *
 * 演示在 AOT 编译下，当方法体同时包含:
 *   1. \Closure::fromCallable([$this, 'method'])  — 触发 eval 模式
 *   2. 深层属性链加法: $style->margin->left->toPx()
 *
 * 在 eval 上下文中，属性链中间环节返回 php::Variant null，
 * 参与算术运算时触发: "Unsupported operand types: null + int"
 *
 * ── MODE A (BROKEN) ──
 *   将 resolveThatTriggersEval() 中第93行取消注释, 95-99行注释,
 *   然后: build.bat aot-resolve-eval-test --run
 *   预期: FATAL ERROR (crash)
 *
 * ── MODE B (FIXED) ── (当前默认)
 *   使用 (int)(...?? 0) 防护, 正常输出 PASS
 *
 * ── 原理 ──
 *   G14 已确认 AOT 不支持闭包。但 LayoutResolver::resolveFragment()
 *   因包含 fromCallable 被降级为 eval()。在 eval 中属性链返回
 *   Variant null。修复: 每项加 (int)(...?? 0)。
 */
use native_types;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'AOT Resolve Eval Test';

// ── 模拟 ComputedStyle 嵌套属性 ──

class CssLen
{
    public readonly float $value;
    public readonly string $unit;
    public function __construct(float $value, string $unit = 'px')
    {
        $this->value = $value;
        $this->unit  = $unit;
    }
    public function toPx(): int { return (int)$this->value; }
    public static function px(float $v): self { return new self($v, 'px'); }
    public static function auto(): self { return new self(0, 'auto'); }
}

class CssR
{
    public readonly CssLen $top, $right, $bottom, $left;
    public function __construct(CssLen $t, CssLen $r, CssLen $b, CssLen $l)
    {
        $this->top = $t; $this->right = $r; $this->bottom = $b; $this->left = $l;
    }
}

class MockStyle
{
    public readonly CssLen $left;
    public readonly CssLen $top;
    public readonly CssR $margin;

    public function __construct()
    {
        $z = CssLen::px(0);
        $this->left   = CssLen::px(10);
        $this->top    = CssLen::px(20);
        $this->margin = new CssR($z, $z, $z, CssLen::px(30));
    }
}

// ── 被测类 ──

class ResolverEval
{
    public function helper(MockStyle $s): int { return $s->left->toPx(); }

    /**
     * 此方法同时包含 fromCallable 和深层属性链加法,
     * 触发 AOT eval 模式。
     *
     * 要重现 crash:
     *   取消下面第93行的注释, 注释掉95-99行,
     *   然后 build.bat aot-resolve-eval-test --run
     */
    public function resolveThatTriggersEval(MockStyle $s): string
    {
        // 本条语句使 AOT 将本方法降级为 eval()
        $cb = \Closure::fromCallable([$this, 'helper']);
        $dummy = $cb($s); // 实际调用一次确保存活

        $parentContentX = 10;

        // ── MODE A (crash): 直接加法, 无任何防护 ──
        // xx: 取消下面一行的注释, 注释掉后面的 MODE B
        // $x = $parentContentX + $s->left->toPx() + $s->margin->left->toPx();

        // ── MODE B (fix): 每项加 (int)(...?? 0) ──
        $x = (int)($parentContentX ?? 0)
           + (int)(($s->left->toPx()) ?? 0)
           + (int)(($s->margin->left->toPx()) ?? 0);

        $expected = 10 + 10 + 30; // parentCX(10) + left(10) + marginLeft(30)
        if ($x === $expected) {
            return "PASS: x={$x} (expected {$expected})\n";
        }
        return "FAIL: x={$x} (expected {$expected})\n";
    }
}

// ── 入口 ──

function main(): int
{
    echo "========================================\n";
    echo " AOT Resolve Eval Test\n";
    echo " 复现: null + int crash in eval mode\n";
    echo "========================================\n\n";

    $resolver = new ResolverEval();
    $style    = new MockStyle();

    echo $resolver->resolveThatTriggersEval($style);

    echo "\n---\n";
    echo " 要重现 crash:\n";
    echo "   1. 取消 resolveThatTriggersEval() 中第93行注释\n";
    echo "   2. 注释掉第95-99行\n";
    echo "   3. build.bat aot-resolve-eval-test --run\n";
    echo "========================================\n";
    return 0;
}
