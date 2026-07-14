<?php
/**
 * VNodeToRenderNodeDiag — 诊断 VNode→RenderNode 转换中组件展开问题
 * 
 * 模拟 VideoGrid+VideoCard 场景，检查转换结果
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

use Px\Dom\VNode;
use Px\Render\RenderNode;
use Px\Render\RenderTreeManager;

// 手动安装 autoloader 使 ComponentFactory 可用
require_once __DIR__ . '/../../../apps/bilibili/gen/ComponentFactory.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoGridComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoCardComponent.php';

echo "========================================\n";
echo " VNode → RenderNode 诊断\n";
echo "========================================\n\n";

// 模拟 VideoGrid 的 render() 输出
echo "--- Step 1: 创建 VideoGrid 组件实例并调用 getVNodeTree() ---\n";

$component = new VideoGridComponent();

// 设置 scheduler（否则 markDirty -> scheduleUpdate 崩溃）
$scheduler = new \Px\Core\Scheduler();
$component->setScheduler($scheduler);
$component->mount();  // 这会触发 onMount() → videoList = allVideos
echo "  videoList count: " . count($component->videoList) . "\n";

$vnodeTree = $component->getVNodeTree();
echo "  vnodeTree type: {$vnodeTree->type}\n";

// 检查树结构
$flexDiv = $vnodeTree->children;
if ($flexDiv instanceof VNode) {
    echo "  flexDiv type: {$flexDiv->type}\n";
    $children = $flexDiv->children;
    if (is_array($children)) {
        echo "  flexDiv has " . count($children) . " children\n";
        foreach ($children as $i => $ch) {
            if ($ch instanceof VNode) {
                echo "    child[$i]: type={$ch->type} isComponent=" . ($ch->isComponent() ? 'true' : 'false') . "\n";
            }
        }
    }
}

// 检查 grid div
echo "\n--- Step 2: 找到 grid div ---\n";
$flexChildren = is_array($flexDiv->children) ? $flexDiv->children : [];
$gridDiv = null;
$titleDiv = null;
foreach ($flexChildren as $ch) {
    if ($ch instanceof VNode) {
        $style = $ch->props['style'] ?? '';
        if (strpos($style, 'display:grid') !== false) {
            $gridDiv = $ch;
            echo "  Found grid div!\n";
        }
        if (strpos($style, 'justify-content:space-between') !== false) {
            $titleDiv = $ch;
            echo "  Found title div!\n";
        }
    }
}

if ($gridDiv !== null) {
    $gridChildren = $gridDiv->children;
    if (is_array($gridChildren)) {
        echo "  grid div children count: " . count($gridChildren) . "\n";
        foreach ($gridChildren as $i => $ch) {
            if ($ch instanceof VNode) {
                echo "    child[$i]: type={$ch->type} isComponent=" . ($ch->isComponent() ? 'true' : 'false') . 
                     " componentClass=" . ($ch->componentClass ?? 'null') . "\n";
            }
        }
    } else {
        echo "  grid div children is not array: " . gettype($gridChildren) . "\n";
    }
} else {
    echo "  ERROR: grid div not found!\n";
    echo "  Rendering full tree:\n";
    dumpVNodeTree($vnodeTree);
}

// Step 3: 模拟 updateFromVNode
echo "\n--- Step 3: 模拟 updateFromVNode ---\n";

// 注意: 直接测试 RenderTreeManager 需要完整环境
// 这里我们手动将 VNode tree 的 grid children 转换为 RenderNode

// 简单地验证: 创建 RenderNode，检查 style
$divStyle = $gridDiv !== null ? $gridDiv->props['style'] ?? '' : '';
echo "  grid div style: $divStyle\n";
$gridChildren = $gridDiv !== null ? $gridDiv->children : [];
if (is_array($gridChildren)) {
    echo "  grid has " . count($gridChildren) . " children (VNode components)\n";
    if (count($gridChildren) > 0) {
        $first = $gridChildren[0];
        echo "  first child: componentClass={$first->componentClass}\n";
        echo "  first child componentPropValues keys: " . 
             ($first->componentPropValues !== null ? implode(',', array_keys($first->componentPropValues)) : 'null') . "\n";
    }
}

echo "\n========================================\n";
echo " 诊断完成\n";
echo "========================================\n";

function dumpVNodeTree(VNode $node, int $depth = 0): void {
    $indent = str_repeat('  ', $depth);
    echo "{$indent}[{$node->type}]";
    $style = $node->props['style'] ?? '';
    if ($style) {
        $display = '';
        if (preg_match('/display:([^;]+)/', $style, $m)) $display = $m[1];
        if ($display) echo " display=$display";
    }
    echo "\n";
    
    if ($node->children !== null) {
        if ($node->children instanceof VNode) {
            dumpVNodeTree($node->children, $depth + 1);
        } elseif (is_array($node->children)) {
            foreach ($node->children as $ch) {
                if ($ch instanceof VNode) {
                    dumpVNodeTree($ch, $depth + 1);
                } else {
                    echo "{$indent}  [non-VNode: " . gettype($ch) . "]\n";
                }
            }
        } else {
            echo "{$indent}  content: " . substr((string)$node->children, 0, 50) . "\n";
        }
    }
}
