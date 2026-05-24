<?php

namespace Px\Rendering;

/**
 * VNode — Vue 3 兼容的虚拟 DOM 节点
 *
 * 全链路统一数据结构:
 *   - 编译期: template-parser 产出 VNode 树 (AST)
 *   - 运行时: 组件 render() 返回 VNode 树
 *   - 渲染器: VNodeRenderer 遍历 VNode 树生成 GDI 调用
 *
 * 与 Vue 3 VNode 的对齐:
 *   - type:  标签名 ('div','span','button','input','#text','#root') 或组件类名
 *   - props: HTML 属性 + Vue 指令 (:bind, @click, v-if, v-for, v-model)
 *   - children: 子 VNode 数组 或 文本字符串
 *   - key:    v-for 场景的 diff 键
 *
 * PHP 8.4 兼容:
 *   - 使用类型声明 (string, ?array, int, bool)
 *   - h() 静态工厂方法返回 VNode (代替 new VNode(...))
 *   - AOT 安全: 无动态属性访问, 无闭包递归
 */
class VNode
{
    // ===== Vue 3 核心字段 =====

    /** 标签名: 'div','span','button','input','#text','#root', 组件类名 */
    public string $type;

    /** HTML 属性 + Vue 指令: ['class'=>'foo', 'style'=>'...', ':bind'=>'prop', '@click'=>'handler'] */
    public ?array $props;

    /** 子节点: VNode[] | string | null (#text 节点用 string, 组件用 null) */
    public mixed $children;

    /** v-for 场景的 key (用于 diff/diffChildren) */
    public ?string $key;

    // ===== 布局字段 (LayoutResolver 计算) =====

    /** 计算后的 X 坐标 (绝对值) */
    public int $x = 0;

    /** 计算后的 Y 坐标 (绝对值) */
    public int $y = 0;

    /** 计算后的宽度 */
    public int $w = 0;

    /** 计算后的高度 */
    public int $h = 0;

    /** 计算后的 CSS 样式 (从 props['style'] 解析 + 默认值) */
    public array $computedStyle = [];

    // ===== 运行时渲染字段 =====

    /** z-order 层级 (0 = base, 1+ = overlay) */
    public int $layer = 0;

    /** 组件组 ID (默认 'app') */
    public string $groupId = 'app';

    /** 是否为滚动容器 */
    public bool $isScrollContainer = false;

    /** 当前滚动位置 (px) */
    public int $scrollTop = 0;

    /** 滚动内容总高度 (px) */
    public int $contentHeight = 0;

    // ===== 构造器 =====

    /**
     * @param string $type      标签名
     * @param array|null $props HTML 属性 + Vue 指令
     * @param mixed $children   VNode[] | string | null
     * @param string|null $key  v-for 键
     */
    public function __construct(
        string $type,
        ?array $props = null,
        $children = null,
        ?string $key = null
    ) {
        $this->type     = $type;
        $this->props    = $props;
        $this->children = $children;
        $this->key      = $key;
    }

    // ===== 静态工厂方法 (Vue 3 h() 风格) =====

    /**
     * h('div', { class: 'foo' }, [child1, child2])
     * h('span', { ':bind': 'title' }, 'Hello')
     * h('#root', { title: 'App', style: 'width:400px;height:500px' }, [...])
     *
     * @param string $type      标签名
     * @param array|null $props HTML 属性
     * @param mixed $children   子节点
     * @return VNode
     */
    public static function h(string $type, ?array $props = null, mixed $children = null): VNode
    {
        return new VNode($type, $props, $children, null);
    }

    /**
     * hKey('div', { ':key': 'item-1' }, [...])
     * 带显式 key 的工厂方法 (用于 v-for 场景)
     */
    public static function hKey(string $type, ?array $props, $children, string $key): VNode
    {
        return new VNode($type, $props, $children, $key);
    }

    // ===== 属性读取辅助 =====

    /**
     * 从 props 中获取属性值
     */
    public function getProp(string $name, mixed $default = null): mixed
    {
        return $this->props[$name] ?? $default;
    }

    /**
     * 检查 props 中是否存在某属性
     */
    public function hasProp(string $name): bool
    {
        return isset($this->props[$name]);
    }

    /**
     * 获取 CSS class (从 props['class'])
     */
    public function getClass(): string
    {
        return $this->props['class'] ?? '';
    }

    /**
     * 从 props['style'] 解析 inline style 为键值对数组
     */
    public function getInlineStyle(): array
    {
        $styleStr = $this->props['style'] ?? '';
        if ($styleStr === '') {
            return [];
        }
        return CssMappings::parseInlineStyle($styleStr);
    }

    /**
     * 获取事件处理器名 (从 props['@event'])
     */
    public function getEventHandler(string $event): string
    {
        return $this->props['@' . $event] ?? '';
    }

    // ===== 类型检查辅助 =====

    /** 是否为文本节点 */
    public function isText(): bool
    {
        return $this->type === '#text';
    }

    /** 是否为根节点 */
    public function isRoot(): bool
    {
        return $this->type === '#root';
    }

    /** 是否为 HTML 元素节点 */
    public function isElement(): bool
    {
        return !$this->isText() && !$this->isRoot();
    }

    /** 子节点是否为文本 (对 GDI 渲染: text 类型) */
    public function hasTextChildren(): bool
    {
        return is_string($this->children);
    }

    /** 子节点是否为数组 */
    public function hasArrayChildren(): bool
    {
        return is_array($this->children) || $this->children instanceof VNode;
    }

    /** 获取子节点数量 */
    public function childCount(): int
    {
        if ($this->children instanceof VNode) {
            return 1;
        }
        if (is_array($this->children)) {
            return count($this->children);
        }
        return 0;
    }
}
