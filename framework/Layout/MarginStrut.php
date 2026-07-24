<?php

namespace Px\Layout;

use native_types;

/**
 * MarginStrut — margin 折叠追踪器（对标 Blink NGMarginStrut）
 *
 * CSS 2.2 §8.3.1：垂直相邻的 margin 折叠算法。
 *
 * 折叠规则：
 *   1. 多个正值 → 取最大值
 *   2. 多个负值 → 取最负值（min，即绝对值最大）
 *   3. 混合正负 → 正最大 + 负最负（数值相加）
 *
 * 用例：
 *   - 相邻兄弟 margin-bottom + margin-top
 *   - 父与首/末子 margin 折叠（BFC 入口/出口）
 *   - 空节点自折叠（margin-top + margin-bottom）
 *
 * 使用：
 *   $strut = new MarginStrut();
 *   $strut->append($m1);
 *   $strut->append($m2);
 *   $resolved = $strut->resolve();  // 折叠后单个 margin 值
 *
 * 不可变操作：clone 前 append 会产生新对象（此处采用可变对象以避免频繁分配）。
 *
 * 未来集成到 LayoutResult 时，可作为 endMarginStrut 上传给父容器实现父子折叠。
 */
class MarginStrut
{
    /** 已累积的最大正 margin */
    public int $positiveMargin = 0;
    /** 已累积的最小负 margin（数值上更负 = 绝对值更大） */
    public int $negativeMargin = 0;

    /** 追加一个 margin 值到 strut。CSS §8.3.1 折叠规则 */
    public function append(int $margin): void
    {
        if ($margin > 0) {
            if ($margin > $this->positiveMargin) $this->positiveMargin = $margin;
        } elseif ($margin < 0) {
            if ($margin < $this->negativeMargin) $this->negativeMargin = $margin;
        }
        // margin === 0 不影响
    }

    /** 追加另一个 MarginStrut（例如父吸收子的 endMarginStrut） */
    public function appendStrut(MarginStrut $other): void
    {
        // AOT 兼容：跨对象属性读取需显式 (int) cast
        $otherPos = (int)$other->positiveMargin;
        $otherNeg = (int)$other->negativeMargin;
        if ($otherPos > $this->positiveMargin) $this->positiveMargin = $otherPos;
        if ($otherNeg < $this->negativeMargin) $this->negativeMargin = $otherNeg;
    }

    /** 解析为最终 margin 值：正最大 + 负最负（正常场景仅一者非零） */
    public function resolve(): int
    {
        return $this->positiveMargin + $this->negativeMargin;
    }

    /** 检查是否为空（未累积任何 margin） */
    public function isEmpty(): bool
    {
        return $this->positiveMargin === 0 && $this->negativeMargin === 0;
    }

    /** 重置为空状态 */
    public function reset(): void
    {
        $this->positiveMargin = 0;
        $this->negativeMargin = 0;
    }

    /** 克隆当前状态（用于 layout 状态回退） */
    public function copy(): MarginStrut
    {
        $s = new MarginStrut();
        // AOT 兼容：跨对象赋值需显式 (int) cast
        $s->positiveMargin = (int)$this->positiveMargin;
        $s->negativeMargin = (int)$this->negativeMargin;
        return $s;
    }
}
