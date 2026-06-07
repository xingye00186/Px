<?php

use native_types;

abstract class Mid extends BaseAbstract {
    public function mount(): void {
        $this->onMount();
    }

    public function onMount(): void {
        // concrete 空实现 — Direct Call Optimization 的触发条件
    }
}
