<?php

/**
 * 布局策略契约测试 — 验证 LayoutStrategyContract 基类工作正常。
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../tools/PxTest/bootstrap.php';

use PxTest\Contracts\LayoutStrategyContract;
use Px\Render\RenderNode;

echo "========================================\n";
echo "  Layout Strategy Contract Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// 具体实现：测试用的布局策略（不做实际计算，仅验证契约）
class TestLayoutStrategy extends LayoutStrategyContract
{
    protected function resolveNode(RenderNode $node): void
    {
        $node->layoutDirty = false;
        $node->w = max($node->w, 100);
        $node->h = max($node->h, 50);
        foreach ($node->children as $child) {
            $child->layoutDirty = false;
            $child->w = max($child->w, 50);
            $child->h = max($child->h, 20);
        }
    }

    public function provideTestCases(): array
    {
        // 有效案例
        $validNode = new RenderNode('div', ['width' => 200, 'height' => 100]);
        $validNode->children[] = new RenderNode('span', [], 'child');

        return [
            'valid_div' => ['input' => $validNode, 'expected' => ['w' => 200, 'h' => 100]],
        ];
    }
}

// ═══ 1. 契约验证：合理输入通过 ═══
echo "--- 1. Valid input passes ---\n";
$strategy = new TestLayoutStrategy();
$results = $strategy->runContractTests();
check('Valid case passes', ($results['valid_div'] ?? null) === 'PASS');


// ═══ 2. 契约验证：layoutDirty 检测 ═══
echo "\n--- 2. Dirty detection ---\n";
class DirtyStrategy extends LayoutStrategyContract
{
    protected function resolveNode(RenderNode $node): void { /* 故意不清除 dirty */ }
    public function provideTestCases(): array {
        return ['dirty_test' => ['input' => new RenderNode('div', [], ''), 'expected' => []]];
    }
}
$r2 = (new DirtyStrategy())->runContractTests();
check('Dirty strategy detected', $r2['dirty_test'][0] === 'FAIL');


// ═══ 3. 契约验证：尺寸为零检测 ═══
echo "\n--- 3. Zero size detection ---\n";
class ZeroSizeStrategy extends LayoutStrategyContract
{
    protected function resolveNode(RenderNode $node): void { $node->layoutDirty = false; }
    public function provideTestCases(): array {
        return ['zero_test' => ['input' => new RenderNode('div', [], ''), 'expected' => []]];
    }
}
$r3 = (new ZeroSizeStrategy())->runContractTests();
check('Zero size detected', $r3['zero_test'][0] === 'FAIL');


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
