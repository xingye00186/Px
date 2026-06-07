<?php

use native_types;

/**
 * 最小复现：v1054 Direct Call Optimization 误优化
 * 
 * Base::onMount() 有 concrete（空）实现 → AOT 可能会将其视为直接调用目标
 * Child::onMount() 覆盖此方法 → 期望通过虚派发正确调用
 */
class Base {
    public array $src = [1, 2, 3];
    public array $dst = [];

    public function mount(): void {
        // 此处调用 $this->onMount()，期望虚派发
        $this->onMount();
    }

    public function onMount(): void {
        // 空实现 — 如果 Direct Call Optimization 触发，会直接跳到这里，dst 保持 []
    }
}

class Child extends Base {
    public function onMount(): void {
        // 覆盖 — 如果虚派发正确，会执行此处，dst = [1,2,3]
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
        echo "       mount() called Base::onMount() directly, skipped Child::onMount()\n";
    }
}
