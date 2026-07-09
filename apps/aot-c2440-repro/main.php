<?php
use native_types;

// ════════════════════════════════════════════════════════════
// AOT 编译器问题 — 完整复现集
// 编译: build.bat aot-c2440-repro
// 输出: apps/aot-c2440-repro/bin/aot_c2440_repro.exe
// ════════════════════════════════════════════════════════════

// ─── Issue 0: C2440 Variant→Int ✓ 已知已修复 ───
class Data { public int $val = 0; public int $result = 0; }
function c2440(): string { $d = new Data(); $d->val = 5; $d->result = $d->val + 1; return $d->result === 6 ? "PASS" : "FAIL"; }

// ─── Issue 1: 命名参数 ───
class NamedTarget { public function __construct(public readonly int $x, public readonly int $y) {} }
function namedArgs(): string { $o = new NamedTarget(x: 1, y: 2); return ($o->x === 1 && $o->y === 2) ? "PASS" : "FAIL"; }

// ─── Issue 2: toArray() 参数丢失 ★ 最容易触发 ───
class ToArrayTest {
    public function toArray(int $v): array { return ['v' => $v]; }
    public function run(): array { return $this->toArray(42); }
}
function toArrayBug(): string { $t = new ToArrayTest(); $r = $t->run(); return ($r['v'] ?? -1) === 42 ? "PASS" : "FAIL"; }

// ─── Issue 3: private 属性子类访问 ───
class PropParent { private ?\Closure $cb = null; public function setCb(\Closure $fn): void { $this->cb = $fn; } }
class PropChild extends PropParent {}
function privateProp(): string { $c = new PropChild(); $c->setCb(function(){}); return "PASS"; }

// ─── Issue 4: 对象→float 转换 ───
class CssMock { public function toPx(): int { return 100; } }
function floatCast(): string { return ((float)(new CssMock()) == 100) ? "PASS" : "FAIL"; }

// ─── Issue 5: 类似类名混淆 ───
class SimilarA { public int $id = 0; }
class SimilarABuilder { public function build(): SimilarA { $o = new SimilarA(); $o->id = 42; return $o; } }
function classConfusion(): string { return (new SimilarABuilder())->build()->id === 42 ? "PASS" : "FAIL"; }

// ─── Issue 6: 命名参数 static factory ───
class FactoryT { public function __construct(public readonly int $a, public readonly int $b) {} }
function factoryNamed(): string { $o = new FactoryT(a: 7, b: 8); return ($o->a === 7 && $o->b === 8) ? "PASS" : "FAIL"; }

// ─── 测试调度（toArray 放最后因为它会 fatal）───
function main(): int
{
    $tests = [];
    $run = function(string $name, \Closure $fn) use (&$tests) {
        try {
            $r = $fn();
            $tests[] = [$name, $r === "PASS" ? "PASS" : "FAIL($r)"];
        } catch (\Throwable $e) {
            $tests[] = [$name, "CRASH: " . $e->getMessage()];
        }
    };

    echo "AOT Repro: " . (function_exists('sk_create_window_context') ? "AOT" : "PHP") . " mode\n";

    $run("C2440(Variant->Int)",   fn() => c2440());
    $run("NamedArgs",              fn() => namedArgs());
    $run("PrivateProp",            fn() => privateProp());
    $run("FloatCast(Object->)",    fn() => floatCast());
    $run("ClassConfusion",         fn() => classConfusion());
    $run("FactoryNamedArgs",       fn() => factoryNamed());
    $run("toArray(param drop)",    fn() => toArrayBug()); // 最后执行

    $allPass = true;
    foreach ($tests as $t) {
        $icon = $t[1] === 'PASS' ? '✅' : '❌';
        printf("  %s %s: %s\n", $icon, $t[0], $t[1]);
        if ($t[1] !== 'PASS') $allPass = false;
    }
    echo $allPass ? "\nALL PASS\n" : "\nSOME FAILED\n";
    return $allPass ? 0 : 1;
}
