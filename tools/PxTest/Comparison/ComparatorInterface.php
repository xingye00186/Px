<?php

namespace PxTest\Comparison;

use PxTest\Core\ToleranceConfig;

/**
 * 对比器统一接口。
 * 所有几何/样式/稳定性/像素对比器实现此接口。
 */
interface ComparatorInterface
{
    /**
     * @param array $baseline 基线数据
     * @param array $current  当前数据
     * @return ComparisonResult
     */
    public function compare(array $baseline, array $current, ToleranceConfig $tolerance): ComparisonResult;

    /** 对比器名称（用于报告/注册） */
    public function name(): string;
}
