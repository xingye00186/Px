<?php

namespace PxTest\Pipeline;

use PxTest\Comparison\ComparatorRegistry;

/**
 * Element compare step â€?with Phase F overflow detection.
 *
 * Phase F: checks textRenderInfo.textWidth vs parent contentW
 * to detect render blind spots (text overflow not visible in layout JSON).
 */
class ElementCompareStep implements PipelineStepInterface
{
    private ComparatorRegistry $registry;
    public function __construct(ComparatorRegistry $registry = null) {
        $this->registry = $registry ?? ComparatorRegistry::default();
    }
    public function name(): string { return 'element_compare'; }
    public function requires(): array { return ['browser_ref']; }

    public function execute(PipelineContext $ctx): StepResult
    {
        $layout = $ctx->get('layout_json');
        $ref = $ctx->get('browser_ref');
        if ($layout === null || $ref === null) return StepResult::err('element_compare', 'Missing data');

        $results = $this->registry->compareAll(
            ['output' => $layout],
            ['output' => $ref],
            new \PxTest\Core\ToleranceConfig()
        );

        // Phase F: overflow detection
        $overflowIssues = $this->detectOverflow($layout);
        if (!empty($overflowIssues)) {
            echo "  [Phase F] overflow detected:\n";
            foreach ($overflowIssues as $issue) {
                echo "    - $issue\n";
            }
            $ctx->set('overflow_issues', $overflowIssues);
        }

        $allPassed = !in_array(false, array_map(fn($r) => $r->passed, $results));
        return $allPassed ? StepResult::ok('element_compare') : StepResult::err('element_compare', 'Differences found');
    }

    /**
     * Phase F: detect text overflow where textRenderInfo.textWidth exceeds parent contentW.
     */
    private function detectOverflow(string $layoutJson): array
    {
        $issues = [];
        $data = json_decode($layoutJson, true);
        if (!$data || !isset($data['children'])) return $issues;

        $this->scanOverflow($data, null, $issues);
        return $issues;
    }

    private function scanOverflow(array $node, ?array $parent, array &$issues): void
    {
        // Check text nodes for overflow
        if (($node['type'] ?? '') === 'text' || ($node['type'] ?? '') === 'span') {
            $textWidth = $node['textRenderInfo']['textWidth']
                ?? $node['textWidth']
                ?? $node['w']
                ?? 0;
            if ($parent && isset($parent['contentW'])) {
                $contentW = $parent['contentW'];
                if ($textWidth > $contentW && $contentW > 0) {
                    $text = $node['content'] ?? $node['text'] ?? '(unknown)';
                    if (is_string($text) && mb_strlen($text) > 20) {
                        $text = mb_substr($text, 0, 20) . '...';
                    }
                    $issues[] = sprintf(
                        'text "%s" width=%d exceeds parent contentW=%d (overflow by %dpx)',
                        $text, $textWidth, $contentW, $textWidth - $contentW
                    );
                }
            }
        }

        // Recurse into children
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->scanOverflow($child, $node, $issues);
            }
        }
    }
}
