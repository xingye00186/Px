<?php

namespace Px\Dom;
use Px\Component\Contracts\ReactiveComponentInterface;
use Px\Css\StyleRecalcPass;
use Px\Css\StyleResolver;
use Px\Render\RenderTreeManager;

use native_types;
use Px\Css\ComputedStyle;

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

    /** 组件组 ID (默认 'app') */
    public string $groupId = 'app';

    // ===== 组件占位字段 =====

    /** 是否为组件占位节点 (#component 类型) */
    public bool $isComponent = false;

    /** 组件类名: 'NumPadComponent' */
    public ?string $componentClass = null;

    /** 运行时的子组件实例 */
    public ?\Px\Component\Contracts\ReactiveComponentInterface $componentInstance = null;

    /** bind 映射: ['childProp' => 'parentExpr']，运行时由 Application 展开 */
    public ?array $componentProps = null;

    /**
     * 预计算组件属性值（仅 v-for 循环使用）。
     * 由 v-for render helper 在循环体内直接赋值，
     * expandComponentNode 优先使用此值，绕开 bind key 查找。
     * @var array<string, string>|null
     */
    public ?array $componentPropValues = null;

    /**
     * 父组件传递的定位偏移（仅 #component 节点使用）。
     * 由 Application::expandComponentNode / matchComponentNode 设置，
     * RenderTreeManager::updateFromVNode 消费。
     * 存放 left/top 像素值，如 ['left' => 11, 'top' => 260]。
     * 替代 transferComponentPositioning() 对子 VNode props['style'] 的直接修改，
     * 使 VNode 保持不可变。
     *
     * @var array{left?:int, top?:int}|null
     */
    public ?array $layoutOffset = null;

    /**
     * 由 StyleRecalcPass 填充的计算后样式（Phase 0.5 引入）。
     * 后续 RenderTreeManager 直接从此读取，不再内联调用 StyleResolver。
     */
    public ?ComputedStyle $computedStyle = null;

    // ===== Vue 3 patchFlag 兼容（编译器级动态绑定标记）=====

    /** 无动态绑定（完全静态） */
    public const PATCH_NONE  = 0;
    /** :style 动态绑定 */
    public const PATCH_STYLE = 1;
    /** :class 动态绑定 */
    public const PATCH_CLASS = 2;
    /** @ 事件动态绑定 */
    public const PATCH_EVENT = 4;
    /** v-for / v-if 结构动态 */
    public const PATCH_STRUCT = 8;
    /** :value, :disabled, :src 等动态属性（非 style/class） */
    public const PATCH_PROPS = 16;
    /** {{ }} 动态文本内容 */
    public const PATCH_TEXT  = 32;
    /** 全部动态（默认，未优化） */
    public const PATCH_ALL   = 63;

    /**
     * 编译器标记的 patch flags（Vue 3 patchFlag 兼容语义）。
     * 0 = 完全静态；非零 = 标记位表示的属性可能变化。
     * 运行时 patchVNodeTree 据此选择性更新，跳过未标记的属性。
     */
    public int $patchFlags = 0;

    /**
     * Fluent setter for patchFlags（编译器生成的代码使用）。
     * 返回 $this 以支持链式调用：VNode::h('div', [...])->withPatchFlags(1)
     */
    public function withPatchFlags(int $flags): self
    {
        $this->patchFlags = $flags;
        return $this;
    }

    // ===== Vue 3 Block Tree 兼容（编译器级扁平动态子孙数组）=====

    /**
     * Block Tree — 扁平化的动态子孙节点数组（Vue 3 openBlock/createBlock 语义）。
     *
     * 语义:
     *   - null       = 不是 block root（走 patchVNodeTree 原始递归 diff）
     *   - 非 null    = 是 block root，patchVNodeTree 走快速路径迭代此数组，跳过静态子树递归
     *   - 空数组 []  = 是 block root 且无动态子孙（跳过所有子节点 diff）
     *
     * 收集规则:
     *   - 只收集 patchFlags != 0 或 isComponent 的子孙（跳过完全静态的中间层）
     *   - Block 边界: 组件根、v-if 分支根、v-for 循环元素（各自独立 block）
     *   - v-if 切换分支时，dynamicChildren 数组长度可能变化 → 快速路径需在此情况下降级到全 diff
     *
     * @var VNode[]|null
     */
    public ?array $dynamicChildren = null;

    /**
     * Fluent setter for dynamicChildren（编译器生成的代码使用）。
     * 返回 $this 以支持链式调用。
     *
     * @param VNode[] $children
     */
    public function withDynamicChildren(array $children): self
    {
        $this->dynamicChildren = $children;
        return $this;
    }

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

    /**
     * hComponent('NumPadComponent', { style: '...' }, { value: 'display' })
     *
     * 创建组件占位节点。编译器生成此节点用于运行时展开。
     *
     * @param string $componentClass  组件类名
     * @param array|null $props       常规 HTML 属性 (style, class, v-if 等)
     * @param array|null $componentProps bind 映射: ['childProp' => 'parentExpr']
     * @return VNode
     */
    public static function hComponent(
        string $componentClass,
        ?array $props = null,
        ?array $componentProps = null
    ): VNode {
        $node = new VNode('#component', $props, null);
        $node->isComponent = true;
        $node->componentClass = $componentClass;
        $node->componentProps = $componentProps;
        return $node;
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

    /** 是否为组件占位节点 */
    public function isComponent(): bool
    {
        return $this->type === '#component';
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

    // ── 静态工具方法 ──────────────────────────

    /**
     * 将 VNode children 统一为 VNode 数组。
     * 消除重复实现（Application + RenderTreeManager 各自维护了一份）。
     *
     * @param mixed $children VNode->children 值
     * @return VNode[]
     */
    public static function childrenToArray(mixed $children): array
    {
        if ($children === null) return [];
        if ($children instanceof VNode) return [$children];
        if (is_array($children)) {
            $result = [];
            foreach ($children as $c) {
                if ($c instanceof VNode) {
                    $result[] = $c;
                } elseif ($c !== null && $c !== false) {
                    // AOT 兼容: php::Variant 上 is_string() 可能返回 false（#text 子节点丢失）
                    // 改用 (string) 强制转换 + 非空检查
                    $strVal = (string)$c;
                    if ($strVal !== '') {
                        $result[] = new VNode('#text', null, $strVal);
                    }
                }
            }
            return $result;
        }
        return [];
    }
}
