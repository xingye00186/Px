<?php
/**
 * GridAppDiag — 精确模拟 Application::rebuildVNodeTree + updateFromVNode
 * 
 * 测试从 root 组件开始的全链路：AppComponent → MainContent → VideoGrid → VideoCard
 * 模拟完整的 two-frame 流程
 */

require_once __DIR__ . '/../bootstrap.php';

use Px\Dom\VNode;
use Px\Render\RenderNode;
use Px\Render\RenderTreeManager;
use Px\Core\Scheduler;

require_once __DIR__ . '/../../../apps/bilibili/gen/ComponentFactory.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/AppComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/MainContentComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoGridComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VideoCardComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/BannerCarouselComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/CategoryTabsComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/NavBarComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/FloatingButtonComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcInputComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcButtonComponent.php';
require_once __DIR__ . '/../../../apps/bilibili/gen/VcAvatarComponent.php';

use Px\Css\StyleEngine;

echo "==========================================================\n";
echo " Grid 全链路诊断 (App 级别)\n";
echo "==========================================================\n\n";

// 初始化主题系统
$baseTheme = ThemeData::light();

class FakeApp {
    public $nextComponentId = 1;
    public $componentByGroupId = [];
    public $scheduler;
    
    function __construct() {
        $this->scheduler = new Scheduler();
    }
    
    function registerComponent($groupId, $component) {
        $this->componentByGroupId[$groupId] = $component;
    }
    
    function unregisterComponent($groupId) {
        unset($this->componentByGroupId[$groupId]);
    }
}

// ------- 模拟 Application 的核心方法 -------

function setGroupIdRecursive($node, $groupId) {
    $node->groupId = $groupId;
    if ($node->children instanceof VNode) {
        setGroupIdRecursive($node->children, $groupId);
    } elseif (is_array($node->children)) {
        foreach ($node->children as $child) {
            if ($child instanceof VNode) {
                setGroupIdRecursive($child, $groupId);
            }
        }
    }
}

function parsePlaceholderPositioning($placeholderStyle) {
    $left = null; $top = null;
    $pairs = explode(';', $placeholderStyle);
    foreach ($pairs as $pair) {
        $pair = trim($pair);
        $lower = strtolower($pair);
        if (str_starts_with($lower, 'left:')) $left = (int) trim(substr($pair, 5));
        elseif (str_starts_with($lower, 'top:')) $top = (int) trim(substr($pair, 4));
    }
    if ($left === null && $top === null) return null;
    $result = [];
    if ($left !== null) $result['left'] = $left;
    if ($top !== null) $result['top'] = $top;
    return $result;
}

function expandComponentNode($node, $owner, $app) {
    $className = $node->componentClass;
    if ($className === null) return;
    
    $instance = ComponentFactory::create($className);
    $instance->setScheduler($app->scheduler);
    $instance->setParent($owner);
    $instance->mount();
    
    $instanceId = $node->componentClass . '_' . $app->nextComponentId++;
    $instance->setId($instanceId);
    $app->registerComponent($instanceId, $instance);
    
    if ($node->componentPropValues !== null) {
        foreach ($node->componentPropValues as $childKey => $value) {
            $instance->setBindValue($childKey, $value);
        }
    } elseif ($node->componentProps !== null && $owner !== null) {
        foreach ($node->componentProps as $childKey => $parentExpr) {
            if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                $instance->setBindValue($childKey, substr($parentExpr, 7));
            } else {
                $instance->setBindValue($childKey, $owner->getBindValue($parentExpr));
            }
        }
    }
    
    $childRoot = $instance->getVNodeTree();
    $node->componentInstance = $instance;
    $node->children = $childRoot;
    
    $placeholderStyle = $node->props['style'] ?? '';
    if ($placeholderStyle !== '') {
        $node->layoutOffset = parsePlaceholderPositioning($placeholderStyle);
    }
    
    setGroupIdRecursive($childRoot, $instanceId);
    
    // 递归展开子组件
    patchComponentTree($node->children, $instance, null, $app);
}

function vnodeChildrenToArray($children) {
    if ($children === null) return [];
    if ($children instanceof VNode) return [$children];
    if (is_array($children)) {
        return array_values(array_filter($children, fn($c) => $c instanceof VNode));
    }
    return [];
}

function matchComponentNode($newNode, $owner, $oldNode, $app) {
    $instance = null;
    
    if ($newNode->componentInstance !== null) {
        $instance = $newNode->componentInstance;
    } elseif ($oldNode !== null && $oldNode->isComponent()) {
        $sameClass = $oldNode->componentClass === $newNode->componentClass;
        $sameKey = ($oldNode->key ?? '') === ($newNode->key ?? '');
        if ($sameClass && $sameKey) {
            $instance = $oldNode->componentInstance;
        }
    }
    
    if ($instance !== null) {
        $instance->setParent($owner);
        
        if ($newNode->componentProps !== null) {
            foreach ($newNode->componentProps as $childKey => $parentExpr) {
                if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
                    $instance->setBindValue($childKey, substr($parentExpr, 7));
                } else {
                    $instance->setBindValue($childKey, $owner->getBindValue($parentExpr));
                }
            }
        }
        
        $newNode->componentInstance = $instance;
        $newNode->children = $instance->getVNodeTree();
        
        $placeholderStyle = $newNode->props['style'] ?? '';
        if ($placeholderStyle !== '') {
            $newNode->layoutOffset = parsePlaceholderPositioning($placeholderStyle);
        }
        
        setGroupIdRecursive($newNode->children, $instance->getId());
        $app->registerComponent($instance->getId(), $instance);
        
        patchComponentTree(
            $newNode->children,
            $instance,
            $oldNode !== null ? $oldNode->children : null,
            $app
        );
    } else {
        if ($oldNode !== null && $oldNode->componentInstance !== null) {
            $oldNode->componentInstance->unmount();
        }
        expandComponentNode($newNode, $owner, $app);
    }
}

function patchComponentTree($newNode, $owner, $oldNode, $app) {
    if (!$newNode->isComponent()) {
        $newNode->groupId = $owner->getId();
    }
    
    if ($newNode->isComponent()) {
        matchComponentNode($newNode, $owner, $oldNode, $app);
        return;
    }
    
    $oldChildren = $oldNode !== null
        ? vnodeChildrenToArray($oldNode->children)
        : [];
    $newChildren = vnodeChildrenToArray($newNode->children);
    
    $count = (int)min(count($oldChildren), count($newChildren));
    for ($i = 0; $i < $count; $i++) {
        patchComponentTree(
            $newChildren[$i],
            $owner,
            $oldChildren[$i],
            $app
        );
    }
    
    for ($i = $count; $i < count($newChildren); $i++) {
        patchComponentTree($newChildren[$i], $owner, null, $app);
    }
}

// ------- 工具函数 -------

function findGridRN($node, $depth=0) {
    $indent = str_repeat('  ', $depth);
    $dsp = $node->style['display'] ?? '';
    if ($dsp === 'grid') {
        echo "${indent}grid RN: {$node->x},{$node->y} {$node->w}x{$node->h} children=" . count($node->children) . "\n";
        foreach ($node->children as $i => $ch) {
            echo "${indent}  child[$i]: type={$ch->type} {$ch->w}x{$ch->h}\n";
        }
        return;
    }
    foreach ($node->children as $ch) {
        findGridRN($ch, $depth+1);
    }
}

function findGridVNode($node) {
    if ($node instanceof VNode) {
        $style = $node->props['style'] ?? '';
        if (strpos($style, 'display:grid') !== false) {
            return $node;
        }
    }
    $children = $node->children ?? [];
    if ($children instanceof VNode) return findGridVNode($children);
    if (is_array($children)) {
        foreach ($children as $ch) {
            if ($ch instanceof VNode) {
                $found = findGridVNode($ch);
                if ($found !== null) return $found;
            }
        }
    }
    return null;
}

// ══════════════════════════════════════════════════
// 主测试流程
// ══════════════════════════════════════════════════

echo "--- Step 1: 创建根组件 App ---\n";
$app = new FakeApp();
$rootComponent = new AppComponent();
$rootComponent->setScheduler($app->scheduler);
$rootComponent->mount();
$app->registerComponent('app', $rootComponent);

// 注册 class styles
if (method_exists($rootComponent, 'getClassStyles')) {
    // C2.9：由注册表迁至 StyleEngine（生产同径）。
    StyleEngine::registerComponentRules($rootComponent);
}

echo "--- Step 2: 帧1 — rebuildVNodeTree ---\n";

// 模拟 rebuildVNodeTree (Frame 1)
$oldTree = null;
$oldRegistry = $app->componentByGroupId;

$app->componentByGroupId = [];
$app->registerComponent('app', $rootComponent);

$activeVNodeTree = $rootComponent->getVNodeTree();

echo "  root tree type: {$activeVNodeTree->type}\n";

patchComponentTree($activeVNodeTree, $rootComponent, $oldTree, $app);

// 卸载不再存在的旧实例
foreach ($oldRegistry as $id => $instance) {
    if ($id !== 'app' && !isset($app->componentByGroupId[$id])) {
        $instance->unmount();
    }
}

// 检查 grid VNode
$gridVNode = findGridVNode($activeVNodeTree);
echo "  grid VNode " . ($gridVNode !== null ? 'FOUND' : 'NOT FOUND') . "\n";
if ($gridVNode !== null) {
    $gc = is_array($gridVNode->children) ? $gridVNode->children : [];
    echo "  grid VNode children: " . count($gc) . "\n";
    if (count($gc) > 0) {
        echo "  child[0] instance: " . ($gc[0]->componentInstance !== null ? 'OK' : 'NULL') . "\n";
    }
}

echo "\n--- Step 3: 帧1 — updateFromVNode ---\n";
$rtm = new RenderTreeManager();

// 模拟 render() 中的 candidates
$rootRenderNodes = $rtm->getRootRenderNodes();
$candidates = !empty($rootRenderNodes) ? $rootRenderNodes : null;

$rootRN = $rtm->updateFromVNode(
    $activeVNodeTree,
    null,
    $rootComponent,
    $app->componentByGroupId,
    $candidates,
    'app'
);
echo "  rootRN: " . ($rootRN !== null ? 'OK' : 'NULL') . "\n";
echo "  grid children in RN tree:\n";
findGridRN($rootRN);

echo "\n--- Step 4: 刷新微任务 (模拟 flushMicrotasks) ---\n";
$app->scheduler->flushMicrotasks();

echo "  render requested: " . ($rtm !== null ? 'yes' : 'no') . "\n";

echo "\n--- Step 5: 帧2 — rebuildVNodeTree (dirty) ---\n";

$oldTree2 = $activeVNodeTree;
$oldRegistry2 = $app->componentByGroupId;

$app->componentByGroupId = [];
$app->registerComponent('app', $rootComponent);

$activeVNodeTree2 = $rootComponent->getVNodeTree();
echo "  root tree2 === tree1: " . ($activeVNodeTree2 === $activeVNodeTree ? 'SAME' : 'DIFFERENT') . "\n";

patchComponentTree($activeVNodeTree2, $rootComponent, $oldTree2, $app);

// 卸载不再存在的旧实例
$unmounted = 0;
foreach ($oldRegistry2 as $id => $instance) {
    if ($id !== 'app' && !isset($app->componentByGroupId[$id])) {
        $instance->unmount();
        $unmounted++;
    }
}
echo "  unmounted old instances: $unmounted\n";

// 检查 grid VNode
$gridVNode2 = findGridVNode($activeVNodeTree2);
echo "  grid VNode2 " . ($gridVNode2 !== null ? 'FOUND' : 'NOT FOUND') . "\n";
if ($gridVNode2 !== null) {
    $gc = is_array($gridVNode2->children) ? $gridVNode2->children : [];
    echo "  grid VNode2 children: " . count($gc) . "\n";
    if (count($gc) > 0) {
        echo "  child[0] instance: " . ($gc[0]->componentInstance !== null ? 'OK' : 'NULL') . "\n";
    }
}

echo "\n--- Step 6: 帧2 — updateFromVNode ---\n";
$rtm2 = new RenderTreeManager();

$rootRN2 = $rtm2->updateFromVNode(
    $activeVNodeTree2,
    null,
    $rootComponent,
    $app->componentByGroupId,
    null,
    'app'
);
echo "  rootRN2: " . ($rootRN2 !== null ? 'OK' : 'NULL') . "\n";
echo "  grid children in RN tree (Frame 2):\n";
findGridRN($rootRN2);

echo "\n==========================================================\n";
echo " 诊断完成\n";
echo "==========================================================\n";
