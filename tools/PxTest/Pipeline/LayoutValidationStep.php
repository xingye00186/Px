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
 */
class LayoutValidationStep implements PipelineStepInterface
{
    private array $issues = [];

    public function name(): string { return 'layout_validation'; }
    public function requires(): array { return ['dump_layout']; }

    public function execute(PipelineContext $ctx): StepResult
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
                    ? (int)($cur['x'] ?? 0) + (int)(($cur['visualW'] ?? $cur['w']) ?? 0)
                    : (int)($cur['y'] ?? 0) + (int)(($cur['visualH'] ?? $cur['h']) ?? 0);
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
    }
}
