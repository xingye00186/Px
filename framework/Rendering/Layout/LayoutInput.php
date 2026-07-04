<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\ComputedStyle;
use Closure;

/**
 * LayoutInput — 布局策略纯函数输入
 *
 * 包含策略布局所需的一切信息，不包含任何 RenderNode 引用。
 * 所有字段 readonly，构造后不可变。
 */
class LayoutInput
{
    public readonly LayoutConstraints $constraints;
    public readonly ComputedStyle $style;
    public readonly string $textContent;
    public readonly array $childResults;
    public readonly string $position;

    // ── 多阶段布局增强字段 ──

    /** @var RenderNode[] 子节点引用 */
    public readonly array $childNodes;
    public readonly ?Closure $reResolveChild;
    public readonly ?Closure $measureIntrinsic;
    public readonly int $iteration;

    // ── 定位祖先信息 ──

    public readonly ?int $ancestorX;
    public readonly ?int $ancestorY;
    public readonly ?int $ancestorW;
    public readonly ?int $ancestorH;
    public readonly int $ancestorBorderLeft;
    public readonly int $ancestorBorderTop;
    public readonly int $ancestorPaddingLeft;
    public readonly int $ancestorPaddingTop;
    public readonly int $viewportW;
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
        array $childNodes = [],
        ?Closure $reResolveChild = null,
        ?Closure $measureIntrinsic = null,
        int $iteration = 0,
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
        $this->childNodes         = $childNodes;
        $this->reResolveChild     = $reResolveChild;
        $this->measureIntrinsic   = $measureIntrinsic;
        $this->iteration          = $iteration;
    }
}
