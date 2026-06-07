<?php

use native_types;

// 中间类 — 同时包含 mount() 和 concrete onMount()（bug 触发器）
// mount() 和 concrete onMount() 必须在同一个文件中
class Mid extends BaseAbstract {
    public function mount(): void {
        $this->onMount();
    }

    public function onMount(): void {
        // concrete 空实现 — 如果 Direct Call Optimization 触发，会跳过子类
    }
}
