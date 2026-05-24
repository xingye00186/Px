<?php
/**
 * AOT 类方法场景模拟
 * 模拟 BaseRenderer 在类方法中调用外部函数返回数组的情况
 */

// 外部函数（模拟 getLayout_X）
function externalGetLayout_aboutDialog(): array {
    return [
        'elements' => [
            [
                'type' => 'rect',
                'layer' => 1,
                'condition' => ['prop' => 'showDialog', 'op' => 'truthy'],
            ],
        ],
        'buttons' => [
            [
                'label' => 'Close',
                'x' => 114, 'y' => 265, 'w' => 100, 'h' => 34,
                'bg' => 38399, 'fg' => 16777215, 'border' => 1354239,
                'layer' => 1,
                'condition' => ['prop' => 'showDialog', 'op' => 'truthy'],
            ],
        ],
    ];
}

// 包装函数（模拟 callLayoutSegment）
function externalCallLayoutSegment(string $name): array {
    if ($name === 'about-dialog') return externalGetLayout_aboutDialog();
    return ['elements' => [], 'buttons' => []];
}

// 模拟 BaseRenderer 的类方法
class ExternalRenderer {
    private array $activeLayouts = ['about-dialog' => 1];

    // 类方法中调用外部函数（这正是 AOT 可能出问题的场景）
    public function getActiveLayout(): array {
        $allElements = []; $allButtons = [];

        // 场景1：使用 (array) 转换（AOT 可能有问题）
        $layoutNames = array_keys($this->activeLayouts);
        $count = count($layoutNames);
        for ($i = 0; $i < $count; $i++) {
            $name = strval($layoutNames[$i]);
            $seg = externalCallLayoutSegment($name);

            // 旧方式：(array) 强制转换
            // foreach ((array)$seg['elements'] as $el) $allElements[] = $el;
            // foreach ((array)$seg['buttons'] as $btn) $allButtons[] = $btn;

            // 新方式：显式类型检查
            if (!is_array($seg)) continue;
            $els = $seg['elements'] ?? null;
            $btns = $seg['buttons'] ?? null;
            if (is_array($els)) {
                foreach ($els as $el) {
                    if (is_array($el)) $allElements[] = $el;
                }
            }
            if (is_array($btns)) {
                foreach ($btns as $btn) {
                    if (is_array($btn)) $allButtons[] = $btn;
                }
            }
        }
        return ['elements' => $allElements, 'buttons' => $allButtons];
    }

    public function findMaxLayer(array $buttons): int {
        $maxLayer = 0;
        foreach ($buttons as $btn) {
            if (!is_array($btn)) continue;
            $cond = $btn['condition'] ?? null;
            if ($cond !== null && !is_array($cond)) continue;
            $layer = $btn['layer'] ?? 0;
            if ($layer > $maxLayer) $maxLayer = $layer;
        }
        return $maxLayer;
    }

    // 模拟 evalCondition（AOT 可能出问题的地方）
    public function evalCondition(array $cond): bool {
        $prop = $cond['prop'] ?? '';
        $op = $cond['op'] ?? '';
        if ($op === 'truthy') {
            // 模拟 showDialog 为 true
            return $prop === 'showDialog' ? true : false;
        }
        return false;
    }

    public function testConditionEval(array $buttons): void {
        echo "=== 测试 evalCondition ===\n";
        foreach ($buttons as $idx => $btn) {
            if (!is_array($btn)) {
                echo "button[$idx]: not array, skipped\n";
                continue;
            }
            $cond = $btn['condition'] ?? null;
            echo "button[$idx]: ";
            if ($cond === null) {
                echo "condition is null\n";
            } elseif (!is_array($cond)) {
                echo "condition type=" . gettype($cond) . ", skipped\n";
            } else {
                echo "condition OK, eval=" . ($this->evalCondition($cond) ? 'true' : 'false') . "\n";
            }
        }
    }
}

function main(): int {
    echo "=== AOT 类方法场景模拟测试 ===\n\n";

    $renderer = new ExternalRenderer();

    echo "1. 收集布局数据\n";
    $layout = $renderer->getActiveLayout();
    echo "   elements: " . count($layout['elements']) . "\n";
    echo "   buttons: " . count($layout['buttons']) . "\n";

    echo "\n2. 确定最高活跃层\n";
    $maxLayer = $renderer->findMaxLayer($layout['buttons']);
    echo "   maxLayer = $maxLayer\n";

    echo "\n3. 测试条件求值\n";
    $renderer->testConditionEval($layout['buttons']);

    echo "\n=== 测试完成 ===\n";
    return 0;
}

main();