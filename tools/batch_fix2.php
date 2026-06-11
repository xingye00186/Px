<?php
/**
 * batch_fix2.php — 快速批量修复：对 6 个内联样式项目的 auto_test.php 应用子串匹配阈值改进
 *
 * 在已有 batch_fix_auto_test.php 基础上，额外补充了多子串计数（substrCount ≥ 3 判定为父容器）逻辑。
 * 这是上一轮 batch_fix 的增量补丁。
 *
 * 目标项目: social-media, hotel-booking, kanban-board, product-detail, medical-appointment, finance-dashboard
 *
 * 用法: php tools/batch_fix2.php
 */
$projects = ["social-media","hotel-booking","kanban-board","product-detail","medical-appointment","finance-dashboard"];
foreach ($projects as $app) {
    $f = "D:\\Px\\apps\\" . $app . "\\auto_test.php";
    $c = file_get_contents($f);
    $orig = $c;
    $c = str_replace("\r\n", "\n", $c);
    $c = str_replace("if (!\$matched) {\n            foreach (\$engineTextIndex as \$eText => \$eIdxs) {", "if (!\$matched) {\n            \$substrCount = 0;\n            foreach (\$engineTextIndex as \$eText => \$eIdxs) {", $c);
    $c = str_replace("            }\n            if (\$matched) {", "            }\n            // If multiple engine substrings found in long browser text, it's concatenated parent container\n            if (!\$matched && \$substrCount >= 3) {\n                \$matched = true;\n            }\n            if (\$matched) {", $c);
    $c = str_replace("if (\$ratio > 0.15 && \$ratio < 0.93) {\n                        \$matched = true;\n                        break;\n                    }", "if (\$ratio > 0.15 && \$ratio < 0.93) {\n                        \$matched = true;\n                        break;\n                    }\n                    \$substrCount++;", $c);
    $c = str_replace("\n", "\r\n", $c);
    if ($c !== $orig) { file_put_contents($f, $c); echo "$app OK\n"; }
    else { echo "$app SAME\n"; }
}
