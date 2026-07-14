<?php
/**
 * GridFullDiag — 完整诊断：从 VideoGrid getVNodeTree 到 RenderNode 树的端到端测试
 * 
 * 模拟 Application::render 的两帧流程：
 *   帧1: getVNodeTree() → patchComponentTree() → updateFromVNode()
 *   帧2: markDirty → getVNodeTree() → patchComponentTree() → updateFromVNode()
 * 
 * 检查每个阶段后 grid div 的 children 状态。
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

use Px\Dom\VNode;
use Px\Render\RenderNode;
use Px\Render\RenderTreeManager;

require_once __DIR__ . '/../../../apps/bilibili/gen/ComponentFactory.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoGridComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoCardComponent.php';

echo "==========================================================\n";
echo " Grid 全链路诊断\n";
echo "==========================================================\n\n";

// ─── 工具函数：递归展开组件树 ─────────────────────────────
function expandTree(VNode $node, $owner, $app, $nextId) {
    if ($node->isComponent()) {
        expandComponent($node, $owner, $app, $nextId);
        return;
    }
    $children = is_array($node->children) ? $node->children : 
                ($node->children instanceof VNode ? [$node->children] : []);
    foreach ($children as $ch) {
        if ($ch instanceof VNode) {
            expandTree($ch, $owner, $app, $nextId);
        }
    }
}

function expandComponent($node, $owner, $app, &$nextId) {
    $className = $node->componentClass;
    if ($className === null) return;
    
    $instance = ComponentFactory::create($className);
    $scheduler = new \Px\Core\Scheduler();
    $instance->setScheduler($scheduler);
    $instance->setParent($owner);
    $instance->mount();
    
    $instanceId = $node->componentClass . '_' . $nextId++;
    $instance->setId($instanceId);
    
    if ($node->componentPropValues !== null) {
        foreach ($node->componentPropValues as $childKey => $value) {
            $instance->setBindValue($childKey, $value);
        }
    }
    
    $childRoot = $instance->getVNodeTree();
    $node->componentInstance = $instance;
    $node->children = $childRoot;
    
    // 递归展开子树的子组件
    expandTree($childRoot, $instance, $app, $nextId);
}

// ─── 工具函数：检查 VNode 树中所有 #component 的 instance 状态 ──
function checkVNodeInstances(VNode $node, string $path, array &$results): void {
    if ($node->isComponent()) {
        $has = $node->componentInstance !== null;
        $class = $node->componentClass ?? '?';
        $results[] = "{$path} #component({$class}) instance=" . ($has ? 'OK' : 'NULL');
    }
    $children = is_array($node->children) ? $node->children : 
                ($node->children instanceof VNode ? [$node->children] : []);
    foreach ($children as $i => $ch) {
        if ($ch instanceof VNode) {
            checkVNodeInstances($ch, "{$path}[{$i}]", $results);
        }
    }
}

// ─── 工具函数：检查 RenderNode 树中 grid div 的子节点 ──
function findGridRenderNode(RenderNode $node, string $path, array &$results): void {
    $dsp = $node->style['display'] ?? '';
    if ($dsp === 'grid') {
        $results[] = "{$path} grid RN: {$node->x},{$node->y} {$node->w}x{$node->h} children=" . count($node->children);
        foreach ($node->children as $i => $ch) {
            $results[] = "{$path}  -> child[{$i}]: type={$ch->type} {$ch->w}x{$ch->h}";
        }
    }
    foreach ($node->children as $i => $ch) {
        findGridRenderNode($ch, "{$path}[{$i}]", $results);
    }
}

use Px\Core\Scheduler;

// ══════════════════════════════════════════════════
// Step 1: 创建 VideoGrid + 初始化
// ══════════════════════════════════════════════════
echo "--- Step 1: 创建 VideoGrid ---\n";
$grid = new VideoGridComponent();
$scheduler = new Scheduler();
$grid->setScheduler($scheduler);
$grid->setRenderCallback(function() { echo "  [renderCallback triggered]\n"; });
$grid->mount();

echo "  videoList count: " . count($grid->videoList) . "\n";
echo "  dirty: " . ($grid->dirty ? 'true' : 'false') . "\n";
// vnodeCache is protected, skip direct access
echo "  vnodeCache: (protected)\n";

// ══════════════════════════════════════════════════
// Step 2: 帧1 — 首次获取 VNode 树
// ══════════════════════════════════════════════════
echo "\n--- Step 2: 帧1 — getVNodeTree() ---\n";
$tree1 = $grid->getVNodeTree();
echo "  tree1 type: {$tree1->type}\n";

// 找到 grid div
$flexDiv = $tree1->children;
$gridDiv = null;
if ($flexDiv instanceof VNode) {
    $fc = $flexDiv->children;
    if (is_array($fc)) {
        foreach ($fc as $ch) {
            if ($ch instanceof VNode) {
                $style = $ch->props['style'] ?? '';
                if (strpos($style, 'display:grid') !== false) {
                    $gridDiv = $ch;
                    break;
                }
            }
        }
    }
}

echo "  grid div " . ($gridDiv !== null ? 'FOUND' : 'NOT FOUND') . "\n";
if ($gridDiv !== null) {
    $gc = $gridDiv->children;
    if (is_array($gc)) {
        echo "  grid div children: " . count($gc) . " video-card VNodes\n";
        if (count($gc) > 0) {
            $first = $gc[0];
            echo "    first: isComponent={$first->isComponent()} componentInstance=" . 
                 ($first->componentInstance !== null ? 'set' : 'null') . "\n";
        }
    }
}

// 检查所有 #component instance 状态
$results = [];
checkVNodeInstances($tree1, '', $results);
$compInstancesNull = array_filter($results, fn($r) => strpos($r, 'NULL') !== false);
echo "  instance检查: " . count($results) . " 个 #component, " . 
     count($compInstancesNull) . " 个 instance=null\n";

// ══════════════════════════════════════════════════
// Step 3: 模拟 expandComponentNode（展开 grid 的子组件）
// ══════════════════════════════════════════════════
echo "\n--- Step 3: 展开 video-card 子组件（模拟 patchComponentTree） ---\n";
$app = new stdClass();
$nextId = 100;

if ($gridDiv !== null) {
    $gc = $gridDiv->children;
    if (is_array($gc)) {
        foreach ($gc as $i => $ch) {
            if ($ch instanceof VNode && $ch->isComponent()) {
                expandComponent($ch, $grid, $app, $nextId);
            }
        }
        echo "  展开后 video-card[0] instance: " . 
             ($gc[0]->componentInstance !== null ? 'OK' : 'NULL') . "\n";
        echo "  展开后 video-card[0] children: " . 
             ($gc[0]->children !== null ? 'set' : 'null') . "\n";
    }
}

// ══════════════════════════════════════════════════
// Step 4: 帧1 — updateFromVNode → RenderNode
// ══════════════════════════════════════════════════
echo "\n--- Step 4: 帧1 — updateFromVNode() ---\n";
$rtm = new RenderTreeManager();
$rootRN = $rtm->updateFromVNode($tree1, null, $grid, ['app' => $grid], null, 'app');

echo "  rootRN: " . ($rootRN !== null ? 'OK' : 'NULL') . "\n";
if ($rootRN !== null) {
    $gridResults = [];
    findGridRenderNode($rootRN, '', $gridResults);
    foreach ($gridResults as $r) {
        echo "  $r\n";
    }
}

// ══════════════════════════════════════════════════
// Step 5: 模拟 performUpdate（dirty=true）
// ══════════════════════════════════════════════════
echo "\n--- Step 5: 帧2 — performUpdate (dirty) ---\n";
$grid->performUpdate();
echo "  dirty: " . ($grid->dirty ? 'true' : 'false') . "\n";
echo "  vnodeCache: (protected)\n\n";

// ══════════════════════════════════════════════════
// Step 6: 模拟 matchComponentNode 复用路径
// (当 $newNode === $oldNode 的情况)
// ══════════════════════════════════════════════════
echo "--- Step 6: 模拟 matchComponentNode 复用路径 ---\n";

// 帧2的 key 场景：新旧树是同一个对象
$tree2 = $grid->getVNodeTree();  // 因为 dirty=true，会调用 render()
echo "  tree2 type: {$tree2->type}\n";
echo "  tree2 === tree1: " . ($tree2 === $tree1 ? 'SAME' : 'DIFFERENT') . "\n";

// 找到 grid div
$gridDiv2 = null;
$flexDiv2 = $tree2->children;
if ($flexDiv2 instanceof VNode) {
    $fc = $flexDiv2->children;
    if (is_array($fc)) {
        foreach ($fc as $ch) {
            if ($ch instanceof VNode) {
                $style = $ch->props['style'] ?? '';
                if (strpos($style, 'display:grid') !== false) {
                    $gridDiv2 = $ch;
                    break;
                }
            }
        }
    }
}

echo "  grid div2: " . ($gridDiv2 !== null ? 'FOUND' : 'NOT FOUND') . "\n";
if ($gridDiv2 !== null) {
    $gc = $gridDiv2->children;
    if (is_array($gc)) {
        echo "  grid div2 children: " . count($gc) . " video-card VNodes\n";
        if (count($gc) > 0) {
            echo "    child[0] instance: " . ($gc[0]->componentInstance !== null ? 'set' : 'NULL') . "\n";
            echo "    child[0] isComponent: " . ($gc[0]->isComponent() ? 'true' : 'false') . "\n";
            echo "    child[0] children: " . ($gc[0]->children !== null ? 'set' : 'null') . "\n";
        }
    }
}

// 模拟 matchComponentNode 中的 patchComponentTree
// 当 $oldNode === $newNode 时，$oldNode->children 被覆盖
echo "\n  模拟 patchComponentTree($newNode===树2, owner=grid, \$oldNode===树2):\n";

// 展开 tree2 中的 video-card 子组件
if ($gridDiv2 !== null) {
    $gc = $gridDiv2->children;
    if (is_array($gc)) {
        foreach ($gc as $i => $ch) {
            if ($ch instanceof VNode && $ch->isComponent()) {
                if ($ch->componentInstance === null) {
                    expandComponent($ch, $grid, $app, $nextId);
                }
            }
        }
        echo "  展开后 grid div2 children: " . count($gc) . "\n";
        echo "  展开后 child[0] instance: " . ($gc[0]->componentInstance !== null ? 'OK' : 'NULL') . "\n";
    }
}

$results2 = [];
checkVNodeInstances($tree2, '', $results2);
$compInstancesNull2 = array_filter($results2, fn($r) => strpos($r, 'NULL') !== false);
echo "  instance检查: " . count($results2) . " 个 #component, " . 
     count($compInstancesNull2) . " 个 instance=null\n";

// ══════════════════════════════════════════════════
// Step 7: 帧2 — updateFromVNode
// ══════════════════════════════════════════════════
echo "\n--- Step 7: 帧2 — updateFromVNode() ---\n";
$rtm2 = new RenderTreeManager();
$rootRN2 = $rtm2->updateFromVNode($tree2, null, $grid, ['app' => $grid], null, 'app');

echo "  rootRN2: " . ($rootRN2 !== null ? 'OK' : 'NULL') . "\n";
if ($rootRN2 !== null) {
    $gridResults2 = [];
    findGridRenderNode($rootRN2, '', $gridResults2);
    foreach ($gridResults2 as $r) {
        echo "  $r\n";
    }
    if (empty($gridResults2)) {
        echo "  === 警告: 未找到 grid div RenderNode! ===\n";
    }
}

echo "\n==========================================================\n";
echo " 诊断完成\n";
echo "==========================================================\n";
