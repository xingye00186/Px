<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;

/**
 * LayoutInput — 布局策略纯函数输入
 *
 * 包含策略布局所需的一切信息，不包含任何 RenderNode 引用。
 * 所有字段 readonly，构造后不可变。
 *
 * AOT 兼容：
 * - 所有属性 public readonly
 * - 不含闭包、引用、回调
 */
class LayoutInput
{
    /** 布局约束（容器尺寸、定位偏移等） */
    public readonly LayoutConstraints $constraints;

    /** 计算样式快照 */
    public readonly ComputedStyle $style;

    /** #text 节点文本内容（用于文本测量） */
    public readonly string $textContent;

    /** 子节点布局结果（已由上一级递归计算的 LayoutResult[]） */
    public readonly array $childResults;

    // ── 定位祖先信息（AbsoluteStrategy 需要） ──

    /** 定位类型: 'absolute' | 'fixed' | 'static' | 'relative' | 'sticky' */
    public readonly string $position;

    /** 定位祖先的 x 坐标（absolute 用 padding box 坐标） */
    public readonly ?int $ancestorX;

    /** 定位祖先的 y 坐标 */
    public readonly ?int $ancestorY;

    /** 定位祖先的 content width */
    public readonly ?int $ancestorW;

    /** 定位祖先的 content height */
    public readonly ?int $ancestorH;

    /** 定位祖先的 border-left */
    public readonly int $ancestorBorderLeft;

    /** 定位祖先的 border-top */
    public readonly int $ancestorBorderTop;

    /** 定位祖先的 padding-left */
    public readonly int $ancestorPaddingLeft;

    /** 定位祖先的 padding-top */
    public readonly int $ancestorPaddingTop;

    /** Viewport 宽度（fixed 定位使用） */
    public readonly int $viewportW;

    /** Viewport 高度（fixed 定位使用） */
    public readonly int $viewportH;

    public function __construct(
        LayoutConstraints $constraints,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childResults = [],
        string $position = 'static',
        ?int $ancestorX = null,
        ?int $ancestorY = null,
        ?int $ancestorW = null,
        ?int $ancestorH = null,
        int $ancestorBorderLeft = 0,
        int $ancestorBorderTop = 0,
        int $ancestorPaddingLeft = 0,
        int $ancestorPaddingTop = 0,
        int $viewportW = 0,
        int $viewportH = 0,
    ) {
        $this->constraints        = $constraints;
        $this->style              = $style ?? new ComputedStyle([]);
        $this->textContent        = $textContent;
        $this->childResults       = $childResults;
        $this->position           = $position;
        $this->ancestorX          = $ancestorX;
        $this->ancestorY          = $ancestorY;
        $this->ancestorW          = $ancestorW;
        $this->ancestorH          = $ancestorH;
        $this->ancestorBorderLeft = $ancestorBorderLeft;
        $this->ancestorBorderTop  = $ancestorBorderTop;
        $this->ancestorPaddingLeft = $ancestorPaddingLeft;
        $this->ancestorPaddingTop  = $ancestorPaddingTop;
        $this->viewportW          = $viewportW;
        $this->viewportH          = $viewportH;
    }
}
