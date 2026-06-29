<?php

namespace PxTest\Core;

/**
 * 按属性/类别配置的容忍度值对象 — 消除硬编码魔法数字。
 *
 * 支持三级查找：
 *   1. 按具体属性名 (如 'x', 'fontSize')
 *   2. 按类别 (如 'geometry', 'style', 'pixel')
 *   3. 全局默认 (defaultTolerance)
 */
class ToleranceConfig
{
    /** @var array<string, int> */
    private array $propertyTolerances;

    /** @var array<string, int> */
    private array $categoryTolerances;

    public function __construct(
        public readonly int $defaultTolerance = 1,
    ) {
        // 默认配置 — 与现有 run.php/check_regression.php 对齐
        $this->categoryTolerances = [
            'geometry' => 1,    // x, y, w, h 容忍 1px
            'style'    => 0,    // CSS 样式值必须精确
            'pixel'    => 5,    // 像素对比阈值 5%
            'color'    => 10,   // 颜色通道差值容忍 10
        ];

        $this->propertyTolerances = [
            'x'          => 1,   // 1px 容差（GDI vs DirectWrite 字体度量四舍五入差异）
            'y'          => 2,   // 2px 容差（DirectWrite 与浏览器 line-height 残余差异）
            'w'          => 1,   // 宽度允许 1px 偏差
            'h'          => 2,   // 2px 容差（DirectWrite 与浏览器 font metrics 残余差异）
            'visualW'    => 1,
            'visualH'    => 1,
            'fontSize'   => 0,   // 字体大小必须精确
            'paddingTop' => 1,
            'lineHeight' => 2,   // 行高允许 2px（字体引擎差异）
        ];
    }

    /** 按具体属性名查询容忍度 */
    public function forProperty(string $property): int
    {
        return $this->propertyTolerances[$property] ?? $this->defaultTolerance;
    }

    /** 按类别查询容忍度 */
    public function forCategory(string $category): int
    {
        return $this->categoryTolerances[$category] ?? $this->defaultTolerance;
    }

    /** 动态覆盖特定属性的容忍度 */
    public function withProperty(string $property, int $tolerance): self
    {
        $clone = clone $this;
        $clone->propertyTolerances[$property] = $tolerance;
        return $clone;
    }

    /** 动态覆盖特定类别的容忍度 */
    public function withCategory(string $category, int $tolerance): self
    {
        $clone = clone $this;
        $clone->categoryTolerances[$category] = $tolerance;
        return $clone;
    }

    /** 创建宽松容忍度（调试用） */
    public static function lenient(): self
    {
        $c = new self(defaultTolerance: 5);
        $c->categoryTolerances['geometry'] = 5;
        $c->categoryTolerances['pixel'] = 10;
        return $c;
    }

    /** 创建严格容忍度（精确匹配） */
    public static function strict(): self
    {
        return new self(defaultTolerance: 0);
    }
}
