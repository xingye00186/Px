<?php

use native_types;

// 最小复现：模拟 ReactiveComponent::mount() -> onMount() 虚派发
class Base {
    public array $src = [1, 2, 3];
    public array $dst = [];

    public function mount(): void {
        $this->onMount();
    }

    public function onMount(): void {
        $this->dst = $this->src;
    }
}

class Child extends Base {
    // 重写 onMount() — v1054 会直接跳转到 Base::onMount，跳过此处
    public function onMount(): void {
        $this->dst = $this->src;
    }

    public function extra(string $key): string {
        return $this->src[0] . $key;
    }
}

function main(): void
{
    $c = new Child();
    $c->mount();

    echo "=== v1054 Direct Call Optimization 复现测试 ===\n\n";

    // 测试1：直接访问 — 编译器从 new 可推断 $c 为 Child 类型
    $cnt = count($c->dst);
    if ($cnt === 3) {
        echo "[PASS] test1 - direct access: dst = $cnt (expected 3)\n";
    } else {
        echo "[FAIL] test1 - direct access: dst = $cnt (expected 3)\n";
        echo "       => v1054: mount() called Base::onMount() DIRECTLY, skipping Child override\n";
    }

    // 测试2：objval() 类型接续 — 从 Var 恢复对象类型
    $c2 = objval($c, Child::class);
    $cnt2 = count($c2->dst);
    if ($cnt2 === 3) {
        echo "[PASS] test2 - objval direct: dst = $cnt2 (expected 3)\n";
    } else {
        echo "[FAIL] test2 - objval direct: dst = $cnt2 (expected 3)\n";
    }

    // 测试3：从数组取值后 objval() 恢复类型
    $items = [$c];
    $c3 = objval($items[0], Child::class);
    $cnt3 = count($c3->dst);
    if ($cnt3 === 3) {
        echo "[PASS] test3 - objval from array: dst = $cnt3 (expected 3)\n";
    } else {
        echo "[FAIL] test3 - objval from array: dst = $cnt3 (expected 3)\n";
    }

    // 测试4：objval 后调用方法 + 数组元素访问
    $c4 = objval($c, Child::class);
    $r = $c4->extra('!');
    if ($r === '1!') {
        echo "[PASS] test4 - objval + method call: $r (expected '1!')\n";
    } else {
        echo "[FAIL] test4 - objval + method call: $r (expected '1!')\n";
    }

    echo "\n=== 测试完成 ===\n";
}
