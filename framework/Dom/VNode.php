<?php

namespace Px\Dom;
use Px\Component\Contracts\ReactiveComponentInterface;
use Px\Css\StyleRecalcPass;
use Px\Css\InlineStyleParser;
use Px\Render\RenderTreeManager;

use native_types;
use Px\Css\ComputedStyle;

/**
 * VNode — Vue 3 兼容的虚拟 DOM 节点
 *
 * 全链路统一数据结构:
 *   - 编译期: template-parser 产出 VNode 树 (AST)
 *   - 运行时: 组件 render() 返回 VNode 树
 *   - 渲染层: RenderTreeManager 将 VNode 树转为 RenderNode 树，PaintPipeline 遍历 Fragment 树绘制
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
     * 后续 RenderTreeManager 直接从此读取，不再内联调用 InlineStyleParser。
     */
    public ?ComputedStyle $computedStyle = null;

    /**
     * C2.9：StyleRecalcPass 预计算的伪类/伪元素叠加声明
     *（state => (property => value)，供 RenderTreeManager → RenderNode::$pseudoStyles
     * → PaintPipeline 消费）。与 $computedStyle 同为“样式重算通行证预算写入”
     * 约定；此前 resolve() 的 by-ref pseudoStyles 在 StyleRecalcPass 内为局部
     * 变量而被丢弃，RTM 只能回落注册表重算（生产恒空）。
     */
    public array $pseudoStyles = [];

    /**
     * C4.1 增量样式重算自验证缓存（对标 Blink NeedsStyleRecalc /
     * ChildNeedsStyleRecalc 的“无需重算则不下降”语义）。
     *
     * Px 与 Blink 的差异：VNode 每帧重建，不存在持久 DOM 可挂脏位。但
     * 编译器会**提升静态子树**（gen 的 static $__sN 缓存），该类节点跳
     * 帧为同一实例且 props 恒定——此时只需确认外部输入（父 ComputedStyle
     * 身份 + 上下文指纹 + 规则表代次）未变，即可跳过**整棵子树**。
     * styleParentCS 存上次据以计算的父 ComputedStyle（身份比较，O(1)）。
     */
    public ?ComputedStyle $styleParentCS = null;

    /** C4.1：上次重算时的外部上下文——**分项存储**以避免每帧建字符串。 */
    public int $styleCtxGen = -1;          // StyleEngine 规则表代次
    public string $styleCtxParentClass = '';
    public int $styleCtxIndex = 0;         // 1-based 元素序（nth-child）
    public string $styleCtxSibSig = '';    // 前序兄弟（仅兄弟组合子规则存在时非空）

    /**
     * C4.1 样式脏位（对标 Blink NeedsStyleRecalc）。新建节点默认脏（必算）；
     * patchProps 在样式相关 props 发生变化时置脏（实例跳帧复用且 props
     * 原地改写，故仅靠实例身份无法判定）；StyleRecalcPass 重算后清除。
     */
    public bool $needsStyleRecalc = true;

    /**
     * C4.1 子树脏位（对标 Blink ChildNeedsStyleRecalc）：后代中存在需重算节点。
     * clean 且 !childNeedsStyleRecalc 时，StyleRecalcPass 可跳过**整棵子树**递归。
     */
    public bool $childNeedsStyleRecalc = true;

    /**
     * C4.1：上次重算时的父节点（由 StyleRecalcPass 写入）。实例跳帧复用使
     * 该链在下一帧 patch 期仍有效，供脏位向上传播（patch 自顶向下递归，
     * 无现成父指针）。
     */
    public ?VNode $styleParentNode = null;

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

    // ===== B-Phase 2.5: #list VNode 专属 patchFlag（对齐 Vue 3 FRAGMENT flag 数值）=====
    /** #list 子节点顺序与长度均在编译期确定（多根组件 / v-for 常量 source） */
    public const PATCH_STABLE_LIST   = 64;
    /** #list 子节点带 :key，需走 keyed diff */
    public const PATCH_KEYED_LIST    = 128;
    /** #list 子节点无 :key，需走 unkeyed diff */
    public const PATCH_UNKEYED_LIST  = 256;

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

    /**
     * hList([$child1, $child2, ...], VNode::PATCH_KEYED_LIST)
     *
     * 创建 #list VNode（B-Phase 2.5）—— v-for helper 返回产物 / 多根组件层容器，
     * 语义与 Vue 3 Fragment 对齐，命名为 `#list` 避免与 Px layout `PhysicalFragment` 碰撞。
     *
     * 语义：
     *   - `type = '#list'`
     *   - `patchFlags` 为 PATCH_STABLE_LIST / PATCH_KEYED_LIST / PATCH_UNKEYED_LIST 之一
     *   - `children` 数组包含真实子节点
     *   - layout / paint 层看不到 #list（childrenToArray 会展平）
     *   - patchVNodeTree 看到 #list 走 patchChildrenArray（按 patchFlag 分流）
     *
     * @param VNode[] $children
     * @param int $listFlag PATCH_STABLE_LIST / PATCH_KEYED_LIST / PATCH_UNKEYED_LIST
     */
    public static function hList(array $children, int $listFlag): VNode
    {
        $node = new VNode('#list', null, $children);
        $node->patchFlags = $listFlag;
        return $node;
    }

    /**
     * hComment() —— v-if 假分支占位符（对齐 Vue 3 createCommentVNode('v-if', true)））
     *
     * 作用：保持父级 dynamicChildren / children 长度稳定，让 patchVNodeTree
     * 能命中 block fast-path。v-if 从真变假时，dynamicChildren 内对应位置就变
     * comment 占位（反之亦然）——长度不变，fast-path 无需降级。
     *
     * 语义：
     *   - `type = '#comment'`，无 props / children
     *   - layout / paint 层直接忽略（childrenToArray 展平时跳过）
     *   - patchVNodeTree 遇到双侧都是 #comment 直接 return（无 op）
     */
    public static function hComment(): VNode
    {
        return new VNode('#comment', null, null);
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
     * B-Phase 2.5：遇到 `#list` VNode 时会**递归展平其 children**，
     * 让 layout / paint 层看不到 #list 层（transparent container）。
     * 而 patchVNodeTree 直接访问 `$vnode->children` 原始值，仍能看到 #list 走 fast-path。
     *
     * @param mixed $children VNode->children 值
     * @return VNode[]
     */
    public static function childrenToArray(mixed $children): array
    {
        if ($children === null) return [];
        if ($children instanceof VNode) {
            // #list 透传：展平其 children
            if ($children->type === '#list') {
                return self::childrenToArray($children->children);
            }
            // #comment 透传：v-if 占位符，layout / paint 层看不到
            if ($children->type === '#comment') {
                return [];
            }
            return [$children];
        }
        if (is_array($children)) {
            $result = [];
            foreach ($children as $c) {
                if ($c instanceof VNode) {
                    if ($c->type === '#list') {
                        // #list 透传：递归展平其 children 到本层结果
                        foreach (self::childrenToArray($c->children) as $sub) {
                            $result[] = $sub;
                        }
                    } elseif ($c->type === '#comment') {
                        // #comment 透传：直接跳过（layout 层不存在）
                        continue;
                    } else {
                        $result[] = $c;
                    }
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
