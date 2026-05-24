<?php
/**
 * AOT 调试：追踪 callLayoutSegment 返回数组的类型
 */

// 模拟实际的布局数据
function mockGetLayout_dialog(): array {
    return [
        'elements' => [
            [
                'type' => 'rect',
                'x' => 0, 'y' => 0, 'w' => 328, 'h' => 420,
                'color' => 657930,
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

function callLayoutSegment(string $name): array {
    if ($name === 'about-dialog') return mockGetLayout_dialog();
    return ['elements' => [], 'buttons' => []];
}

// 模拟 BaseRenderer.render() 的收集逻辑
class TestRenderer {
    private array $activeLayouts = ['about-dialog' => 1];

    public function collectLayout(): array {
        $elements = []; $buttons = [];

        // 模拟 BaseRenderer.render() 的收集方式
        $layoutNames = array_keys($this->activeLayouts);
        $count = count($layoutNames);
        for ($i = 0; $i < $count; $i++) {
            $name = strval($layoutNames[$i]);
            echo "[DEBUG] calling callLayoutSegment('$name')\n";

            $seg = callLayoutSegment($name);
            echo "[DEBUG] seg type: " . gettype($seg) . "\n";
            echo "[DEBUG] seg is_array: " . (is_array($seg) ? 'true' : 'false') . "\n";

            // 检查 elements
            $elKey = 'elements';
            echo "[DEBUG] checking seg['$elKey']...\n";
            $elVal = $seg[$elKey] ?? null;
            echo "[DEBUG] seg['$elKey'] type: " . gettype($elVal) . "\n";
            echo "[DEBUG] seg['$elKey'] is_array: " . (is_array($elVal) ? 'true' : 'false') . "\n";

            // 检查 buttons
            $btnKey = 'buttons';
            echo "[DEBUG] checking seg['$btnKey']...\n";
            $btnVal = $seg[$btnKey] ?? null;
            echo "[DEBUG] seg['$btnKey'] type: " . gettype($btnVal) . "\n";
            echo "[DEBUG] seg['$btnKey'] is_array: " . (is_array($btnVal) ? 'true' : 'false') . "\n";

            // 使用 (array) 转换（当前 BaseRenderer 的做法）
            foreach ((array)$seg['elements'] as $idx => $el) {
                echo "[DEBUG] foreach element[$idx], type: " . gettype($el) . ", is_array: " . (is_array($el) ? 'true' : 'false') . "\n";
                $elements[] = $el;
            }
            foreach ((array)$seg['buttons'] as $idx => $btn) {
                echo "[DEBUG] foreach button[$idx], type: " . gettype($btn) . ", is_array: " . (is_array($btn) ? 'true' : 'false') . "\n";

                // 如果是数组，检查 condition 字段
                if (is_array($btn)) {
                    $cond = $btn['condition'] ?? null;
                    echo "[DEBUG]   btn['condition'] type: " . gettype($cond) . ", is_array: " . (is_array($cond) ? 'true' : 'false') . "\n";
                }

                $buttons[] = $btn;
            }
        }

        echo "[DEBUG] Total elements: " . count($elements) . "\n";
        echo "[DEBUG] Total buttons: " . count($buttons) . "\n";

        return ['elements' => $elements, 'buttons' => $buttons];
    }

    public function findMaxLayer(array $buttons): int {
        $maxLayer = 0;
        foreach ($buttons as $idx => $btn) {
            echo "[DEBUG] Phase1 foreach button[$idx], type: " . gettype($btn) . "\n";
            if (!is_array($btn)) {
                echo "[DEBUG]   skip: not array\n";
                continue;
            }

            $cond = $btn['condition'] ?? null;
            echo "[DEBUG]   condition type: " . gettype($cond) . "\n";
            if ($cond !== null && !is_array($cond)) {
                echo "[DEBUG]   skip: condition not array\n";
                continue;
            }

            $layer = $btn['layer'] ?? 0;
            echo "[DEBUG]   layer: $layer\n";
            if ($layer > $maxLayer) $maxLayer = $layer;
        }
        return $maxLayer;
    }
}

function main(): int {
    echo "=== AOT Debug: 追踪数组类型 ===\n\n";

    $renderer = new TestRenderer();
    $layout = $renderer->collectLayout();

    echo "\n=== Phase 1: 确定最高活跃层 ===\n";
    $maxLayer = $renderer->findMaxLayer($layout['buttons']);
    echo "maxLayer = $maxLayer\n";

    echo "\n=== 完成 ===\n";
    return 0;
}

main();