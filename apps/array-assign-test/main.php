<?php

use native_types;

class Child extends Mid {
    public array $src = [1, 2, 3];
    public array $dst = [];

    public function onMount(): void {
        $this->dst = $this->src;
    }
}

function main(): void
{
    $c = new Child();
    $c->mount();

    $cnt = count($c->dst);
    if ($cnt === 3) {
        echo "[PASS] dst = $cnt, virtual dispatch works\n";
    } else {
        echo "[FAIL] dst = $cnt, Direct Call Optimization bypassed override\n";
        echo "       mount() called Mid::onMount() directly, skipped Child::onMount()\n";
    }
}
