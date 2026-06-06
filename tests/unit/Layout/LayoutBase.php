<?php
/**
 * LayoutBase — CSS 布局标准测试共享辅助函数
 *
 * 提供 makeNode, treeToText, runResolver, assert_layout_tree 等函数
 * 供 FlexLayoutTest / ComboLayoutTest 等使用。
 *
 * Usage:
 *   require_once __DIR__ . '/../bootstrap.php';
 *   require_once __DIR__ . '/LayoutBase.php';
 */

use Px\Rendering\RenderNode;
use Px\Rendering\LayoutResolver;

/**
 * 构建 RenderNode 树
 */
function makeNode(string $type, array $style = [], array $children = [], ?string $content = null): RenderNode
{
    $node = new RenderNode($type, $style, $content);
    foreach ($children as $child) {
        $node->addChild($child);
    }
    return $node;
}

/**
 * 标准 LayoutResolver 包装
 */
function runResolver(RenderNode $root): LayoutResolver
{
    $resolver = new LayoutResolver();
    $resolver->resolve($root);
    return $resolver;
}

/**
 * 将布局结果树输出为易读的文本格式
 *
 * 包含: 类型、display、position、坐标、scroll 属性
 */
function treeToText(RenderNode $node, int $depth = 0): string
{
    $indent = str_repeat('  ', $depth);
    $display = $node->style['display'] ?? 'block';
    $position = $node->style['position'] ?? 'static';

    // 基础信息: [类型] display position x y w h layer
    $parts = [
        sprintf('[%s]', $node->type),
        sprintf('d=%s', $display),
        sprintf('pos=%s', $position),
        sprintf('x=%d y=%d w=%d h=%d', $node->x, $node->y, $node->w, $node->h),
        sprintf('layer=%d', $node->layer),
    ];

    // 文本内容
    if ($node->content !== null) {
        $parts[] = 'text="' . $node->content . '"';
    }

    // key (v-for)
    if ($node->key !== null) {
        $parts[] = 'key=' . $node->key;
    }

    // 滚动容器属性
    if ($node->isScrollContainer) {
        $parts[] = sprintf('scroll(scrollTop=%d ch=%d cw=%d)',
            $node->scrollTop, $node->contentHeight, $node->contentWidth);
    }

    $result = $indent . implode(' ', $parts) . "\n";

    foreach ($node->children as $child) {
        $result .= treeToText($child, $depth + 1);
    }
    return $result;
}

/**
 * 断言布局结果，失败时自动输出完整布局树
 */
function assert_layout_tree(RenderNode $root, array $nodePath, array $expected, string $msg = ''): void
{
    $target = $root;
    foreach ($nodePath as $segment) {
        if (is_string($segment)) {
            $target = $target->$segment;
        } else {
            $target = $target->children[$segment];
        }
    }
    try {
        assert_layout($target, $expected, $msg);
    } catch (\AssertionError $e) {
        echo "--- 布局树 dump（当前实际值）---\n";
        echo treeToText($root);
        throw $e;
    }
}
