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

    $cnt = count($c->dst);
    if ($cnt === 3) {
        echo "[PASS] dst = 3 (\$this->onMount() virtual dispatch works)\n";
    } else {
        echo "[FAIL] dst = $cnt (expected 3)\n";
        echo "  => v1054: mount() called onMount() DIRECTLY, skipping override\n";
    }
}
