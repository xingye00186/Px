<?php

namespace Px\Css;
use Px\Render\RenderTreeManager;

use native_types;

use Px\Dom\VNode;

use Px\Css\InlineStyleParser;

/**
 * StyleRecalcPass — 独立样式重算通行证
 *
 * Phase 0.5 产物：将样式解析从 RenderTreeManager 中抽离为独立阶段。
 */
class StyleRecalcPass
{
    public function recalc(VNode $root, ?ComputedStyle $parentCS = null, string $parentClassStr = '', array $precedingSiblingClasses = [], array $ancestorClassLists = [], array $ancestorCtx = [], array $prevSiblingCtx = [], int $elemIndex = 1): void
    {
        if ($root->isComponent) {
            // Component 节点不直接渲染，展开后由子组件管理
            return;
        }

        $inlineStyle = $root->props['style'] ?? [];
        // 运行时 style 字符串（动态构建的 VNode，如测试 harness）需解析为声明数组；
        // 编译期数组化路径（SFC 组件）已是数组，直接使用。与 RenderTreeManager
        // 处理 placeholder style 的 is_string ? parseInlineStyle : ... 逻辑一致。
        if (is_string($inlineStyle)) {
            $inlineStyle = $inlineStyle !== '' ? InlineStyleParser::parseInlineStyle($inlineStyle) : [];
        } elseif (!is_array($inlineStyle)) {
            $inlineStyle = [];
        }
        $className = $root->props['class'] ?? '';

        // #text 节点无自身样式：直接复用父 ComputedStyle（identity 稳定，避开估算开销）
        // 若无父（顶层预防护理论上不该发生），够用 empty 单例兑底
        if ($root->type === '#text') {
            $root->computedStyle = $parentCS ?? StylePool::empty();
            \Px\Core\PerfCounter::inc('style_recalc_text_skip');
            return;
        }

        $pseudoStyles = [];
        // ── C4.1 逐节点 clean 跳过（对标 Blink NeedsStyleRecalc）──
        // 四重条件均成立时，本节点计算样式必与上帧一致，可跳过
        // resolve（含 elementCtx 构建、引擎匹配、指纹与 StylePool 查）：
        //   1. 已有上帧结果（computedStyle 非 null）
        //   2. 样式相关 props 未变（!styleDirty，patchProps 精确置位）
        //   3. 父 ComputedStyle **身份**相同（StylePool 内驻：同值即同对象）
        //   4. 外部上下文指纹相同（含规则表代次）
        // 注：**仍递归子层**——子节点可能自行脏。整棵子树跳过需在 patch 期
        // 向上传播 childStyleDirty（Blink ChildNeedsStyleRecalc），属后续增量。
        $ctxSig = self::styleCtxSig($parentClassStr, $precedingSiblingClasses, $ancestorClassLists, $elemIndex);
        $clean = self::$incrementalEnabled
            && $root->computedStyle !== null
            && !$root->styleDirty
            && $root->styleParentCS === $parentCS
            && $root->styleCtxSig === $ctxSig;
        if ($clean) {
            \Px\Core\PerfCounter::inc('style_recalc_node_skip');
            $computedStyle = $root->computedStyle;
        } else {
            // 观测点：分条件归因 clean 未命中的原因（对标 Blink 的 recalc 统计）。
            if ($root->computedStyle === null) {
                \Px\Core\PerfCounter::inc('style_recalc_miss_nostyle');
            } elseif ($root->styleDirty) {
                \Px\Core\PerfCounter::inc('style_recalc_miss_dirty');
            } elseif ($root->styleParentCS !== $parentCS) {
                \Px\Core\PerfCounter::inc('style_recalc_miss_parentcs');
            } else {
                \Px\Core\PerfCounter::inc('style_recalc_miss_ctxsig');
            }

        // C2.5-full：构建 SelectorChecker 元素上下文（仅引擎活跃时，避免
        // 生产无用开销）。引擎空 → 空上下文 → resolve 不走引擎分支。
        $elementCtx = [];
        if (StyleEngine::ruleCount() > 0) {
            $elementCtx = self::buildElementCtx($root, $className, $ancestorCtx, $prevSiblingCtx, $elemIndex);
        }
        $computedStyle = InlineStyleParser::resolve(
            inlineStyle: $inlineStyle,
            className: $className,
            parentCS: $parentCS,
            elementType: $root->type,
            parentClassStr: $parentClassStr,
            // C2.4：传真实前序兄弟 class（修§1.3.3 运行时兄弟组合子 +/~
            // 恒传空失效）；由父级子循环按文档序累积传入。
            precedingSiblingClasses: $precedingSiblingClasses,
            pseudoStyles: $pseudoStyles,
            // C2.5：传完整祖先 class 链（根→直接父），使后代组合子跨中间
            // 元素匹配任意祖先（与编译期 MiscHelper 语义一致，CSS Selectors L3）。
            ancestorClassLists: $ancestorClassLists,
            // C2.5-full：StyleEngine 全 AST 匹配所需元素上下文。
            elementCtx: $elementCtx
        );

        }   // end else（非 clean 分支）

        $root->computedStyle = $computedStyle;
        // C4.1：记录本次据以计算的外部输入，并清除脏位。
        $root->styleParentCS = $parentCS;
        $root->styleCtxSig = $ctxSig;
        $root->styleDirty = false;
        // C2.9：持久化伪类叠加（引擎/注册表产出）——此前为局部变量而丢弃，
        // 致 RTM 只能回落注册表重算（生产恒空）。与 computedStyle 同约定。
        if (!empty($pseudoStyles)) {
            $root->pseudoStyles = $pseudoStyles;
        }

        // 根因治本：旧 `is_array(...) ? ... : []` 对**单 VNode 子**（VNode::h(t, p,
        // $child)，如 run_minimal_pipeline 的 #root）得 [] → 整棵子树样式重算被
        // 静默跳过，computedStyle 恒 null，RTM 只能回落（旧注册表无需
        // elementCtx 故被掩盖；C2.5 引擎化后 elementCtx 缺失 → 规则不应用）。
        // 数组路径仍直接复用（写时复制零分配，且不合成多余 #text VNode）。
        $children = is_array($root->children)
            ? $root->children
            : VNode::childrenToArray($root->children);
        // 前序兄弟 class 串累积（文档序）：供子层运行时兄弟组合子匹配。
        $siblingAcc = [];
        // 子层祖先链 = 当前链 + 本节点自身 class（根→直接父）。
        $childAncestors = $ancestorClassLists;
        if ($className !== '') {
            $childAncestors[] = $className;
        }
        // C2.5-full：子层元素上下文链（根→直接父）+ 兄弟上下文累积 + 元素序。
        $childAncCtx = $ancestorCtx;
        if (!empty($elementCtx)) {
            $childAncCtx[] = $elementCtx;
        }
        $sibCtxAcc = [];
        // 特征门控（对标 Blink RuleFeatureSet::usesSiblingRules）：无兄弟组合子
        // 规则时不维护前序兄弟账本。原本 $siblingAcc 无条件累积每个兄弟的
        // class 串，而它进入 StylePool key 的兄弟指纹：第 k 个子指纹含前 k-1 个
        // class，故 n 个同类兄弟产生 n 个**互异**池条目且指纹长 O(n) —— O(n²)
        // 字符串内存，且丧失本该全部命中的池复用。
        // C2.9：ThemeProvider 注册表已删除，此处为纯特征门控。
        $trackSiblings = StyleEngine::usesSiblingRules();
        $childIdx = 1;
        foreach ($children as $child) {
            if ($child instanceof VNode) {
                // 递归直传父 ComputedStyle 对象（O(1) 身份），不再传 toExportArray()
                $this->recalc($child, $computedStyle, $className, $siblingAcc, $childAncestors, $childAncCtx, $sibCtxAcc, $childIdx);
                $cc = $child->props['class'] ?? '';
                if ($trackSiblings && is_string($cc) && $cc !== '') {
                    $siblingAcc[] = $cc;
                }
                if (!empty($elementCtx) && $trackSiblings && $child->type !== '#text') {
                    $sibCtxAcc[] = self::buildElementCtx($child, is_string($cc) ? $cc : '', $childAncCtx, [], $childIdx);
                }
                if ($child->type !== '#text') {
                    $childIdx++;
                }
            }
        }
    }

    /**
     * C2.5-full：由 VNode 构建 SelectorChecker 元素上下文（纯数组，AOT 友好）。
     * 对标 Blink Element 选择器匹配所需数据要素：tag/id/classes/attrs/
     * index（1-based 元素序，供 nth-child）/ancestors（根→直接父）/prevSiblings。
     */
    /**
     * C4.1：增量样式重算开关。默认开（已经单测钉/三层验证）；置 false 可
     * 强制全量重算，用于 A/B 测量与回归二分定位。
     */
    public static bool $incrementalEnabled = true;

    /**
     * C4.1：外部上下文指纹——除节点自身 props 之外、一切能影响本节点及其
     * 子树计算样式的输入。父 ComputedStyle 不入指纹（已由身份比较覆盖）。
     *
     * 规则表代次必须入指纹：运行中新注册组件会改变匹配结果，旧缓存
     * 必须失效（否则子树跳过会保留陈旧样式）。
     */
    private static function styleCtxSig(
        string $parentClassStr,
        array $precedingSiblingClasses,
        array $ancestorClassLists,
        int $elemIndex
    ): string {
        $sig = StyleEngine::generation() . '|' . $parentClassStr . '|' . $elemIndex . '|';
        foreach ($precedingSiblingClasses as $s) {
            $sig .= $s . ',';
        }
        $sig .= '|';
        foreach ($ancestorClassLists as $a) {
            if (is_array($a)) {
                foreach ($a as $ac) { $sig .= $ac . '.'; }
            } else {
                $sig .= (string)$a;
            }
            $sig .= ';';
        }
        return $sig;
    }

    private static function buildElementCtx(VNode $node, string $className, array $ancestorCtx, array $prevSiblingCtx, int $index): array
    {
        $classes = [];
        if ($className !== '') {
            foreach (explode(' ', $className) as $c) {
                if ($c !== '') $classes[] = $c;
            }
        }
        $attrs = [];
        $props = is_array($node->props) ? $node->props : [];
        foreach ($props as $pk => $pv) {
            if ($pk === 'style' || $pk === 'class') continue;
            if (is_string($pv) || is_int($pv) || is_float($pv)) {
                $attrs[(string)$pk] = (string)$pv;
            }
        }
        return [
            'tag'          => $node->type,
            'id'           => isset($props['id']) && is_string($props['id']) ? $props['id'] : null,
            'classes'      => $classes,
            'attrs'        => $attrs,
            'index'        => $index,
            'ancestors'    => $ancestorCtx,
            'prevSiblings' => $prevSiblingCtx,
        ];
    }
}
