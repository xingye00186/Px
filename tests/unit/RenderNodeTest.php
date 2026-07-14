<?php

/**
 * RenderNode 单元测试
 *
 * 覆盖范围：
 * - 属性默认值
 * - markLayoutDirty 传播逻辑
 * - markSubtreeDirty 仅子树
 * - needsPaint / markPainted 帧号机制
 * - addChild / clearChildren 树管理
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Render\RenderNode;
use Px\Css\ComputedStyle;
use Px\Dom\VNode;

// ─────────────────────────────────────────────
// 1. 属性默认值
// ─────────────────────────────────────────────
$rn = new RenderNode('div', new ComputedStyle(['width' => 100, 'height' => 50]));
assert($rn->type === 'div', 'type 应为 div');
assert($rn->style === ['width' => 100, 'height' => 50], 'style 应等于传入值');
assert($rn->x === 0, 'x 默认 0');
assert($rn->y === 0, 'y 默认 0');
assert($rn->w === 0, 'w 默认 0');
assert($rn->h === 0, 'h 默认 0');
assert($rn->layoutDirty === true, 'layoutDirty 初始为 true');
assert($rn->lastPaintFrame === 0, 'lastPaintFrame 初始为 0');
assert($rn->lastScrollTop === 0, 'lastScrollTop 初始为 0');
assert($rn->parent === null, 'parent 初始为 null');
assert($rn->children === [], 'children 初始为空数组');
assert($rn->sourceVNode === null, 'sourceVNode 初始为 null');
assert($rn->groupId === null, 'groupId 初始为 null');
echo "[PASS] RenderNode 属性默认值正确\n";

// ─────────────────────────────────────────────
// 2. markLayoutDirty 向上传播
// ─────────────────────────────────────────────
$parent = new RenderNode('div');
$child = new RenderNode('span');
$parent->addChild($child);

// 标记子节点 dirty，传播到父节点
$parent->layoutDirty = false;
$child->layoutDirty = false;
$child->markLayoutDirty(true);

assert($child->layoutDirty === true, '子节点应为 dirty');
assert($parent->layoutDirty === true, '父节点也应被标记为 dirty（向上传播）');
echo "[PASS] markLayoutDirty(true) 向上传播\n";

// ─────────────────────────────────────────────
// 3. markLayoutDirty 不传播
// ─────────────────────────────────────────────
$parent2 = new RenderNode('div');
$child2 = new RenderNode('span');
$parent2->addChild($child2);

$parent2->layoutDirty = false;
$child2->layoutDirty = false;
$child2->markLayoutDirty(false);

assert($child2->layoutDirty === true, '子节点应为 dirty');
assert($parent2->layoutDirty === false, '父节点不应被标记为 dirty（不传播）');
echo "[PASS] markLayoutDirty(false) 不向上传播\n";

// ─────────────────────────────────────────────
// 4. markSubtreeDirty 仅子树（不向上传播）
// ─────────────────────────────────────────────
$grandparent = new RenderNode('div');
$parent3 = new RenderNode('div');
$child3 = new RenderNode('span');
$grandchild = new RenderNode('text');
$parent3->addChild($child3);
$grandparent->addChild($parent3);
$child3->addChild($grandchild);

// 都 clean
$grandparent->layoutDirty = false;
$parent3->layoutDirty = false;
$child3->layoutDirty = false;
$grandchild->layoutDirty = false;

// 仅标记子树
$parent3->markSubtreeDirty();

assert($parent3->layoutDirty === true, 'parent3 应被标记为 dirty');
assert($child3->layoutDirty === true, 'child3 应被标记为 dirty');
assert($grandchild->layoutDirty === true, 'grandchild 应被标记为 dirty');
assert($grandparent->layoutDirty === false, 'grandparent 不应被标记为 dirty（不向上传播）');
echo "[PASS] markSubtreeDirty 仅标记子树，不向上传播\n";

// ─────────────────────────────────────────────
// 5. needsPaint / markPainted 帧号机制
// ─────────────────────────────────────────────
$rn2 = new RenderNode('div');
$rn2->layoutDirty = false;
$rn2->lastPaintFrame = 0;

// 未绘制过 → 需要绘制
assert($rn2->needsPaint(1) === true, '未绘制过的节点需要绘制');
echo "[PASS] needsPaint 未绘制节点返回 true\n";

$rn2->markPainted(1);
assert($rn2->lastPaintFrame === 1, 'markPainted 更新帧号为 1');
assert($rn2->needsPaint(1) === false, '同一帧号已绘制则不需要再绘制');
echo "[PASS] markPainted 后同帧不需要重新绘制\n";

// 下一帧需要绘制
assert($rn2->needsPaint(2) === true, '新帧号需要重新绘制');
echo "[PASS] 新帧号需要重新绘制\n";

// layoutDirty=true → 需要绘制（无论帧号）
$rn2->markPainted(3);
assert($rn2->needsPaint(4) === false, '干净节点可跳过');
$rn2->layoutDirty = true;
assert($rn2->needsPaint(4) === true, '脏节点无论帧号都需要绘制');
echo "[PASS] layoutDirty=true 时 needsPaint 始终返回 true\n";

// ─────────────────────────────────────────────
// 6. addChild / clearChildren
// ─────────────────────────────────────────────
$parent4 = new RenderNode('div');
$child4 = new RenderNode('span');
$child5 = new RenderNode('button');

$parent4->addChild($child4);
$parent4->addChild($child5);

assert(count($parent4->children) === 2, '应有 2 个子节点');
assert($child4->parent === $parent4, 'child4 的 parent 应指向父节点');
assert($child5->parent === $parent4, 'child5 的 parent 应指向父节点');

$parent4->clearChildren();
assert($parent4->children === [], 'clearChildren 后应为空数组');
echo "[PASS] addChild/clearChildren 正常工作\n";

// ─────────────────────────────────────────────
// 7. 空 content 与 key
// ─────────────────────────────────────────────
$rn3 = new RenderNode('text', null, 'Hello');
assert($rn3->content === 'Hello', '文本内容应为 Hello');
assert($rn3->key === null, 'key 应为 null');

$rn4 = new RenderNode('div', null, null, 'item-1');
assert($rn4->key === 'item-1', 'key 应为 item-1');
echo "[PASS] content 和 key 构造参数正确\n";

// ─────────────────────────────────────────────
// 报告
// ─────────────────────────────────────────────
echo "\nRenderNodeTest: 全部通过 ✓\n";
echo "Results: 10/10 passed\n";
