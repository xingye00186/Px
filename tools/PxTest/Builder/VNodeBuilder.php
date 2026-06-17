<?php

namespace PxTest\Builder;

use Px\Rendering\VNode;

/**
 * Fluent Builder: 链式构造 VNode 树。
 *
 * 目标：测试中无直接 `new VNode()` 的复杂嵌套，全部通过 Builder 构造。
 *
 * 用法:
 *   $vnode = VNodeBuilder::div()
 *       ->style(['display' => 'flex'])
 *       ->child('span', 'Hello')
 *       ->build();
 *
 *   $root = VNodeBuilder::root()
 *       ->childBuilder(VNodeBuilder::div()->style(['width' => '100px']))
 *       ->build();
 */
class VNodeBuilder
{
    private string $type;
    private array $props = [];
    /** @var VNodeBuilder[] */
    private array $childBuilders = [];
    /** @var array{type: string, content: string, props: array}[] */
    private array $rawChildren = [];

    private function __construct(string $type)
    {
        $this->type = $type;
    }

    /** 创建 #root 节点 */
    public static function root(): self
    {
        return new self('#root');
    }

    /** 创建 div 节点 */
    public static function div(): self
    {
        return new self('div');
    }

    /** 创建 span 节点 */
    public static function span(string $text = '', array $props = []): self
    {
        $b = new self('span');
        if ($text !== '') {
            $b->rawChildren[] = ['type' => '#text', 'content' => $text, 'props' => []];
        }
        if ($props) {
            $b->props = $props;
        }
        return $b;
    }

    /** 创建 button 节点 */
    public static function button(string $text = ''): self
    {
        $b = new self('button');
        if ($text !== '') {
            $b->rawChildren[] = ['type' => '#text', 'content' => $text, 'props' => []];
        }
        return $b;
    }

    /** 创建 #component 占位节点 */
    public static function component(string $componentClass, array $props = []): self
    {
        $b = new self('#component');
        $b->props = $props;
        $b->props['_componentClass'] = $componentClass;
        $b->props['isComponent'] = true;
        return $b;
    }

    /** 设置内联样式 (字符串形式) */
    public function style(string|array $styles): self
    {
        if (is_array($styles)) {
            $parts = [];
            foreach ($styles as $k => $v) {
                $parts[] = "$k:$v";
            }
            $this->props['style'] = implode(';', $parts);
        } else {
            $this->props['style'] = $styles;
        }
        return $this;
    }

    /** 设置单个 HTML 属性 */
    public function prop(string $key, mixed $value): self
    {
        $this->props[$key] = $value;
        return $this;
    }

    /** 批量设置属性 */
    public function props(array $props): self
    {
        $this->props = array_merge($this->props, $props);
        return $this;
    }

    /** 设置 class */
    public function class(string|array $classes): self
    {
        $this->props['class'] = is_array($classes) ? implode(' ', $classes) : $classes;
        return $this;
    }

    /** 设置 @click 事件处理器 */
    public function onClick(string $handler, ?string $arg = null): self
    {
        $key = $arg !== null ? "@click=$handler($arg)" : "@click=$handler";
        $this->props[$key] = '';
        return $this;
    }

    /** 设置 v-for */
    public function vFor(string $expression): self
    {
        $this->props['v-for'] = $expression;
        return $this;
    }

    /** 设置 :bind */
    public function bind(string $key): self
    {
        $this->props[":$key"] = '';
        return $this;
    }

    /** 添加纯文本子节点 */
    public function childText(string $text): self
    {
        $this->rawChildren[] = ['type' => '#text', 'content' => $text, 'props' => []];
        return $this;
    }

    /** 添加子 VNodeBuilder */
    public function childBuilder(VNodeBuilder $builder): self
    {
        $this->childBuilders[] = $builder;
        return $this;
    }

    /** 添加子节点 (便捷: type + text + props) */
    public function child(string $type, string $text = '', array $props = []): self
    {
        $child = ['type' => $type, 'content' => $text, 'props' => $props];
        if ($text !== '') {
            // 文本放在 children 中
            $child['children'] = VNode::h('#text', [], $text);
        }
        $this->rawChildren[] = $child;
        return $this;
    }

    /** 添加多个相同类型子节点 */
    public function children(string $type, array $texts, array $props = []): self
    {
        foreach ($texts as $text) {
            $this->child($type, $text, $props);
        }
        return $this;
    }

    /** 构建 VNode 树 */
    public function build(): VNode
    {
        $children = [];

        foreach ($this->rawChildren as $raw) {
            if ($raw['type'] === '#text') {
                $children[] = VNode::h('#text', [], $raw['content']);
            } else {
                $props = $raw['props'];
                $content = $raw['content'] ?? '';
                $child = VNode::h($raw['type'], $props, $content);
                $children[] = $child;
            }
        }

        foreach ($this->childBuilders as $builder) {
            $children[] = $builder->build();
        }

        if (count($children) === 1) {
            return VNode::h($this->type, $this->props, $children[0]);
        }

        return VNode::h($this->type, $this->props, $children);
    }
}
