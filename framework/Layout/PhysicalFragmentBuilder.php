<?php

namespace Px\Layout;

use native_types;
use Px\Css\ComputedStyle;

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
    private $_sourceNode = null;

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
    public function sourceNode($v): self { $this->_sourceNode = $v; return $this; }

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
        );
    }
}
