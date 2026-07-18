<?php

namespace Px\Layout;

use native_types;
use Px\Css\ComputedStyle;
use Px\Render\RenderNode;

/**
 * PhysicalFragmentBuilder — Fragment 构建器（替代 10+ 参数构造函数）
 *
 * 对标 Blink 的 BoxFragmentBuilder。提供链式 API：
 *   $frag = (new PhysicalFragmentBuilder())
 *       ->x(100)->y(200)->w(300)->h(150)
 *       ->style($s)->children([$child])
 *       ->build();
 */
class PhysicalFragmentBuilder
{
    private int $_x = 0;
    private int $_y = 0;
    private int $_w = 0;
    private int $_h = 0;
    private int $_vw = 0;
    private int $_vh = 0;
    private int $_layer = 0;
    private int $_cw = 0;
    private int $_ch = 0;
    private ?ComputedStyle $_style = null;
    private array $_children = [];
    private ?RenderNode $_sourceNode = null;
    // ── 新增：自包含元数据字段 ──
    private string $_type = '';
    private mixed $_content = null;
    private array $_dataset = [];
    private array $_pseudoStyles = [];
    private int $_availableWidth = 0;
    private int $_scrollTop = 0;
    private int $_scrollLeft = 0;
    private bool $_isScrollContainer = false;

    public function x(int $v): self { $this->_x = $v; return $this; }
    public function y(int $v): self { $this->_y = $v; return $this; }
    public function w(int $v): self { $this->_w = $v; return $this; }
    public function h(int $v): self { $this->_h = $v; return $this; }
    public function vw(int $v): self { $this->_vw = $v; return $this; }
    public function vh(int $v): self { $this->_vh = $v; return $this; }
    public function layer(int $v): self { $this->_layer = $v; return $this; }
    public function cw(int $v): self { $this->_cw = $v; return $this; }
    public function ch(int $v): self { $this->_ch = $v; return $this; }
    public function style(?ComputedStyle $v): self { $this->_style = $v; return $this; }
    public function children(array $v): self { $this->_children = $v; return $this; }
    public function sourceNode(?RenderNode $v): self { $this->_sourceNode = $v; return $this; }
    public function type(string $v): self { $this->_type = $v; return $this; }
    public function content(mixed $v): self { $this->_content = $v; return $this; }
    public function dataset(array $v): self { $this->_dataset = $v; return $this; }
    public function pseudoStyles(array $v): self { $this->_pseudoStyles = $v; return $this; }
    public function availableWidth(int $v): self { $this->_availableWidth = $v; return $this; }
    public function scrollTop(int $v): self { $this->_scrollTop = $v; return $this; }
    public function scrollLeft(int $v): self { $this->_scrollLeft = $v; return $this; }
    public function isScrollContainer(bool $v): self { $this->_isScrollContainer = $v; return $this; }

    /** 从现有 Fragment 拷贝属性 */
    public function from(PhysicalFragment $f): self
    {
        $this->_x = $f->getX();
        $this->_y = $f->getY();
        $this->_w = $f->getW();
        $this->_h = $f->getH();
        $this->_vw = $f->getVisualW();
        $this->_vh = $f->getVisualH();
        $this->_layer = $f->getLayer();
        $this->_cw = $f->getContentWidth();
        $this->_ch = $f->getContentHeight();
        $this->_style = $f->style;
        $this->_children = $f->children;
        $this->_sourceNode = $f->sourceNode ?? null;
        $this->_type = (string)$f->type;
        $this->_content = $f->content;
        $this->_dataset = (array)$f->dataset;
        $this->_pseudoStyles = (array)$f->pseudoStyles;
        $this->_availableWidth = (int)$f->availableWidth;
        $this->_scrollTop = (int)$f->scrollTop;
        $this->_scrollLeft = (int)$f->scrollLeft;
        $this->_isScrollContainer = (bool)$f->isScrollContainer;
        return $this;
    }

    public function build(): PhysicalFragment
    {
        return new PhysicalFragment(
            $this->_x, $this->_y, $this->_w, $this->_h,
            $this->_vw, $this->_vh, $this->_layer,
            $this->_cw, $this->_ch,
            $this->_style, $this->_children,
            $this->_sourceNode,
            $this->_scrollTop, $this->_scrollLeft, $this->_isScrollContainer,
            $this->_type, $this->_content, $this->_dataset, $this->_pseudoStyles,
            $this->_availableWidth,
        );
    }
}
