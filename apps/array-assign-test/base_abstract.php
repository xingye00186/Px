<?php

use native_types;

// 顶层抽象基类 — onMount 声明为 abstract
abstract class BaseAbstract {
    public array $src = [1, 2, 3];
    public array $dst = [];

    abstract public function onMount(): void;
}
