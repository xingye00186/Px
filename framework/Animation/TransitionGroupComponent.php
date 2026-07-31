<?php

namespace Px\Animation;
use Px\Render\RenderTreeManager;

use Px\Component\ReactiveComponent;
use Px\Dom\VNode;
use Px\Render\RenderNode;
use Px\Animation\AnimationManager;
use Px\Animation\CssAnimationParser;
use Px\Animation\Interpolator;
use Px\Css\CssMappings;

/**
 * TransitionGroupComponent — 列表过渡组件
 *
 * 对标 Vue 3 的 <TransitionGroup> 组件：
 * - 管理列表项的进入/离开/移动动画
 * - 实现 FLIP 算法处理列表重排
 * - 自动为每个子节点生成唯一 key
 *
 * 使用方式：
 * ```php
 * // 模板中使用
 * <transition-group name="list">
 *   <div v-for="item in items" :key="item.id">{{ item.text }}</div>
 * </transition-group>
 * ```
 *
 * AOT 兼容: 使用 protected static 数组，不使用闭包
 */
class TransitionGroupComponent extends ReactiveComponent
{
    /** @var string 过渡名称（用于 CSS 类名前缀） */
    protected string $name = 'v';

    /** @var string 子节点 tag（默认 'div'） */
    protected string $tag = 'div';

    /** @var array 已知的子节点 key → 坐标（用于 FLIP 算法） */
    protected array $childPositions = [];

    /** @var bool FLIP 动画是否启用 */
    protected bool $flipEnabled = true;

    /** @var int FLIP 动画时长（毫秒） */
    protected int $flipDuration = 300;

    /** @var string FLIP 缓动函数 */
    protected string $flipEasing = 'ease';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 设置过渡名称。
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * 设置子节点 tag。
     */
    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }

    /**
     * 设置 FLIP 动画参数。
     */
    public function setFlipParams(int $duration, string $easing = 'ease'): void
    {
        $this->flipDuration = $duration;
        $this->flipEasing = $easing;
    }

    // ============================================================
    // ReactiveComponent 实现
    // ============================================================

    public function render(): VNode
    {
        // 默认渲染一个占位容器
        return VNode::h($this->tag, [
            'style' => 'position:relative',
        ]);
    }

    public function setBindValue(string $key, string $value): void
    {
        // 转发 bind 值给子组件
        // 注意：子组件的 setBindValue 由编译器生成代码调用
    }

    public function getBindValue(string $key): string
    {
        return '';
    }

    // ============================================================
    // FLIP 算法（由 RenderTreeManager 调用）
    // ============================================================

    /**
     * 记录子节点的当前位置。
     * 在布局完成后调用，用于 FLIP 算法的 First 阶段。
     *
     * @param RenderNode $node  RenderNode
     * @param string $key       子节点 key
     */
    public function recordPosition(RenderNode $node, string $key): void
    {
        // 从绘制权威 Fragment 读几何（RenderNode 无 x/y/w/h 字段）
        $frag = $node->cachedFragment;
        $this->childPositions[$key] = [
            'x' => $frag !== null ? (int)$frag->x : 0,
            'y' => $frag !== null ? (int)$frag->y : 0,
            'w' => $frag !== null ? (int)$frag->w : 0,
            'h' => $frag !== null ? (int)$frag->h : 0,
        ];
    }

    /**
     * T3: layout 完成后由 Application 调用——对比旧 childPositions
     * 与新 Fragment 几何，对变化项注册 FLIP translateX/Y 动画。
     */
    public function afterLayout(): void
    {
        if (!$this->flipEnabled) {
            return;
        }
        $rootRN = $this->getRootRenderNode();
        if ($rootRN === null) {
            return;
        }
        $oldPositions = $this->childPositions;
        // 快照当前几何
        $this->childPositions = [];
        foreach ($rootRN->children as $child) {
            $key = $child->key;
            if ($key === null) continue;
            $frag = $child->cachedFragment;
            $this->childPositions[$key] = [
                'x' => $frag !== null ? (int)$frag->x : 0,
                'y' => $frag !== null ? (int)$frag->y : 0,
                'w' => $frag !== null ? (int)$frag->w : 0,
                'h' => $frag !== null ? (int)$frag->h : 0,
            ];
        }
        // 对比并注册 FLIP
        if (empty($oldPositions)) {
            return; // 首帧无对比基线
        }
        $mgr = AnimationManager::getInstance();
        foreach ($rootRN->children as $child) {
            $key = $child->key;
            if ($key === null) continue;
            $old = $oldPositions[$key] ?? null;
            $cur = $this->childPositions[$key] ?? null;
            if ($old === null || $cur === null) continue;
            $dx = (int)$old['x'] - (int)$cur['x'];
            $dy = (int)$old['y'] - (int)$cur['y'];
            if ($dx === 0 && $dy === 0) continue;
            // 反向 translate → 过渡归零（FLIP 的 Invert+Play）
            $mgr->addTransition($child, 'translateX', $dx, 0, $this->flipDuration, $this->flipEasing);
            $mgr->addTransition($child, 'translateY', $dy, 0, $this->flipDuration, $this->flipEasing);
        }
    }

    /**
     * 执行 FLIP 动画（旧接口，保留兼容）。
     */
    public function performFlip(RenderNode $node, array $prevPositions): void
    {
        if (!$this->flipEnabled) {
            return;
        }

        // 遍历当前子节点，检查位置变化
        foreach ($node->children as $child) {
            if ($child->key === null) {
                continue;
            }

            $key = $child->key;
            $prev = $prevPositions[$key] ?? null;
            if ($prev === null) {
                // 新增节点，跳过 FLIP
                continue;
            }

            $dx = $prev['x'] - $child->lastX;
            $dy = $prev['y'] - $child->lastY;

            // 如果位置没有变化，跳过
            if ($dx === 0 && $dy === 0) {
                continue;
            }

            // 执行 FLIP 动画
            $this->animateFlip($child, $dx, $dy);
        }
    }

    /**
     * 对单个子节点执行 FLIP 动画。
     *
     * @param RenderNode $node  子节点
     * @param int $dx          X 方向位移（像素）
     * @param int $dy          Y 方向位移（像素）
     */
    private function animateFlip(RenderNode $node, int $dx, int $dy): void
    {
        $manager = AnimationManager::getInstance();

        // 取消已有的 FLIP 动画
        $manager->cancelTransition($node, 'translateX');
        $manager->cancelTransition($node, 'translateY');

        // 添加新的 FLIP 动画：反向位移 → 0
        if ($dx !== 0) {
            $manager->addTransition(
                $node,
                'translateX',
                -$dx,
                0,
                $this->flipDuration,
                $this->flipEasing
            );
        }

        if ($dy !== 0) {
            $manager->addTransition(
                $node,
                'translateY',
                -$dy,
                0,
                $this->flipDuration,
                $this->flipEasing
            );
        }
    }

    // ============================================================
    // 列表过渡（进入/离开）
    // ============================================================

    /**
     * 标记子节点为进入状态。
     *
     * @param string $key 子节点 key
     */
    public function markEntering(string $key): void
    {
        $classFrom = CssAnimationParser::generateTransitionClass($this->name, 'enter', 'from');
        $classActive = CssAnimationParser::generateTransitionClass($this->name, 'enter', 'active');
        $classTo = CssAnimationParser::generateTransitionClass($this->name, 'enter', 'to');

        // 应用 CSS 类名（由 CSS 规则定义动画）
        // 注意：这需要与 RenderNode.props['class'] 集成
    }

    /**
     * 标记子节点为离开状态。
     *
     * @param string $key 子节点 key
     */
    public function markLeaving(string $key): void
    {
        $classFrom = CssAnimationParser::generateTransitionClass($this->name, 'leave', 'from');
        $classActive = CssAnimationParser::generateTransitionClass($this->name, 'leave', 'active');
        $classTo = CssAnimationParser::generateTransitionClass($this->name, 'leave', 'to');

        // 应用 CSS 类名
    }

    // ============================================================
    // 生命周期
    // ============================================================

    public function onMount(): void
    {
    }

    public function onUnmount(): void
    {
        // 取消所有 FLIP 动画
        $this->childPositions = [];
    }

    public function onUpdated(): void
    {
        // 布局完成后更新 lastX/lastY
        // 这些值在动画过程中保持不变，只在完整布局完成后更新
    }
}
