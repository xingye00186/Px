<?php

use Px\ReactiveComponent;
use Px\Rendering\VNode;
use native_types;

class TestChild extends ReactiveComponent {
    public array $src = [1, 2, 3];
    public array $dst = [];

    public function onMount(): void {
        $this->dst = $this->src;
    }

    public function render(): VNode {
        return VNode::h('div', [], []);
    }

    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }
}

function main(): void
{
    $c = new TestChild('test');
    $c->mount();

    // 测试1：直接访问 — 编译器从 new 可推断 $c 为 TestChild 类型
    $cnt = count($c->dst);
    if ($cnt === 3) {
        echo "[PASS] dst = 3 (\$this->onMount() virtual dispatch works)\n";
    } else {
        echo "[FAIL] dst = $cnt (expected 3)\n";
        echo "  => v1054: mount() called onMount() DIRECTLY, skipping override\n";
    }

    // 测试2：objval() 类型接续 — 显式恢复对象类型信息
    // 场景：从数组或 any 类型取值后，编译器丢失类型信息，需用 objval() 重建
    $c2 = objval($c, TestChild::class);
    $cnt2 = count($c2->dst);
    if ($cnt2 === 3) {
        echo "[PASS] objval() continuation: cnt = $cnt2 (expected 3)\n";
    } else {
        echo "[FAIL] objval() continuation: cnt = $cnt2 (expected 3)\n";
    }

    // 测试3：通过数组存取后使用 objval() 恢复类型
    $items = [$c];
    $c3 = objval($items[0], TestChild::class);
    $cnt3 = count($c3->dst);
    if ($cnt3 === 3) {
        echo "[PASS] objval() from array: cnt = $cnt3 (expected 3)\n";
    } else {
        echo "[FAIL] objval() from array: cnt = $cnt3 (expected 3)\n";
    }
}
