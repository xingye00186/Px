<?php

namespace Px\Core;
use Px\Dom\VNode;

use native_types;

use Px\Render\RenderNode;
use Px\Render\ScrollState;
use Px\Component\Contracts\ReactiveComponentInterface;
use Px\Component\ReactiveComponent;
use Px\Core\PerfCounter;

/**
 * ScrollManager — 滚动交互服务（RenderNode 版）
 *
 * 从 Application 抽取所有滚动状态和逻辑，修复 SOLID 违反。
 * 支持多滚动视口独立操作、横向滚动（Shift+滚轮）。
 * 所有树操作基于 RenderNode（非 VNode），属性通过 sourceVNode 访问。
 *
 * 通过回调注入与 Application 通信，避免循环依赖。
 */
class ScrollManager
{
    /** @var callable 触发延迟重渲染 */
    private ?\Closure $requestRender = null;

    /** @var callable 跳过树重建直接重绘 */
    private ?\Closure $directRender = null;

    /** @var callable 查组件表（接受 VNode，使用 groupId） */
    private ?\Closure $resolveComponentByGroupId = null;
    
    /** @var callable 查找鼠标坐标下的滚动容器 */
    private ?\Closure $findScrollContainer = null;

    // ── ScrollState 映射 ───────────────────────
    /** @var array<string, ScrollState> */
    private array $scrollStates = [];

    /**
     * 销毁时清理 ScrollState（由 destroyRenderNodeTree 调用）。
     */
    public function removeScrollState(RenderNode $node): void
    {
        unset($this->scrollStates[spl_object_id($node)]);
    }

    private function getScrollState(RenderNode $node): ScrollState
    {
        $key = spl_object_id($node);
        if (!isset($this->scrollStates[$key])) {
            $this->scrollStates[$key] = new ScrollState();
        }
        return $this->scrollStates[$key];
    }

    private function writeScrollTop(RenderNode $node, int $value): void
    {
        $this->getScrollState($node)->scrollTop = $value;
    }

    private function readScrollTop(RenderNode $node): int
    {
        return $this->getScrollState($node)->scrollTop;
    }

    private function writeScrollLeft(RenderNode $node, int $value): void
    {
        $this->getScrollState($node)->scrollLeft = $value;
    }

    private function readScrollLeft(RenderNode $node): int
    {
        return $this->getScrollState($node)->scrollLeft;
    }

    public function getScrollStateForNode(RenderNode $node): ScrollState
    {
        return $this->getScrollState($node);
    }

    public function setScrollContainer(RenderNode $node, bool $isContainer): void
    {
        $this->getScrollState($node)->isScrollContainer = $isContainer;
    }

    public function isScrollContainer(RenderNode $node): bool
    {
        return $this->getScrollState($node)->isScrollContainer;
    }

    public function getScrollTop(RenderNode $node): int
    {
        return $this->getScrollState($node)->scrollTop;
    }

    public function setScrollTop(RenderNode $node, int $value): void
    {
        $this->getScrollState($node)->scrollTop = $value;
    }

    public function getScrollLeft(RenderNode $node): int
    {
        return $this->getScrollState($node)->scrollLeft;
    }

    public function setScrollLeft(RenderNode $node, int $value): void
    {
        $this->getScrollState($node)->scrollLeft = $value;
    }

    /** 从 cachedFragment 迁移滚动状态到 ScrollState Map（对标 Blink: fragment 为几何权威源） */
    public function migrateFromRenderNode(RenderNode $node): void
    {
        $ss = $this->getScrollState($node);
        $frag = $node->cachedFragment;
        if ($frag !== null) {
            $ss->scrollTop = $frag->getScrollTop();
            $ss->scrollLeft = $frag->getScrollLeft();
            $ss->isScrollContainer = $frag->getIsScrollContainer();
            $ss->contentWidth = $frag->getContentWidth();
            $ss->contentHeight = $frag->getContentHeight();
        } else {
            $ss->scrollTop = 0;
            $ss->scrollLeft = 0;
            $ss->isScrollContainer = false;
            $ss->contentWidth = 0;
            $ss->contentHeight = 0;
        }
    }

    /** 回写迁移后的滚动状态到 RenderNode（已废弃：字段已移除，保留接口兼容） */
    public function writeBackToRenderNode(RenderNode $node): void
    {
        // RenderNode 滚动字段已移除，滚动状态统一由 cachedFragment 持有
    }

    // ── 拖拽状态 ──────────────────────────
    private ?RenderNode $scrollDragTarget = null;
    private int $scrollDragStartX = 0;
    private int $scrollDragStartY = 0;
    private int $scrollDragStartScrollPos = 0;
    private bool $scrollDragIsHorizontal = false;

    // ── 平滑滚动状态 ────────────────────────
    /** @var array{smoothNode:RenderNode,targetTop:int,targetLeft:int,steps:int,currentStep:int} */
    private ?array $smoothScrollState = null;
    private Scheduler $scheduler;

    /** 是否正在拖拽滚动条 */
    public function isDragging(): bool
    {
        return $this->scrollDragTarget !== null;
    }

    public function __construct(
        callable $requestRender,
        callable $directRender,
        callable $resolveComponentByGroupId,
        callable $findScrollContainer
    ) {
        $this->requestRender = $requestRender;
        $this->directRender = $directRender;
        $this->resolveComponentByGroupId = $resolveComponentByGroupId;
        $this->findScrollContainer = $findScrollContainer;
    }

    // ── 滚轮事件 ─────────────────────────────

    /**
     * 鼠标滚轮事件 — 更新最近祖先滚动容器的 scroll 位置。
     * Shift 按下时走横向滚动，否则走竖向滚动。
     */
    public function handleScrollWheel($event): void
    {
        PerfCounter::start('scroll_process');
        try {
            $scrollNode = ($this->findScrollContainer)($event->getX(), $event->getY());
            if ($scrollNode === null) {
                if (\Px\Core\Config::get('debug_diag_enabled', false)) {
                    error_log('[SCROLL_DBG] no scroll container at x=' . $event->getX() . ' y=' . $event->getY());
                }
                return;
            }

            $delta = $event->getDelta();
            $scrollAmount = (int)($delta / 3);

            if ($event->isShiftDown()) {
                $geom = $scrollNode->cachedFragment;
                $contentW = $geom !== null ? $geom->getContentWidth() : 0;
                $containerW = $geom !== null ? $geom->getW() : 0;
                $maxScroll = max($contentW - $containerW, 0);
                if ($maxScroll <= 0) return;

                $newScrollLeft = max(0, min($maxScroll, $this->readScrollLeft($scrollNode) - $scrollAmount));
                if ($newScrollLeft !== $this->readScrollLeft($scrollNode)) {
                    $this->applyScrollLeft($scrollNode, $newScrollLeft, true);
                }
            } else {
                $geom = $scrollNode->cachedFragment;
                $contentH = $geom !== null ? $geom->getContentHeight() : 0;
                $containerH = $geom !== null ? $geom->getH() : 0;
                $maxScroll = max($contentH - $containerH, 0);
                if (\Px\Core\Config::get('debug_diag_enabled', false)) {
                    error_log('[SCROLL_DBG] wheel x=' . $event->getX() . ' y=' . $event->getY() . ' delta=' . $delta . ' sa=' . $scrollAmount . ' scrollTop=' . $this->readScrollTop($scrollNode) . ' contentH=' . $contentH . ' containerH=' . $containerH . ' maxScroll=' . $maxScroll);
                }
                if ($maxScroll <= 0) return;

                $newScrollTop = max(0, min($maxScroll, $this->readScrollTop($scrollNode) - $scrollAmount));
                if ($newScrollTop !== $this->readScrollTop($scrollNode)) {
                    $this->applyScrollTop($scrollNode, $newScrollTop, true);
                    if (\Px\Core\Config::get('debug_diag_enabled', false)) {
                        error_log('[SCROLL_DBG] applied scrollTop=' . $newScrollTop . ' (old was ' . $this->readScrollTop($scrollNode) . ')');
                    }
                }
            }
        } finally {
            PerfCounter::end('scroll_process');
        }
    }

    // ── 滚动条命中测试 ────────────────────────

    /**
     * 滚动条命中测试。
     * 返回 ['scrollNode' => RenderNode, 'type' => 'thumb'|'track', 'isHorizontal' => bool] 或 null。
     * 先查右侧竖滚动条，再查底部横滚动条。
     */
    public function hitTestScrollbar(int $x, int $y, RenderNode $node): ?array
    {
        // 反向遍历子节点
        for ($i = count($node->children) - 1; $i >= 0; $i--) {
            $child = $node->children[$i];
            $result = $this->hitTestScrollbar($x, $y, $child);
            if ($result !== null) return $result;
        }

        // 对标 Blink: 从 Fragment 读取滚动容器标志
        $hsFrag = $node->cachedFragment;
        $hsIsScroll = $hsFrag !== null ? $hsFrag->getIsScrollContainer() : false;
        if (!$hsIsScroll) return null;

        // 对标 Blink：从 PhysicalFragment 读取几何（cachedFragment = LayoutObject.physical_fragment_）
        $geom = $node->cachedFragment;
        $nodeX = $geom !== null ? $geom->getX() : 0;
        $nodeY = $geom !== null ? $geom->getY() : 0;
        $nodeW = $geom !== null ? $geom->getW() : 0;
        $nodeH = $geom !== null ? $geom->getH() : 0;
        $contentH = $geom !== null ? $geom->getContentHeight() : 0;
        $contentW = $geom !== null ? $geom->getContentWidth() : 0;

        // 从节点 style 读取可配置的滚动条宽度
        $sbWidth = $node->computedStyle?->getRaw('scrollbarWidth') ?? 12;

        // ── 竖滚动条（右侧）──
        if ($contentH > $nodeH) {
            $sbW = $sbWidth;
            $sbX = $nodeX + $nodeW - $sbW;

            if ($x >= $sbX && $x <= $sbX + $sbW
                && $y >= $nodeY && $y <= $nodeY + $nodeH) {
                $ratio = min($nodeH / max($contentH, 1), 1.0);
                $thumbH = max((int)($nodeH * $ratio), 20);
                $maxScroll = max($contentH - $nodeH, 0);
                $scrollRatio = $maxScroll > 0 ? $this->readScrollTop($node) / $maxScroll : 0.0;
                $thumbY = $nodeY + (int)(($nodeH - $thumbH) * $scrollRatio);

                if ($y >= $thumbY && $y <= $thumbY + $thumbH) {
                    return ['scrollNode' => $node, 'type' => 'thumb', 'isHorizontal' => false];
                }
                return ['scrollNode' => $node, 'type' => 'track', 'isHorizontal' => false];
            }
        }

        // ── 横滚动条（底部）──
        if ($contentW > $nodeW) {
            $sbH = $sbWidth;
            $sbY = $nodeY + $nodeH - $sbH;

            if ($y >= $sbY && $y <= $sbY + $sbH
                && $x >= $nodeX && $x <= $nodeX + $nodeW) {
                $ratio = min($nodeW / max($contentW, 1), 1.0);
                $thumbW = max((int)($nodeW * $ratio), 20);
                $maxScroll = max($contentW - $nodeW, 0);
                $scrollRatio = $maxScroll > 0 ? $this->readScrollLeft($node) / $maxScroll : 0.0;
                $thumbX = $nodeX + (int)(($nodeW - $thumbW) * $scrollRatio);

                if ($x >= $thumbX && $x <= $thumbX + $thumbW) {
                    return ['scrollNode' => $node, 'type' => 'thumb', 'isHorizontal' => true];
                }
                return ['scrollNode' => $node, 'type' => 'track', 'isHorizontal' => true];
            }
        }

        return null;
    }

    // ── 滚动条按下处理 ────────────────────────

    /**
     * 滚动条点击处理：轨道 = 跳转，滑块 = 开始拖拽。
     */
    public function handleScrollbarDown(RenderNode $scrollNode, string $type, int $mouseX, int $mouseY, bool $isHorizontal): void
    {
        // 对标 Blink：从 PhysicalFragment 读取几何
        $geom = $scrollNode->cachedFragment;
        $nodeX = $geom !== null ? $geom->getX() : 0;
        $nodeY = $geom !== null ? $geom->getY() : 0;
        $nodeW = $geom !== null ? $geom->getW() : 0;
        $nodeH = $geom !== null ? $geom->getH() : 0;
        $contentW = $geom !== null ? $geom->getContentWidth() : $scrollNode->contentWidth;
        $contentH = $geom !== null ? $geom->getContentHeight() : $scrollNode->contentHeight;

        if ($isHorizontal) {
            $maxScroll = max($contentW - $nodeW, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($nodeW / max($contentW, 1), 1.0);
            $thumbW = max((int)($nodeW * $ratio), 20);
            $trackW = $nodeW - $thumbW;

            if ($type === 'track') {
                $clickOffset = $mouseX - $nodeX - (int)($thumbW / 2);
                $newScrollLeft = (int)($maxScroll * $clickOffset / max($trackW, 1));
                $newScrollLeft = (int)max(0, min($maxScroll, $newScrollLeft));
                $this->applyScrollLeft($scrollNode, $newScrollLeft, true);
            } elseif ($type === 'thumb') {
                $this->scrollDragTarget = $scrollNode;
                $this->scrollDragStartX = $mouseX;
                $this->scrollDragStartY = $mouseY;
                $this->scrollDragStartScrollPos = $this->readScrollLeft($scrollNode);
                $this->scrollDragIsHorizontal = true;
            }
        } else {
            $maxScroll = max($contentH - $nodeH, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($nodeH / max($contentH, 1), 1.0);
            $thumbH = max((int)($nodeH * $ratio), 20);
            $trackH = $nodeH - $thumbH;

            if ($type === 'track') {
                $clickOffset = $mouseY - $nodeY - (int)($thumbH / 2);
                $newScrollTop = (int)($maxScroll * $clickOffset / max($trackH, 1));
                $newScrollTop = (int)max(0, min($maxScroll, $newScrollTop));
                $this->applyScrollTop($scrollNode, $newScrollTop, true);
            } elseif ($type === 'thumb') {
                $this->scrollDragTarget = $scrollNode;
                $this->scrollDragStartX = $mouseX;
                $this->scrollDragStartY = $mouseY;
                $this->scrollDragStartScrollPos = $this->readScrollTop($scrollNode);
                $this->scrollDragIsHorizontal = false;
            }
        }
    }

    // ── 滚动条拖拽 ────────────────────────────

    /**
     * 滚动条拖拽 — 实时更新 scrollTop/scrollLeft（不持久化到组件）。
     */
    public function handleScrollbarDrag(int $mouseX, int $mouseY): void
    {
        $node = $this->scrollDragTarget;
        if ($node === null) return;

        if ($this->scrollDragIsHorizontal) {
            $geom = $node->cachedFragment;
            $contentW = $geom !== null ? $geom->getContentWidth() : 0;
            $containerW = $geom !== null ? $geom->getW() : 0;
            $maxScroll = max($contentW - $containerW, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($containerW / max($contentW, 1), 1.0);
            $thumbW = max((int)($containerW * $ratio), 20);
            $trackW = $containerW - $thumbW;

            $dx = $mouseX - $this->scrollDragStartX;
            $scrollDx = (int)($maxScroll * $dx / max($trackW, 1));
            $newScrollLeft = max(0, min($maxScroll, $this->scrollDragStartScrollPos + $scrollDx));

            if ($newScrollLeft !== $this->readScrollLeft($node)) {
                $this->applyScrollLeft($node, $newScrollLeft, false);
            }
        } else {
            $geom = $node->cachedFragment;
            $contentH = $geom !== null ? $geom->getContentHeight() : 0;
            $containerH = $geom !== null ? $geom->getH() : 0;
            $maxScroll = max($contentH - $containerH, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($containerH / max($contentH, 1), 1.0);
            $thumbH = max((int)($containerH * $ratio), 20);
            $trackH = $containerH - $thumbH;

            $dy = $mouseY - $this->scrollDragStartY;
            $scrollDy = (int)($maxScroll * $dy / max($trackH, 1));
            $newScrollTop = max(0, min($maxScroll, $this->scrollDragStartScrollPos + $scrollDy));

            if ($newScrollTop !== $this->readScrollTop($node)) {
                $this->applyScrollTop($node, $newScrollTop, false);
            }
        }
    }

    // ── 鼠标释放 ──────────────────────────────

    /**
     * 鼠标释放 — 结束拖拽，持久化滚动位置。
     */
    public function handleMouseUp(): void
    {
        if ($this->scrollDragTarget === null) return;

        if ($this->scrollDragIsHorizontal) {
            $this->applyScrollLeft($this->scrollDragTarget, $this->readScrollLeft($this->scrollDragTarget), true);
        } else {
            $this->applyScrollTop($this->scrollDragTarget, $this->readScrollTop($this->scrollDragTarget), true);
        }
        $this->scrollDragTarget = null;
    }

    // ── 滚动位置应用 ───────────────────────────

    /**
     * 应用 scrollTop 到 RenderNode，可选持久化到组件 bind 值。
     *
     * persist=true:  持久化到组件 + 请求重建树（滚轮、轨道点击、拖拽结束）
     * persist=false: 仅修改 RenderNode + 直接重绘（拖拽过程中）
     *
     * bind 键读取自 sourceVNode->props（VNode 上的 :scroll-top 属性）。
     */
    /**
     * 检测节点是否启用平滑滚动（scroll-behavior: smooth）。
     */
    private function isSmoothScroll(RenderNode $node): bool
    {
        $sb = $node->computedStyle?->getRaw('scrollBehavior') ?? $node->computedStyle?->getRaw('scroll-behavior') ?? '';
        return $sb === 'smooth';
    }

    /**
     * 执行平滑滚动的一步。
     * 如果还有剩余步数，继续调度下一帧。
     */
    private function smoothScrollStep(): void
    {
        if ($this->smoothScrollState === null) return;

        $state = $this->smoothScrollState;
        $node = $state['smoothNode'];
        $currentStep = $state['currentStep'] + 1;
        $steps = $state['steps'];

        // 计算本步进度（ease-out: 先快后慢）
        $progress = $currentStep / $steps;
        // ease-out: 1 - (1 - t)^2
        $eased = 1.0 - (1.0 - $progress) * (1.0 - $progress);

        $targetTop = $state['targetTop'];
        $targetLeft = $state['targetLeft'];
        $startTop = $this->readScrollTop($node);
        $startLeft = $this->readScrollLeft($node);
        // 使用原始目标重新计算每步位置，避免累积误差
        $origStartTop = $state['origStartTop'] ?? $startTop;
        $origStartLeft = $state['origStartLeft'] ?? $startLeft;

        $newTop = (int)($origStartTop + ($targetTop - $origStartTop) * $eased);
        $newLeft = (int)($origStartLeft + ($targetLeft - $origStartLeft) * $eased);

        $this->writeScrollTop($node, $newTop);
        $this->writeScrollLeft($node, $newLeft);

        // 更新状态
        $this->smoothScrollState['currentStep'] = $currentStep;

        // 直接重绘
        ($this->directRender)();

        if ($currentStep < $steps) {
            // 调度下一步
            $scheduler = Scheduler::getInstance();
            $scheduler->addMacrotask($this->smoothScrollStep(...));
        } else {
            $this->smoothScrollState = null;
        }
    }

    public function applyScrollTop(RenderNode $node, int $newScrollTop, bool $persist): void
    {
        // 检查是否启用平滑滚动
        if ($persist && $this->isSmoothScroll($node) && $newScrollTop !== $this->readScrollTop($node)) {
            $steps = 10;
            $this->smoothScrollState = [
                'smoothNode' => $node,
                'targetTop' => $newScrollTop,
                'targetLeft' => $this->readScrollLeft($node),
                'steps' => $steps,
                'currentStep' => 0,
                'origStartTop' => $this->readScrollTop($node),
                'origStartLeft' => $this->readScrollLeft($node),
            ];
            // 立即执行第一步
            $this->smoothScrollStep();
            return;
        }

        $this->writeScrollTop($node, $newScrollTop);

        if ($persist) {
            $bindKey = '';
            if ($node->sourceVNode !== null && $node->sourceVNode->props !== null) {
                $bindKey = $node->sourceVNode->props[':scroll-top'] ?? '';
            }
            if ($bindKey !== '') {
                $target = ($this->resolveComponentByGroupId)($node->groupId);
                $target->setBindValue($bindKey, (string) $newScrollTop);
            }
            ($this->requestRender)();
        } else {
            ($this->directRender)();
        }
    }

    /**
     * 应用 scrollLeft 到 RenderNode，可选持久化到组件 bind 值。
     * 镜像 applyScrollTop，操作横向滚动。
     */
    public function applyScrollLeft(RenderNode $node, int $newScrollLeft, bool $persist): void
    {
        // 检查是否启用平滑滚动
        if ($persist && $this->isSmoothScroll($node) && $newScrollLeft !== $this->readScrollLeft($node)) {
            $steps = 10;
            $this->smoothScrollState = [
                'smoothNode' => $node,
                'targetTop' => $this->readScrollTop($node),
                'targetLeft' => $newScrollLeft,
                'steps' => $steps,
                'currentStep' => 0,
                'origStartTop' => $this->readScrollTop($node),
                'origStartLeft' => $this->readScrollLeft($node),
            ];
            $this->smoothScrollStep();
            return;
        }

        $this->writeScrollLeft($node, $newScrollLeft);

        if ($persist) {
            $bindKey = '';
            if ($node->sourceVNode !== null && $node->sourceVNode->props !== null) {
                $bindKey = $node->sourceVNode->props[':scroll-left'] ?? '';
            }
            if ($bindKey !== '') {
                $target = ($this->resolveComponentByGroupId)($node->groupId);
                $target->setBindValue($bindKey, (string) $newScrollLeft);
            }
            ($this->requestRender)();
        } else {
            ($this->directRender)();
        }
    }
}
