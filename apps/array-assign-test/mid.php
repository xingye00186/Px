<?php

use native_types;

/** @abstract 子类需覆盖 onMount */
class Mid {
    public function mount(): void {
        $this->onMount();
    }

    public function onMount(): void {
        // concrete 空 — Direct Call Optimization 的触发条件
    }
}
