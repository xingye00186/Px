<?php

namespace Px\Layout;

use native_types;
use Px\Render\RenderNode;

/**
 * OOFPositionedDescendant — Out-Of-Flow 后代描述（对标 Blink NGOutOfFlowPositionedDescendant）
 *
 * 用于 LayoutResult.oofDescendants 冒泡，让父容器（正确的包含块）在自己布局完成后
 * 才处理这些 OOF 后代，避免在 mainLayout 遍历时全树搜索。
 */
class OOFPositionedDescendant
{
    public function __construct(
        public readonly RenderNode $node,
        /** 相对于当前 Fragment 的静态位置（当 top/left 均为 auto 时使用） */
        public readonly int $staticInlineOffset,
        public readonly int $staticBlockOffset,
    ) {}
}

/**
 * LayoutResult — 算法输出的完整包（对标 Blink NGLayoutResult）
 *
 * 审计 §12.2 P0 权重 4%：Fragment 不足以承载布局副产物。
 *
 * Blink NGLayoutResult 承载：
 *   scoped_refptr<const NGPhysicalFragment> physical_fragment_;
 *   NGBfcOffset bfc_offset_;                        // BFC 相对偏移
 *   LayoutUnit intrinsic_block_size_;               // 与 final size 分离
 *   NGMarginStrut end_margin_strut_;                // 上传给父实现父子折叠
 *   Vector<NGOutOfFlowPositionedDescendant> oofs_;  // 冒泡到正确包含块
 *   scoped_refptr<const NGBreakToken> break_token_; // 强制换行/分页
 *
 * Px 目前只有 PhysicalFragment，导致：
 *   - 父与末子 margin 折叠失效（无 endMarginStrut 上传）
 *   - Auto-height 与 min-content 混淆（无独立 intrinsic_block_size）
 *   - OOF 靠全树遍历重定位（无 oofDescendants 冒泡）
 *
 * **本类为架构铺垫**：定义结构，为算法逐步迁移做准备。
 * 现阶段算法仍返回 PhysicalFragment，未来通过 LayoutResult::wrap() 包装或
 * 算法直接返回 LayoutResult。消费者通过 result->fragment 访问几何。
 *
 * 不可变约束：所有字段 readonly，构造后不可修改。
 */
class LayoutResult
{
    public function __construct(
        /** 主输出：几何 Fragment 树（不可变） */
        public readonly PhysicalFragment $fragment,

        /** 末端 margin strut（用于父吸收进行父子折叠，CSS 2.2 §8.3.1） */
        public readonly ?MarginStrut $endMarginStrut = null,

        /**
         * 内在 block size（与 fragment.h 分开跟踪）。
         * 对标 Blink intrinsic_block_size_。
         * -1 表示未计算（消费者应回退到 fragment.h）。
         */
        public readonly int $intrinsicBlockSize = -1,

        /** OOF 后代列表（冒泡到正确包含块处理） */
        public readonly array $oofDescendants = [],

        /**
         * BFC 偏移（在正确启用 BFC 语义前保留为占位，
         * 对标 Blink NGBfcOffset，用于 float 和 margin 折叠）
         */
        public readonly int $bfcOffsetX = 0,
        public readonly int $bfcOffsetY = 0,

        /**
         * 是否有强制换行（对标 Blink has_forced_break，
         * 用于 CSS Fragmentation 多页/多列打断）
         */
        public readonly bool $hasForcedBreak = false,
    ) {}

    /**
     * 从裸 PhysicalFragment 构造 LayoutResult（迁移期便捷方法）。
     * 无 endMarginStrut / oofDescendants，intrinsicBlockSize 由 fragment.h 推断。
     */
    public static function wrap(PhysicalFragment $fragment): LayoutResult
    {
        return new LayoutResult(
            $fragment,
            null,
            $fragment->getH(),
        );
    }

    /** 便捷：读取内嵌 Fragment 的几何 */
    public function getFragment(): PhysicalFragment
    {
        return $this->fragment;
    }

    /** 内在 block size（未设置时回退到 fragment.h） */
    public function getIntrinsicBlockSize(): int
    {
        return $this->intrinsicBlockSize >= 0 ? $this->intrinsicBlockSize : (int)$this->fragment->getH();
    }

    /** 是否有 endMarginStrut 待父吸收 */
    public function hasEndMarginStrut(): bool
    {
        return $this->endMarginStrut !== null && !$this->endMarginStrut->isEmpty();
    }

    /**
     * 生成新 LayoutResult，替换 fragment 但保留其他字段（不可变更新模式）。
     * 用于 OOF pass 修改 fragment 坐标后重新打包。
     */
    public function withFragment(PhysicalFragment $newFragment): LayoutResult
    {
        return new LayoutResult(
            $newFragment,
            $this->endMarginStrut,
            $this->intrinsicBlockSize,
            $this->oofDescendants,
            $this->bfcOffsetX,
            $this->bfcOffsetY,
            $this->hasForcedBreak,
        );
    }
}
