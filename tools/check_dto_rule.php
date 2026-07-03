<?php
/**
 * DTO规则检查器 — 检测策略中直接写入 RenderNode 子节点坐标
 *
 * 规则：策略内禁止 $ch->x/w/y/h = ...（必须走 DTO 路径）
 * 允许：$node->x/w/y/h = ...（容器自身坐标）
 * 允许：$gridItems[$idx]->x = ...（DTO 操作）
 * 允许：$ch->x/w/y/h = $gridItems[$idx]->...（DTO → RenderNode sync-back）
 *
 * Usage: php tools/check_dto_rule.php
 */

$strategies = glob(__DIR__ . '/../framework/Rendering/Layout/*LayoutStrategy.php');
$violations = [];

foreach ($strategies as $f) {
    $basename = basename($f);
    foreach (file($f) as $i => $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '/' || $t[0] === '*') continue;

        // Match $ch->x =, $child->w = etc
        if (preg_match('/\$ch->\s*[xywh]\s*=/', $t)
            || preg_match('/\$child->\s*[xywh]\s*=/', $t)
            || preg_match('/\$children\s*\[[^\]]*\]\s*->\s*[xywh]\s*=/', $t)) {

            // Exclude DTO sync-backs and container self-writes
            $isSyncBack = (strpos($t, '$gridItems') !== false
                        || strpos($t, '$flexItems') !== false);
            $isSelfWrite = preg_match('/\$node->\s*[xywh]\s*=/', $t);

            if (!$isSyncBack && !$isSelfWrite) {
                $violations[] = $basename . ':' . ($i + 1) . '  ' . $t;
            }
        }
    }
}

$count = count($violations);
if ($count === 0) {
    echo "PASS: 0 violations\n";
    exit(0);
}
echo "FAIL: $count violations\n\n";
foreach ($violations as $v) echo "  $v\n";
echo "\nFix: 替换为 DTO (\$flexItems[\$idx]->x/y/w/h) 或 \$gridItems[\$idx]->x/y/w/h\n";
exit(1);
