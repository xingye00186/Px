<?php

namespace PxTest\Comparison;

/**
 * 对比结果值对象。
 */
class ComparisonResult
{
    /** @param string[] $diffs 差异描述列表 */
    public function __construct(
        public readonly bool  $passed,
        public readonly array $diffs = [],
        public readonly string $comparatorName = '',
    ) {}

    public static function pass(string $name = ''): self
    {
        return new self(true, [], $name);
    }

    public static function fail(string $name = '', array $diffs = []): self
    {
        return new self(false, $diffs, $name);
    }
}
