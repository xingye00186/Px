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
        // type + key 联合匹配：key 不同或 type 不同 → 替换整个节点
        if ($old->type !== $new->type || $old->key !== $new->key) {
            $this->replaceVNode($old, $new);
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
            $old->children = $this->patchChildrenArray($old->children, $new->children);
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
        // patchFlag 选择性更新 props
        $flags = $old->patchFlags;
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
    }

    /**
     * 按 key+type 匹配新旧子节点数组，复用旧对象。
     */
    private function patchChildrenArray(array $oldChildren, array $newChildren): array
    {
        $result = [];

        // 建立旧子节点的 key→node 映射
        /** @var array<string, VNode> */
        $oldByKey = [];
        foreach ($oldChildren as $ch) {
            if ($ch instanceof VNode && $ch->key !== null) {
                $oldByKey[$ch->key] = $ch;
            }
        }

        foreach ($newChildren as $newCh) {
            if (!($newCh instanceof VNode)) {
                $result[] = $newCh;
                continue;
            }

            // 尝试 key 匹配
            if ($newCh->key !== null && isset($oldByKey[$newCh->key])) {
                $oldCh = $oldByKey[$newCh->key];
                unset($oldByKey[$newCh->key]); // 已消费
                $this->patchVNodeTree($oldCh, $newCh);
                $result[] = $oldCh;
            } else {
                // 无匹配：新节点
                $result[] = $newCh;
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
