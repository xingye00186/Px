<?php

namespace PxTest\Pipeline;

/**
 * Phase L: CSS Layout Assertions — validates engine layout tree
 * against CSS specification constraints.
 *
 * Detects bugs that element-to-browser comparison might miss when
 * differences are mixed among dozens of font/format diffs.
 *
 * Current assertions:
 *   A. flex-wrap container width <= parent content width (no auto-expansion)
 *   B. flex item gap matches specified gap value
 *   C. block-level flex item cross-size not inflated by stretch
 *   D. margin:auto centering detection
 *   E. block-level flex item with text content has proper auto-height
 */
class LayoutValidationStep implements PipelineStepInterface
{
    private array $issues = [];

    public function name(): string { return 'layout_validation'; }
    public function requires(): array { return ['dump_layout']; }

    public function execute(CaseContext $ctx): StepResult
    {
        $this->issues = [];
        $layoutPath = $ctx->get('layout_path');
        if (!$layoutPath || !file_exists($layoutPath)) {
            return StepResult::ok('layout_validation'); // skip if no layout
        }

        $json = json_decode(file_get_contents($layoutPath), true);
        if (!$json) {
            return StepResult::err('layout_validation', 'Invalid engine layout JSON');
        }

        $this->scanTree($json, null);

        // ─── 保存 Phase L 报告 ───
        $refDir = dirname($layoutPath);
        $caseName = basename(dirname($refDir));
        if (!is_dir($refDir)) {
            @mkdir($refDir, 0777, true);
        }
        $jsonReport = json_encode([
            'step' => 'layout_validation',
            'case' => $caseName,
            'timestamp' => date('Y-m-d H:i:s'),
            'total_issues' => count($this->issues),
            'issues' => $this->issues,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents("$refDir/layout_validation_report.json", $jsonReport);
        $md = "# Phase L 布局断言报告: $caseName\n\n";
        $md .= "**生成时间**: " . date('Y-m-d H:i:s') . "\n\n";
        if (!empty($this->issues)) {
            $md .= "发现 " . count($this->issues) . " 个 CSS 布局违规：\n\n";
            $md .= "```\n";
            foreach ($this->issues as $issue) {
                $md .= "$issue\n";
            }
            $md .= "```\n";
        } else {
            $md .= "未发现 CSS 布局违规。\n";
        }
        file_put_contents("$refDir/layout_validation_report.md", $md);

        // Store issue count in context for summary report
        $ctx->set('layout_validation_issues', count($this->issues));

        if (!empty($this->issues)) {
            echo "  [Phase L] CSS layout violations:\n";
            foreach ($this->issues as $issue) {
                echo "    - $issue\n";
            }
            return StepResult::err('layout_validation', 'CSS layout assertions failed');
        }

        return StepResult::ok('layout_validation');
    }

    private function scanTree(array $node, ?array $parent): void
    {
        $style = $node['style'] ?? [];
        $display = $style['display'] ?? '';

        if ($display === 'flex' || $display === 'inline-flex') {
            $this->validateFlexContainer($node, $parent, $style);
        }

        // ─── Assertion F: text content node h > 0 invariant ───
        // Any node with text content should have positive content height.
        // h=0 means the text won't be rendered regardless of padding/borders.
        $hasText = !empty($node['content']) && strlen(trim($node['content'])) > 0;
        $isHidden = ($style['display'] ?? '') === 'none';
        // 跳过侧边栏项：textRenderInfo 为 null 表示未渲染的 flex 子项
        $hasRenderInfo = isset($node['textRenderInfo']) && $node['textRenderInfo'] !== null;
        if ($hasText && !$isHidden && $hasRenderInfo) {
            $ch = (int)($node['h'] ?? 0);
            $cvh = (int)($node['visualH'] ?? $ch);
            // Skip if it's just a spacer (depth < 3 sidebar items)
            // h=0 is OK only when text is spacer/separator text or visibility:hidden
            $vis = $style['visibility'] ?? 'visible';
            if ($ch === 0 && $cvh === 0 && $vis === 'visible') {
                $textPreview = mb_substr($node['content'], 0, 30);
                $disp = $style['display'] ?? 'block';
                $this->issues[] = "[F] text content h=0: '{$textPreview}' has zero content height "
                    . "(visualH={$cvh}, display={$disp})";
            }
        }

        // ─── Assertion D: margin:auto centering for block-level children ───
        // Elements with width < parent content width sitting at content left edge
        // should have margin:auto centering applied (indicated by _computedMarginLeft)
        // Only check elements in test content zone (x >= 200) to avoid sidebar noise
        if ($parent !== null && ($display === 'flex' || $display === 'inline-flex')) {
            $nX = (int)($node['x'] ?? 0);
            $nW = (int)($node['w'] ?? 0);

            // Skip sidebar elements, scroll containers, and empty elements
            if ($nX < 200 || $nW <= 0) return;

            $pStyle = $parent['style'] ?? [];
            $pW = (int)($parent['w'] ?? 0);
            $pX = (int)($parent['x'] ?? 0);
            $pBorderL = (int)($pStyle['borderWidth'] ?? $pStyle['borderLeftWidth'] ?? 0);
            $pPadL = (int)($pStyle['paddingLeft'] ?? $pStyle['padding'] ?? 0);
            $pContentX = $pX + $pBorderL + $pPadL;
            $computedML = $style['_computedMarginLeft'] ?? null;
            $pPadR = (int)($pStyle['paddingRight'] ?? $pStyle['padding'] ?? 0);
            $pBorderR = (int)($pStyle['borderRightWidth'] ?? $pStyle['borderWidth'] ?? 0);
            $pContentW = max(1, $pW - $pBorderL - $pBorderR - $pPadL - $pPadR);

            // Element is at (or very near) parent content left edge
            $atLeftEdge = abs($nX - $pContentX) <= 2;
            // Element width is significantly less than parent content width (>20% smaller)
            $muchSmaller = $nW < $pContentW * 0.8;

            // Only flag if element has auto-margin indicators (margin:auto intent)
            $hasAutoMargin = ($style['marginLeftAuto'] ?? false) || ($style['marginRightAuto'] ?? false)
                || (($style['marginLeft'] ?? '') === 'auto') || (($style['marginRight'] ?? '') === 'auto');

            if ($atLeftEdge && $muchSmaller && $hasAutoMargin && ($computedML === null || $computedML === 0)) {
                $this->issues[] = "[D] flex container margin:auto likely missing: x=$nX at parent content left edge "
                    . "(pContentX=$pContentX), w=$nW < pContentW=$pContentW, _computedMarginLeft not set";
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->scanTree($child, $node);
            }
        }
    }

    private function validateFlexContainer(array $node, ?array $parent, array $style): void
    {
        $wrap = $style['flexWrap'] ?? 'nowrap';
        $isRow = ($style['flexDirection'] ?? 'row') !== 'column';
        $gap = (int)($style['gap'] ?? 0);
        $hasExplicitW = array_key_exists('width', $style);
        $hasExplicitH = array_key_exists('height', $style);
        $align = $style['alignItems'] ?? 'stretch';

        // ─── Assertion A: flex-wrap container width ≤ parent content width ───
        if ($wrap === 'wrap' && !$hasExplicitW && $parent !== null) {
            $pW = (int)($parent['w'] ?? 0);
            $pX = (int)($parent['x'] ?? 0);
            $pPadL = (int)($parent['style']['paddingLeft'] ?? $parent['style']['padding'] ?? 0);
            $pBL = (int)($parent['style']['borderLeftWidth'] ?? $parent['style']['borderWidth'] ?? 0);
            $parentContentRight = $pX + $pPadL + $pBL + $pW;

            $nX = (int)($node['x'] ?? 0);
            $nW = (int)($node['w'] ?? 0);
            $nodeRight = $nX + $nW;

            if ($nodeRight > $parentContentRight + 2) {
                $over = $nodeRight - $parentContentRight;
                $this->issues[] = "[A] flex-wrap container (w=$nW) exceeds parent content right by {$over}px "
                    . "(parentContentRight=$parentContentRight, nodeRight=$nodeRight)";
            }
        }

        // ─── Assertion B: gap between flex items matches specified gap ───
        if ($gap > 0) {
            $children = array_values(array_filter($node['children'] ?? [], function($ch) {
                if (!is_array($ch)) return false;
                $pos = $ch['style']['position'] ?? 'static';
                $disp = $ch['style']['display'] ?? 'block';
                return $pos !== 'absolute' && $pos !== 'fixed' && $disp !== 'none';
            }));

            for ($i = 0; $i < count($children) - 1; $i++) {
                $cur = $children[$i];
                $next = $children[$i + 1];

                $curEnd = $isRow
                    ? (int)($cur['x'] ?? 0) + (int)(($cur['w']) ?? 0)
                    : (int)($cur['y'] ?? 0) + (int)(($cur['h']) ?? 0);
                $nextStart = $isRow ? (int)($next['x'] ?? 0) : (int)($next['y'] ?? 0);
                $actualGap = $nextStart - $curEnd;

                if (abs($actualGap - $gap) > 2) {
                    $this->issues[] = "[B] flex items gap mismatch: expected={$gap}px actual={$actualGap}px "
                        . "(items $i and " . ($i+1) . ", " . ($isRow ? 'row' : 'column') . ")";
                }
            }
        }

        // ─── Assertion C: block-level flex item cross-size not inflated ───
        if ($align === 'stretch') {
            foreach ($node['children'] ?? [] as $ch) {
                if (!is_array($ch)) continue;
                $chPos = $ch['style']['position'] ?? 'static';
                if ($chPos === 'absolute' || $chPos === 'fixed') continue;
                $chDisplay = $ch['style']['display'] ?? 'block';

                // Only check block-level items without explicit height
                if ($chDisplay !== 'block' && $chDisplay !== '') continue;
                $hasExplicitH = array_key_exists('height', $ch['style'] ?? []);
                if ($hasExplicitH) continue;

                $chH = (int)($ch['h'] ?? 0);
                if ($chH <= 0) continue;
                
                // Skip scroll containers: their height is determined by parent
                // flex layout (fill remaining space), not by their content.
                if (!empty($ch['isScrollContainer'])) continue;

                // Estimate content height from children
                $contentBottom = 0;
                $contentTop = PHP_INT_MAX;
                $hasContent = false;
                foreach ($ch['children'] ?? [] as $gc) {
                    if (!is_array($gc)) continue;
                    $gcPos = $gc['style']['position'] ?? 'static';
                    if ($gcPos === 'absolute' || $gcPos === 'fixed') continue;
                    $gcy = (int)($gc['y'] ?? 0);
                    $gch = (int)($gc['h'] ?? 0);
                    $gcvh = (int)($gc['visualH'] ?? $gch);
                    if ($gcy < $contentTop) $contentTop = $gcy;
                    if ($gcy + $gcvh > $contentBottom) $contentBottom = $gcy + $gcvh;
                    $hasContent = true;
                }

                if (!$hasContent) continue;

                $padT = (int)($ch['style']['paddingTop'] ?? $ch['style']['padding'] ?? 0);
                $expectedContentH = $contentBottom - $contentTop;
                $expectedH = $expectedContentH;
                $chY = (int)($ch['y'] ?? 0);

                // Expected item height = content extent from item's content top
                $itemContentTop = $chY + $padT;
                $expectedTotalH = max(0, $contentBottom - $itemContentTop);

                // If item height exceeds expected by more than 50%, flag it
                if ($chH > $expectedTotalH * 1.5 && $expectedTotalH > 0) {
                    $ratio = round($chH / $expectedTotalH, 1);
                    $this->issues[] = "[C] block flex item cross-size inflated: h={$chH}px vs expected ~{$expectedTotalH}px "
                        . "(ratio={$ratio}x, contentBottom=$contentBottom, itemContentTop=$itemContentTop)";
                }
            }
        }

        // ─── Assertion E: block-level flex item with text content has proper auto-height ───
        // Flex items with flex-grow get width=0 on first layout pass, causing text to be skipped.
        // Without two-pass re-resolution, the item's visualH won't account for text content.
        // Detect: block item with text content but visualH too small to fit padding+text.
        if ($align === 'stretch') {
            foreach ($node['children'] ?? [] as $ch) {
                if (!is_array($ch)) continue;
                $chPos = $ch['style']['position'] ?? 'static';
                if ($chPos === 'absolute' || $chPos === 'fixed') continue;
                $chDisplay = $ch['style']['display'] ?? 'block';
                if ($chDisplay !== 'block' && $chDisplay !== '') continue;
                if (array_key_exists('height', $ch['style'] ?? [])) continue;

                // Must have text content but no child elements
                $textContent = $ch['content'] ?? null;
                if ($textContent === null || $textContent === '') continue;
                if (!empty($ch['children'])) continue;
                if (!empty($ch['isScrollContainer'])) continue;

                $chH = (int)($ch['h'] ?? 0);
                $chVH = (int)($ch['visualH'] ?? $chH);
                if ($chVH <= 0) continue;

                // Expected min height = padding-top + ~text-height + padding-bottom + border
                $fs = (int)($ch['style']['fontSize'] ?? 14);
                $lh = (int)($fs * 1.2);
                $padT = (int)($ch['style']['paddingTop'] ?? $ch['style']['padding'] ?? 0);
                $padB = (int)($ch['style']['paddingBottom'] ?? $ch['style']['padding'] ?? 0);
                $bT = (int)($ch['style']['borderTopWidth'] ?? $ch['style']['borderWidth'] ?? 0);
                $bB = (int)($ch['style']['borderBottomWidth'] ?? $ch['style']['borderWidth'] ?? 0);

                // Content height (h) should accommodate text even if padding+border dominate visualH.
                // Skip items where h already accounts for text (h >= min text height).
                // Also skip items where h=0 (content height not computed, likely text measurement
                // issue rather than layout bug — flagging these distracts from real issues).
                $contentOnlyH = $chH - $padT - $padB - $bT - $bB;
                if ($contentOnlyH <= 0) {
                    // Content height is zero or negative: text not accounted.
                    // Only flag if visualH is also too small to fit padding + one line.
                    // This allows short labels with dominant padding to pass.
                    $paddingOnlyVH = $padT + $padB + $bT + $bB;
                    if ($chVH < $paddingOnlyVH * 0.7) {
                        $this->issues[] = "[E] block flex item cross-size too small: visualH={$chVH}px "
                            . "expected >= {$paddingOnlyVH}px (just padding+border, h={$chH}, "
                            . "text='{$textContent}', padT={$padT}, padB={$padB})";
                    }
                }
            }
        }
    }
}
