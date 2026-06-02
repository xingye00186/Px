<?php

namespace Px\Core;

use native_types;

use Px\Rendering\RenderNode;
use Px\ReactiveComponent;

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
    private ?\Closure $resolveComponent = null;

    // ── 拖拽状态 ──────────────────────────
    private ?RenderNode $scrollDragTarget = null;
    private int $scrollDragStartX = 0;
    private int $scrollDragStartY = 0;
    private int $scrollDragStartScrollPos = 0;
    private bool $scrollDragIsHorizontal = false;

    public function __construct(
        callable $requestRender,
        callable $directRender,
        callable $resolveComponent
    ) {
        $this->requestRender = $requestRender;
        $this->directRender = $directRender;
        $this->resolveComponent = $resolveComponent;
    }

    // ── 滚轮事件 ─────────────────────────────

    /**
     * 鼠标滚轮事件 — 更新最近祖先滚动容器的 scroll 位置。
     * Shift 按下时走横向滚动，否则走竖向滚动。
     * $root 为 RenderNode 树的根节点。
     */
    public function handleScrollWheel($event, RenderNode $root): void
    {
        PerfCounter::start('scroll_process');
        try {
            $scrollNode = $this->findScrollContainerAt($event->x, $event->y, $root);
            if ($scrollNode === null) return;

            $delta = $event->delta ?? 0;
            $scrollAmount = (int)($delta / 40);

            if ($event->shiftDown ?? false) {
                // 横向滚动
                $contentW = $scrollNode->contentWidth;
                $containerW = $scrollNode->w;
                $maxScroll = max($contentW - $containerW, 0);
                if ($maxScroll <= 0) return;

                $newScrollLeft = max(0, min($maxScroll, $scrollNode->scrollLeft - $scrollAmount));
                if ($newScrollLeft !== $scrollNode->scrollLeft) {
                    $this->applyScrollLeft($scrollNode, $newScrollLeft, true);
                }
            } else {
                // 竖向滚动
                $contentH = $scrollNode->contentHeight;
                $containerH = $scrollNode->h;
                $maxScroll = max($contentH - $containerH, 0);
                if ($maxScroll <= 0) return;

                $newScrollTop = max(0, min($maxScroll, $scrollNode->scrollTop - $scrollAmount));
                if ($newScrollTop !== $scrollNode->scrollTop) {
                    $this->applyScrollTop($scrollNode, $newScrollTop, true);
                }
            }
        } finally {
            PerfCounter::end('scroll_process');
        }
    }

    // ── 容器查找 ─────────────────────────────

    /**
     * 查找鼠标坐标下的滚动容器（最深层的子孙优先）。
     * RenderNode.children 始终是数组，简化遍历逻辑。
     */
    public function findScrollContainerAt(int $x, int $y, RenderNode $node): ?RenderNode
    {
        // 反向遍历子节点（后渲染优先）
        for ($i = count($node->children) - 1; $i >= 0; $i--) {
            $child = $node->children[$i];
            $found = $this->findScrollContainerAt($x, $y, $child);
            if ($found !== null) return $found;
        }

        if ($node->isScrollContainer
            && $x >= $node->x && $x <= $node->x + $node->w
            && $y >= $node->y && $y <= $node->y + $node->h) {
            return $node;
        }
        return null;
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

        if (!$node->isScrollContainer) return null;

        // ── 竖滚动条（右侧，12px宽）──
        $contentH = $node->contentHeight;
        if ($contentH > $node->h) {
            $sbW = 12;
            $sbX = $node->x + $node->w - $sbW;

            if ($x >= $sbX && $x <= $sbX + $sbW
                && $y >= $node->y && $y <= $node->y + $node->h) {
                $ratio = min($node->h / max($contentH, 1), 1.0);
                $thumbH = max((int)($node->h * $ratio), 20);
                $maxScroll = max($contentH - $node->h, 0);
                $scrollRatio = $maxScroll > 0 ? $node->scrollTop / $maxScroll : 0.0;
                $thumbY = $node->y + (int)(($node->h - $thumbH) * $scrollRatio);

                if ($y >= $thumbY && $y <= $thumbY + $thumbH) {
                    return ['scrollNode' => $node, 'type' => 'thumb', 'isHorizontal' => false];
                }
                return ['scrollNode' => $node, 'type' => 'track', 'isHorizontal' => false];
            }
        }

        // ── 横滚动条（底部，12px高）──
        $contentW = $node->contentWidth;
        if ($contentW > $node->w) {
            $sbH = 12;
            $sbY = $node->y + $node->h - $sbH;

            if ($y >= $sbY && $y <= $sbY + $sbH
                && $x >= $node->x && $x <= $node->x + $node->w) {
                $ratio = min($node->w / max($contentW, 1), 1.0);
                $thumbW = max((int)($node->w * $ratio), 20);
                $maxScroll = max($contentW - $node->w, 0);
                $scrollRatio = $maxScroll > 0 ? $node->scrollLeft / $maxScroll : 0.0;
                $thumbX = $node->x + (int)(($node->w - $thumbW) * $scrollRatio);

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
        if ($isHorizontal) {
            $contentW = $scrollNode->contentWidth;
            $containerW = $scrollNode->w;
            $maxScroll = max($contentW - $containerW, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($containerW / max($contentW, 1), 1.0);
            $thumbW = max((int)($containerW * $ratio), 20);
            $trackW = $containerW - $thumbW;

            if ($type === 'track') {
                $clickOffset = $mouseX - $scrollNode->x - (int)($thumbW / 2);
                $newScrollLeft = (int)($maxScroll * $clickOffset / max($trackW, 1));
                $newScrollLeft = max(0, min($maxScroll, $newScrollLeft));
                $this->applyScrollLeft($scrollNode, $newScrollLeft, true);
            } elseif ($type === 'thumb') {
                $this->scrollDragTarget = $scrollNode;
                $this->scrollDragStartX = $mouseX;
                $this->scrollDragStartY = $mouseY;
                $this->scrollDragStartScrollPos = $scrollNode->scrollLeft;
                $this->scrollDragIsHorizontal = true;
            }
        } else {
            $contentH = $scrollNode->contentHeight;
            $containerH = $scrollNode->h;
            $maxScroll = max($contentH - $containerH, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($containerH / max($contentH, 1), 1.0);
            $thumbH = max((int)($containerH * $ratio), 20);
            $trackH = $containerH - $thumbH;

            if ($type === 'track') {
                $clickOffset = $mouseY - $scrollNode->y - (int)($thumbH / 2);
                $newScrollTop = (int)($maxScroll * $clickOffset / max($trackH, 1));
                $newScrollTop = max(0, min($maxScroll, $newScrollTop));
                $this->applyScrollTop($scrollNode, $newScrollTop, true);
            } elseif ($type === 'thumb') {
                $this->scrollDragTarget = $scrollNode;
                $this->scrollDragStartX = $mouseX;
                $this->scrollDragStartY = $mouseY;
                $this->scrollDragStartScrollPos = $scrollNode->scrollTop;
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
            $contentW = $node->contentWidth;
            $containerW = $node->w;
            $maxScroll = max($contentW - $containerW, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($containerW / max($contentW, 1), 1.0);
            $thumbW = max((int)($containerW * $ratio), 20);
            $trackW = $containerW - $thumbW;

            $dx = $mouseX - $this->scrollDragStartX;
            $scrollDx = (int)($maxScroll * $dx / max($trackW, 1));
            $newScrollLeft = max(0, min($maxScroll, $this->scrollDragStartScrollPos + $scrollDx));

            if ($newScrollLeft !== $node->scrollLeft) {
                $this->applyScrollLeft($node, $newScrollLeft, false);
            }
        } else {
            $contentH = $node->contentHeight;
            $containerH = $node->h;
            $maxScroll = max($contentH - $containerH, 0);
            if ($maxScroll <= 0) return;

            $ratio = min($containerH / max($contentH, 1), 1.0);
            $thumbH = max((int)($containerH * $ratio), 20);
            $trackH = $containerH - $thumbH;

            $dy = $mouseY - $this->scrollDragStartY;
            $scrollDy = (int)($maxScroll * $dy / max($trackH, 1));
            $newScrollTop = max(0, min($maxScroll, $this->scrollDragStartScrollPos + $scrollDy));

            if ($newScrollTop !== $node->scrollTop) {
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
            $this->applyScrollLeft($this->scrollDragTarget, $this->scrollDragTarget->scrollLeft, true);
        } else {
            $this->applyScrollTop($this->scrollDragTarget, $this->scrollDragTarget->scrollTop, true);
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
    public function applyScrollTop(RenderNode $node, int $newScrollTop, bool $persist): void
    {
        $node->scrollTop = $newScrollTop;

        if ($persist) {
            $bindKey = '';
            if ($node->sourceVNode !== null && $node->sourceVNode->props !== null) {
                $bindKey = $node->sourceVNode->props[':scroll-top'] ?? '';
            }
            if ($bindKey !== '') {
                $target = ($this->resolveComponent)($node->sourceVNode);
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
        $node->scrollLeft = $newScrollLeft;

        if ($persist) {
            $bindKey = '';
            if ($node->sourceVNode !== null && $node->sourceVNode->props !== null) {
                $bindKey = $node->sourceVNode->props[':scroll-left'] ?? '';
            }
            if ($bindKey !== '') {
                $target = ($this->resolveComponent)($node->sourceVNode);
                $target->setBindValue($bindKey, (string) $newScrollLeft);
            }
            ($this->requestRender)();
        } else {
            ($this->directRender)();
        }
    }
}
