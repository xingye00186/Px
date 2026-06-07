<?php

use Px\ReactiveComponent;
use Px\Rendering\VNode;
use native_types;

// 使用真实的 ReactiveComponent 验证 onMount 抽象化修复
class Child extends ReactiveComponent {
    public array $src = [1, 2, 3];
    public array $dst = [];

    public function onMount(): void {
        $this->dst = $this->src;
    }

    public function onUnmount(): void {}

    public function render(): VNode {
        return VNode::h('div', [], []);
    }

    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }
}

function main(): void
{
    $c = new Child('test');
    $c->mount();

    echo "=== ReactiveComponent onMount 抽象化修复验证 ===\n\n";

    // 测试1：直接访问
    $cnt = count($c->dst);
    if ($cnt === 3) {
        echo "[PASS] test1 - direct: dst = $cnt (expected 3)\n";
        echo "       onMount() abstract in ReactiveComponent: virtual dispatch works\n";
    } else {
        echo "[FAIL] test1 - direct: dst = $cnt (expected 3)\n";
        echo "       onMount() abstract in ReactiveComponent: Direct Call Optimization still fires\n";
    }

    // 测试2：objval() — 从 Var 恢复对象类型
    $c2 = objval($c, Child::class);
    $cnt2 = count($c2->dst);
    if ($cnt2 === 3) {
        echo "[PASS] test2 - objval: dst = $cnt2 (expected 3)\n";
    } else {
        echo "[FAIL] test2 - objval: dst = $cnt2 (expected 3)\n";
    }

    // 测试3：从数组取值后 objval()
    $items = [$c];
    $c3 = objval($items[0], Child::class);
    $cnt3 = count($c3->dst);
    if ($cnt3 === 3) {
        echo "[PASS] test3 - objval from array: dst = $cnt3 (expected 3)\n";
    } else {
        echo "[FAIL] test3 - objval from array: dst = $cnt3 (expected 3)\n";
    }

    echo "\n=== 测试完成 ===\n";
}
