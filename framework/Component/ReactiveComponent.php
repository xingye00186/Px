<?php

namespace Px\Component;
use Px\Render\RenderTreeManager;

use native_types;

use Px\Component\Contracts\ComponentInterface;
use Px\Component\Contracts\ReactiveComponentInterface;
use Px\Core\Scheduler;
use Px\Render\RenderNode;
use Px\Dom\VNode;

/**
 * ReactiveComponent — 响应式组件基类 (v10 Reactive)
 *
 *  - 响应式属性通过 #[Reactive] + Property Hooks 自动追踪
 *  - 属性变更自动触发 Effect 调度 → 微任务 → 组件更新
 *  - $emit() 子→父事件通信（对标 Vue 3）
 *  - unmount 自动清理 Effect 订阅 + 事件处理器
 *
 * 注意:
 *   - markDirty() 已移除 — 由 Property Hook 的 set → Notifier::notify() → Effect 自动处理
 *   - 子类仍可手动调用 performUpdate() 强制更新
 *   - 兼容 Application::matchComponentNode() 的 $instance->dirty 检查
 *
 * AOT：闭包调用合法，默认值传递。
 */
abstract class ReactiveComponent extends BaseComponent implements ComponentInterface, ReactiveComponentInterface
{
    /** @var bool 缓存脏标记 — 由 performUpdate() 设置，getVNodeTree() 清除 */
    public bool $dirty = false;

    /** @var bool 防止 performUpdate() 重入 */
    protected bool $isUpdating = false;

    protected bool $isMounted = false;

    /** @var callable|null 渲染请求回调（由 Application 注入） */
    protected ?\Closure $renderCallback = null;

    /** @var array<string, array<int, callable>> eventName => [handlerId => callback] */
    protected array $eventHandlers = [];

    /** @var array<array{child: ReactiveComponent, handlerId: int}> 注册在子组件上的处理器引用 */
    protected array $listenerIds = [];

    private int $nextHandlerId = 1;

    /**
     * VNode 树缓存
     *
     * Vue 3 风格惰性重建：
     *   状态未变时复用缓存，避免整树重建
     *   performUpdate() 将 dirty 设为 true → getVNodeTree() 重新执行 render()
     */
    protected ?VNode $vnodeCache = null;

    /**
     * 渲染脏标记 — 跨 patchComponentTree → updateFromVNode 两阶段持久。
     *
     * dirty 在 getVNodeTree() 中被清除（供 patchComponentTree 判断），
     * 但 updateFromVNode 需要知道组件本轮是否重渲染过，
     * 以决定是否跳过 RenderNode 子树遍历（对标 Blink ChildNeedsStyleRecalc）。
     * renderDirty 在 performUpdate 中设置，在 updateFromVNode 组件处理中清除。
     */
    public bool $renderDirty = false;

    /**
     * 响应式 Effect（由编译器生成的子类初始化）
     * 通过 DependencyTracker::runWithEffect() 包裹 render()
     * 自动追踪 render() 期间读取的 #[Reactive] 属性
     */
    protected ?\Px\Reactive\Effect $_px_effect = null;

    /**
     * v-for iteration 级 VNode 缓存（Path A，对标 Flutter Element 复用 + Vue 3 v-once 自动化）
     *
     * 双缓冲机制：每帧构建 $newCache，帧末整体交换旧缓存，
     * 防止 item 移除导致的缓存残留/内存泄漏。
     * 仅 keyed v-for（PATCH_KEYED_LIST，有稳定 :key）使用。
     * @var array<string, array<string, VNode>> helperName => (itemKey => VNode)
     */
    protected array $_vforCache = [];

    /**
     * v-for iteration item 快照（Path A）
     * 存储上一帧每个 item 的数组值，用于 === 值比较检测变化：
     *   item 未变（=== 命中）→ 复用缓存 VNode，跳过构建
     *   item 变化（=== 未命中）→ 重建 VNode
     * @var array<string, array<string, array>> helperName => (itemKey => item数组)
     */
    protected array $_vforItem = [];

    /**
     * 上一帧的根 RenderNode，用于跨帧匹配复用。
     * 由 RenderTreeManager::updateFromVNode 在 #component 处理器中设置。
     */
    public ?RenderNode $rootRenderNode = null;

    public function getRootRenderNode(): ?RenderNode
    {
        return $this->rootRenderNode;
    }

    public function setRootRenderNode(?RenderNode $node): void
    {
        $this->rootRenderNode = $node;
    }

    public function isRenderDirty(): bool
    {
        return $this->renderDirty;
    }

    public function clearRenderDirty(): void
    {
        $this->renderDirty = false;
    }

    /**
     * Application 注入渲染请求回调。
     */
    public function setRenderCallback(callable $callback): void
    {
        $this->renderCallback = $callback;
    }

    /**
     * 执行更新 (由 Effect::schedule() 的微任务回调调用)
     *
     * 触发渲染管线：
     *   Effect::schedule() → microtask → performUpdate()
     *   → renderCallback → Application::requestRender()
     *   → rebuildVNodeTree() → getVNodeTree() → render()
     */
    public function performUpdate(): void
    {
        if ($this->isUpdating) {
            return;
        }

        $this->isUpdating = true;

        if ($this->isMounted) {
            $this->onBeforeUpdate();
        }

        // 不清空 vnodeCache —— 保留旧 VNode 树，patchVNodeTree 会原地更新属性。
        // 旧 VNode 存活 → computedStyle 保留 → RenderNode 匹配命中 → Fragment 缓存命中。
        $this->dirty = true;
        $this->renderDirty = true;

        // 通过注入的回调请求 Application 重渲染
        if ($this->renderCallback !== null) {
            ($this->renderCallback)();
        }

        if ($this->isMounted) {
            $this->onUpdated();
        }

        $this->isUpdating = false;
    }

    /**
     * 获取 VNode 树 — 惰性重建。
     *
     * 仅在 dirty 时调用 render() 重建并缓存；
     * 状态未变时直接返回上次缓存的树。
     *
     * 子类若使用了 #[Reactive] 属性，编译器会自动覆盖此方法
     * 并包裹在 DependencyTracker::runWithEffect() 中。
     */
    public function getVNodeTree(): VNode
    {
        if (!$this->dirty && $this->vnodeCache !== null) {
            return $this->vnodeCache;
        }

        $oldCache = $this->vnodeCache;
        $this->vnodeCache = $this->render();
        $this->dirty = false;

        // 有旧缓存时，用新树 patche 旧树，复用旧 VNode 对象
        // 旧 VNode 存活 → computedStyle 保留 → RenderNode 不重建 → Fragment 缓存命中
        if ($oldCache !== null) {
            $this->patchVNodeTree($oldCache, $this->vnodeCache);
            $this->vnodeCache = $oldCache;
        }

        return $this->vnodeCache;
    }

    /**
     * 递归 patch VNode 树：用新树属性更新旧树对象，复用旧 VNode 保留 computedStyle。
     * 匹配策略：先按 key，再按 type+index。
     *
     * patchFlag 消费逻辑（Vue 3 对标）：
     *   - PATCH_STRUCT (8) 或 PATCH_ALL (63)：全量替换 props（结构变化）
     *   - PATCH_STYLE (1)：仅更新 ':style' 键
     *   - PATCH_CLASS (2)：仅更新 'class' 键
     *   - PATCH_EVENT  (4)：仅更新 '@*' 键
     *   - PATCH_PROPS (16)：仅更新 ':key' 动态属性（非 style/class）
     *   - PATCH_TEXT  (32)：动态文本内容（由 children 递归处理）
     *   - PATCH_NONE (0)：跳过 props 更新（完全静态）
     */
    private function patchVNodeTree(VNode $old, VNode $new): void
    {
        // Path A: 同一 VNode 对象（iteration 缓存命中复用）→ 真 O(1) no-op
        //   缓存命中时新旧是同一实例，所有字段已一致，无需任何 patch/遍历
        if ($old === $new) {
            return;
        }

        // type + key 联合匹配：key 不同或 type 不同 → 替换整个节点
        if ($old->type !== $new->type || $old->key !== $new->key) {
            $this->replaceVNode($old, $new);
            return;
        }

        // #comment: v-if 占位符，无 op（类型不匹配日月 replaceVNode 上面已 handle）
        if ($old->type === '#comment') {
            return;
        }

        // ===== B-Phase 2.5: #list VNode 专属分支 =====
        // v-for helper 返回 #list VNode（包含 iteration 子节点），
        // 直接用 patchChildrenArray patch 其 children，无需递归到普通全 diff 路径。
        // patchFlags 区分：
        //   PATCH_KEYED_LIST   → keyed diff（patchChildrenArray 里的 keyed 分支）
        //   PATCH_UNKEYED_LIST → index+type 匹配（patchChildrenArray 里的无 key fallback）
        //   PATCH_STABLE_LIST  → 顶畬按顺序 patch（本次同上）
        if ($old->type === '#list') {
            // 归一化单源：单 VNode 子也须参与子树 diff（旧 is_array 守卫下
            // VNode::h(t, p, $child) 形态的子树被当空 → 差异漏判）。
            // 根因治本：单 VNode 子（VNode::h(t, p, $child)）也须参与子树 diff，
            // 旧 is_array 守卫下该形态被当空 → 差异漏判。数组路径保持直接复用
            //（写时复制零分配；childrenToArray 会为字符串子合成新 #text VNode）。
            $oldCh = is_array($old->children) ? $old->children : VNode::childrenToArray($old->children);
            $newCh = is_array($new->children) ? $new->children : VNode::childrenToArray($new->children);
            // #list unkeyed / stable 下，应保证 index+type 匹配也能 patch（不受
            // B-Phase 2 selective 限制——因为 #list 编译期保证了 child type均一致）
            $aggressive = ($new->patchFlags & (VNode::PATCH_UNKEYED_LIST | VNode::PATCH_STABLE_LIST)) !== 0;
            $old->children = $this->patchChildrenArray($oldCh, $newCh, $aggressive);
            $old->patchFlags = $new->patchFlags;  // 保留新 list 的 flag
            \Px\Core\PerfCounter::inc('list_patch');
            return;
        }

        // ===== Block Tree 快速路径 (Vue 3 patchBlockChildren 语义) =====
        // 新旧都是 block root 且 dynamicChildren 长度一致 → 迭代动态子孙数组，
        // 跳过静态中间层递归。长度不一致（v-if 切换分支/v-for 长度变化）
        // 直接降级到全 diff，正确性优先。
        if ($old->dynamicChildren !== null
            && $new->dynamicChildren !== null
            && count($old->dynamicChildren) === count($new->dynamicChildren)) {
            // 本节点 props 仍需 patch（本节点自身也可能有 patchFlags）
            $this->patchProps($old, $new);

            // 同步 organizational 字段（与递归路径保持一致）
            $old->componentInstance = $new->componentInstance;
            $old->componentPropValues = $new->componentPropValues;
            $old->layoutOffset = $new->layoutOffset;

            // 迭代动态子孙数组，直接 patch
            $oldDyn = $old->dynamicChildren;
            $newDyn = $new->dynamicChildren;
            $n = count($oldDyn);
            for ($i = 0; $i < $n; $i++) {
                if ($oldDyn[$i] instanceof VNode && $newDyn[$i] instanceof VNode) {
                    $this->patchVNodeTree($oldDyn[$i], $newDyn[$i]);
                }
            }

            \Px\Core\PerfCounter::inc('block_fastpath_hit');
            return;
        }

        if ($old->dynamicChildren !== null || $new->dynamicChildren !== null) {
            // 单侧 block 或长度变化 → 降级全 diff，量化用
            \Px\Core\PerfCounter::inc('block_fastpath_miss');
        }

        // ===== 全 diff 路径 =====
        $this->patchProps($old, $new);

        // 更新组件实例引用
        $old->componentInstance = $new->componentInstance;
        $old->componentPropValues = $new->componentPropValues;
        $old->layoutOffset = $new->layoutOffset;

        // children 分类型处理
        if ($old->children instanceof VNode && $new->children instanceof VNode) {
            // 单子节点：递归 patch
            $this->patchVNodeTree($old->children, $new->children);
        } elseif (is_array($old->children) && is_array($new->children)) {
            // 多子节点：按 key 匹配 patch
            $prevKids = $old->children;
            $old->children = $this->patchChildrenArray($old->children, $new->children);
            // C4.1：结构变更（引入新实例/数量变化）影响子层样式与元素序
            //（nth-child 等）→ 置脏并向上传播。
            if (self::childrenIdentityChanged($prevKids, $old->children)) {
                self::markNeedsStyleRecalcUp($old);
            }
        } else {
            // 简单类型（string/null）或类型不一致：直接替换
            $old->children = $new->children;
        }
    }

    /**
     * patch 单个节点的 props（基于 patchFlags 选择性更新）。
     * 从 patchVNodeTree 抽取，供 block fast-path 与全 diff 共享。
     */
    private function patchProps(VNode $old, VNode $new): void
    {
        // C4.1：样式脏位（对标 Blink NeedsStyleRecalc）。VNode 实例跳帧复用且
        // props **原地改写**（getVNodeTree 将 patch 结果写回 $oldCache），故
        // “同实例 + 外部输入未变”**不足以**判定样式未变。
        //
        // 优化：签名比对只在**props 可能被改写且可能影响样式**时才做。
        // 编译器已用 patchFlags 声明了“哪里是动态的”，无需重新推导：
        //   - PATCH_NONE：本方法不改 props → 签名必相等 → 计算注定无效
        //     （gen 应用中静态子树占多数，旧写法在此处纯浪费）
        //   - CLASS/STYLE/STRUCT：直接改写 class/style 或整体替换 props → 必查
        //   - 仅 EVENT/PROPS/TEXT：不碰 class/style；但引擎含**属性选择器**时
        //     任意 prop 变动均可改变匹配 → 仍需查
        $flags = $old->patchFlags;
        $needSigCheck = ($flags !== VNode::PATCH_NONE) && (
            ($flags & (VNode::PATCH_CLASS | VNode::PATCH_STYLE | VNode::PATCH_STRUCT)) !== 0
            || \Px\Css\StyleEngine::usesAttrRules()
        );
        $styleSigBefore = '';
        if ($needSigCheck) {
            \Px\Core\PerfCounter::inc('style_sig_check');
            $styleSigBefore = self::styleRelevantPropsSig($old);
        } else {
            \Px\Core\PerfCounter::inc('style_sig_skip');
        }
        // patchFlag 选择性更新 props
        if ($flags === VNode::PATCH_NONE) {
            // 完全静态：跳过 props 更新
        } elseif (($flags & VNode::PATCH_STRUCT) !== 0 || $flags === VNode::PATCH_ALL) {
            // 结构变化或未细化标记：全量替换
            $old->props = $new->props;
        } else {
            // 选择性更新：只复制 patchFlag 标记的动态键
            if ($old->props === null) {
                $old->props = $new->props;
            } elseif ($new->props !== null) {
                if (($flags & VNode::PATCH_STYLE) !== 0) {
                    if (isset($new->props[':style'])) {
                        $old->props[':style'] = $new->props[':style'];
                    } elseif (isset($old->props[':style'])) {
                        unset($old->props[':style']);
                    }
                    // 动态 style 可能影响静态 style 合并
                    if (isset($new->props['style'])) {
                        $old->props['style'] = $new->props['style'];
                    }
                }
                if (($flags & VNode::PATCH_CLASS) !== 0) {
                    if (isset($new->props['class'])) {
                        $old->props['class'] = $new->props['class'];
                    } elseif (isset($old->props['class'])) {
                        unset($old->props['class']);
                    }
                }
                if (($flags & VNode::PATCH_EVENT) !== 0) {
                    // 复制所有 @ 开头的键
                    foreach ($new->props as $k => $v) {
                        if (str_starts_with($k, '@')) {
                            $old->props[$k] = $v;
                        }
                    }
                }
                if (($flags & VNode::PATCH_PROPS) !== 0) {
                    // 复制所有 : 开头的动态属性（排除已处理的 :style/:class）
                    foreach ($new->props as $k => $v) {
                        if (str_starts_with($k, ':') && $k !== ':style' && $k !== ':class') {
                            $old->props[$k] = $v;
                        }
                    }
                }
            }
        }
        // C4.1：样式相关 props 发生变化 → 置脏并**向上传播** childNeedsStyleRecalc
        //（不清脏：未变时保留已有脏状态，由 StyleRecalcPass 重算后统一清除）。
        if ($needSigCheck && self::styleRelevantPropsSig($old) !== $styleSigBefore) {
            self::markNeedsStyleRecalcUp($old);
        }
    }

    /**
     * C4.1：置节点脏并沿父链置 childNeedsStyleRecalc（对标 Blink 的
     * SetNeedsStyleRecalc + MarkAncestorsWithChildNeedsStyleRecalc）。
     * 不提前终止：即使某层已为 true，也不保证其以上已被标记（上帧重算会
     * 清除各层）；以深度上限防循环引用。
     */
    private static function markNeedsStyleRecalcUp(VNode $n): void
    {
        $n->needsStyleRecalc = true;
        $p = $n->styleParentNode;
        $guard = 0;
        while ($p !== null && $guard < 4096) {
            $p->childNeedsStyleRecalc = true;
            $p = $p->styleParentNode;
            $guard++;
        }
    }

    /**
     * C4.1：子节点数组是否发生**实例级**变更（长度或逐位身份不同）。
     * 仅比身份与长度，O(n) 且无分配；patch 后复用旧实例时返回 false。
     */
    private static function childrenIdentityChanged(array $before, array $after): bool
    {
        if (count($before) !== count($after)) return true;
        $i = 0;
        foreach ($after as $node) {
            if (!array_key_exists($i, $before) || $before[$i] !== $node) return true;
            $i++;
        }
        return false;
    }

    /**
     * C4.1：样式相关 props 快照。仅含能影响计算样式的键（class/style 及其
     * 动态形式）；其余 props（事件/bind/普通属性）不参与样式计算。
     * 注：属性选择器（[attr=v]）使任意属性都可能影响匹配，故引擎含
     * 属性选择器时不能只看 class/style——由 usesAttrRules 保守处理。
     */
    private static function styleRelevantPropsSig(VNode $n): string
    {
        $p = $n->props;
        if (!is_array($p)) return '';
        if (\Px\Css\StyleEngine::usesAttrRules()) {
            // 存在属性选择器：任何 props 变动均可能改变匹配 → 全量入快照。
            $acc = '';
            foreach ($p as $k => $v) {
                if (is_scalar($v) || $v === null) { $acc .= $k . '=' . (string)$v . ';'; }
            }
            return $acc;
        }
        $cls = $p['class'] ?? '';
        $dcls = $p[':class'] ?? '';
        $st = $p['style'] ?? '';
        $dst = $p[':style'] ?? '';
        return (is_scalar($cls) ? (string)$cls : json_encode($cls)) . '|'
            . (is_scalar($dcls) ? (string)$dcls : json_encode($dcls)) . '|'
            . (is_scalar($st) ? (string)$st : json_encode($st)) . '|'
            . (is_scalar($dst) ? (string)$dst : json_encode($dst));
    }

    /**
     * 按 key 匹配新旧子节点数组，无 key 时回避到 index+type 匹配（Vue 3 默认行为）。
     * 无 key patch 多多为 v-if 分支根 / 静态列表等位置稳定的子节点，可保持旧对象复用。
     *
     * @param bool $aggressiveUnkeyed  true = 无 key 时只要 type 一致就 patch（#list unkeyed 场景）
     *                                  false = 十匹配双方都是 block root 且长度一致时才 patch（默认保守保护）
     */
    private function patchChildrenArray(array $oldChildren, array $newChildren, bool $aggressiveUnkeyed = false): array
    {
        $result = [];

        // 建立旧子节点的 key→node 映射与无 key 队列
        /** @var array<string, VNode> */
        $oldByKey = [];
        /** @var VNode[] */
        $oldNoKey = [];
        foreach ($oldChildren as $ch) {
            if ($ch instanceof VNode) {
                if ($ch->key !== null) {
                    $oldByKey[$ch->key] = $ch;
                } else {
                    $oldNoKey[] = $ch;
                }
            }
        }

        // 无 key 旧节点消费指针（按输入顺序前推）
        $noKeyIdx = 0;
        $noKeyLen = count($oldNoKey);

        foreach ($newChildren as $newCh) {
            if (!($newCh instanceof VNode)) {
                $result[] = $newCh;
                continue;
            }

            if ($newCh->key !== null) {
                // 尝试 key 匹配
                if (isset($oldByKey[$newCh->key])) {
                    $oldCh = $oldByKey[$newCh->key];
                    unset($oldByKey[$newCh->key]); // 已消费
                    $this->patchVNodeTree($oldCh, $newCh);
                    $result[] = $oldCh;
                } else {
                    // 无匹配：新节点
                    $result[] = $newCh;
                }
            } else {
                // 无 key：严格限定走 patch 的条件，避免回归
                //   默认（保守）：仅当旧首项 type 一致 && 双方都是 block root && dynamicChildren 长度相同时才 patch
                //   $aggressiveUnkeyed=true (#list unkeyed)：仅需 type 一致即 patch（编译期保证 type 稳定）
                //   → 避免为了少数 fast-path 命中而引入 v-if 无 block 分支的深递归开销
                $typeMatch = ($noKeyIdx < $noKeyLen) && $oldNoKey[$noKeyIdx]->type === $newCh->type;
                $canFastPathPatch = $typeMatch && (
                    $aggressiveUnkeyed
                    || (
                        $oldNoKey[$noKeyIdx]->dynamicChildren !== null
                        && $newCh->dynamicChildren !== null
                        && count($oldNoKey[$noKeyIdx]->dynamicChildren) === count($newCh->dynamicChildren)
                    )
                );

                if ($canFastPathPatch) {
                    $oldCh = $oldNoKey[$noKeyIdx];
                    $noKeyIdx++;
                    $this->patchVNodeTree($oldCh, $newCh);
                    $result[] = $oldCh;
                } else {
                    // 失变中：直接采用新节点（保持旧 patchChildrenArray 行为，无 regression）
                    $result[] = $newCh;
                    if ($noKeyIdx < $noKeyLen) { $noKeyIdx++; }
                }
            }
        }
        return $result;
    }

    /**
     * 整体替换 VNode（type/key 不匹配时）。
     */
    private function replaceVNode(VNode $old, VNode $new): void
    {
        $old->type = $new->type;
        $old->props = $new->props;
        $old->children = $new->children;
        $old->key = $new->key;
        $old->groupId = $new->groupId;
        $old->isComponent = $new->isComponent;
        $old->componentClass = $new->componentClass;
        $old->componentInstance = $new->componentInstance;
        $old->componentProps = $new->componentProps;
        $old->componentPropValues = $new->componentPropValues;
        $old->layoutOffset = $new->layoutOffset;
        // computedStyle 被新值覆盖（结构变了，旧样式不可用）
        $old->computedStyle = $new->computedStyle;
    }

    // ── 事件处理器注册（内部） ──────────────────

    /**
     * 注册事件处理器（由 on() 在子组件上调用），返回处理器 ID。
     */
    private function registerHandler(string $eventName, callable $callback): int
    {
        $id = $this->nextHandlerId++;
        if (!isset($this->eventHandlers[$eventName])) {
            $this->eventHandlers[$eventName] = [];
        }
        $this->eventHandlers[$eventName][$id] = $callback;
        return $id;
    }

    /**
     * 移除事件处理器。
     */
    private function removeHandler(int $id): void
    {
        foreach ($this->eventHandlers as $eventName => $handlers) {
            if (isset($handlers[$id])) {
                unset($this->eventHandlers[$eventName][$id]);
                if (empty($this->eventHandlers[$eventName])) {
                    unset($this->eventHandlers[$eventName]);
                }
                return;
            }
        }
    }

    // ── 组件通信 ─────────────────────────────────

    /**
     * 向父组件发送事件（对标 Vue 3 $emit）。
     *
     * 直接调用注册在当前组件上的事件处理器。
     *
     * 示例:
     *   // 子组件
     *   $this->emit('itemSelected', ['id' => 5]);
     *
     *   // 父组件
     *   $child->on('itemSelected', function($payload) { ... });
     *
     * @param string $eventName 事件名
     * @param mixed $payload 事件载荷
     */
    protected function emit(string $eventName, mixed $payload = null): void
    {
        if (!isset($this->eventHandlers[$eventName])) {
            return;
        }
        foreach ($this->eventHandlers[$eventName] as $callback) {
            $callback($payload);
        }
    }

    /**
     * 监听子组件事件（对标 Vue 3 v-on）。
     *
     * 子组件 emit('itemSelected', ...) 后触发回调。
     * 在 unmount 时自动解绑。
     *
     * @param ReactiveComponent $child 子组件实例
     * @param string $eventName 事件名
     * @param callable $callback 回调
     */
    protected function on(ReactiveComponent $child, string $eventName, callable $callback): void
    {
        $id = $child->registerHandler($eventName, $callback);
        $this->listenerIds[] = ['child' => $child, 'handlerId' => $id];
    }

    /**
     * 挂载组件
     */
    public function mount(): void
    {
        $this->isMounted = true;
        $this->onMount();
    }

    /**
     * 卸载组件（自动解绑所有监听器）
     */
    public function unmount(): void
    {
        $this->onUnmount();
        $this->isMounted = false;
        $this->rootRenderNode = null;

        // 移除注册在子组件上的事件处理器
        foreach ($this->listenerIds as $entry) {
            $entry['child']->removeHandler($entry['handlerId']);
        }
        $this->listenerIds = [];

        // 清空自身事件处理器
        $this->eventHandlers = [];
    }

    public function onUnmount(): void
    {
    }

    public function onBeforeUpdate(): void
    {
    }

    public function onUpdated(): void
    {
    }

    public function onMount(): void
    {
    }

    abstract public function render(): VNode;

    abstract public function setBindValue(string $bindKey, string $value): void;

    abstract public function getBindValue(string $bindKey): string;
}
