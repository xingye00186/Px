<?php

namespace Px\Layout;

use native_types;

/**
 * LruNode — LRU 双向链表节点（TextMeasureCache 内部使用）
 *
 * ?self 类型属性（prev/next）在 AOT 下已验证兼容（aot-syntax-test G16）。
 */
class LruNode
{
    public string $key = '';
    public ?LruNode $prev = null;
    public ?LruNode $next = null;

    public function __construct(string $key)
    {
        $this->key = $key;
    }
}
