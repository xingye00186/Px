<?php

namespace PxTest\Contracts;

use Px\Render\RenderNode;

/**
 * 布局策略契约测试基类。
 *
 * 任何新的 LayoutStrategy 只需继承此类 + 实现 provideTestCases()，
 * 即可自动验证基础契约：
 *   - resolve() 后 $node->layoutDirty 必须为 false
 *   - 所有子节点 w/h 为正值
 *   - 子节点坐标不为负
 */
abstract class LayoutStrategyContract
{
    /** @return array<string, array{input: RenderNode, expected: array}> */
    abstract public function provideTestCases(): array;

    /** 子类实现具体的 resolve 调用 */
    abstract protected function resolveNode(RenderNode $node): void;

    /** 运行契约验证 */
    public function runContractTests(): array
    {
        $results = [];
        foreach ($this->provideTestCases() as $name => $case) {
            $node = $case['input'];
            $this->resolveNode($node);

            $errors = [];
            if ($node->layoutDirty) {
                $errors[] = 'layoutDirty should be false after resolve';
            }
            if ($node->w <= 0 || $node->h <= 0) {
                $errors[] = "size invalid: {$node->w}x{$node->h}";
            }
            foreach ($node->children as $child) {
                if ($child->w <= 0 || $child->h <= 0) {
                    $errors[] = "child '{$child->type}' size invalid: {$child->w}x{$child->h}";
                }
            }

            $results[$name] = empty($errors) ? 'PASS' : ['FAIL', $errors];
        }
        return $results;
    }
}
