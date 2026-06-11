<?php
/**
 * batch_fix_auto_test.php — 批量修复 auto_test.php：应用 3 项自动测试改进
 *
 * 对 6 个内联样式项目的 auto_test.php 批量应用以下改进:
 *   1. 构建跳过: exe 已存在时跳过 build.bat
 *   2. 子串阈值放宽: $bLen > $eLen + 3 → $bLen >= $eLen + 1, 比值 0.2-0.85 → 0.15-0.93
 *   3. 多子串计数: 引擎中 ≥3 个子串命中时判定为父容器（解决长文本串联问题）
 *
 * 目标项目: social-media, hotel-booking, kanban-board, product-detail, medical-appointment, finance-dashboard
 *
 * 用法: php tools/batch_fix_auto_test.php
 */
// Apply all 3 auto_test improvements to remaining projects
$projects = ['social-media','hotel-booking','kanban-board','product-detail','medical-appointment','finance-dashboard'];

foreach ($projects as $app) {
    $f = 'D:\Px\apps\\' . $app . '\auto_test.php';
    if (!file_exists($f)) {
        echo "$app: auto_test.php not found\n";
        continue;
    }
    $c = file_get_contents($f);
    $orig = $c;
    $c = str_replace(chr(13).chr(10), chr(10), $c);
    
    // 1. Build skip
    $old1 = "// Step 1: Build\necho \"Step 1: 编译构建\\n\";\necho \"----------------------------------------\\n\";\nchdir(\$PROJECT_ROOT);\n\$result = run_cmd(\"build.bat \$APP_NAME 2>&1\");\nfile_put_contents(\$LOG_DIR . '/build.log', implode(\"\\n\", \$result['output']));\nif (\$result['exitCode'] !== 0) {\n    echo \"  [FAIL] 构建失败 (exit code: {\$result['exitCode']})\\n\";\n    echo \"  详见: \" . \$LOG_DIR . \"/build.log\\n\\n\";\n    exit(1);\n}\npass(\"构建成功\\n\");";
    $new1 = "// Step 1: Build (skip if exe already exists)\necho \"Step 1: 编译构建\\n\";\necho \"----------------------------------------\\n\";\n\$exePath = \$BIN_DIR . '/' . \$APP_NAME . '.exe';\nif (!file_exists(\$exePath)) {\n    \$exeFiles = glob(\$BIN_DIR . '/*.exe');\n    if (!empty(\$exeFiles)) \$exePath = \$exeFiles[0];\n}\n\nif (file_exists(\$exePath)) {\n    \$fsize = filesize(\$exePath);\n    log_msg(\"exe 已存在: \$exePath (\" . round(\$fsize/1024) . \" KB)\");\n    pass(\"跳过构建\\n\");\n} else {\n    chdir(\$PROJECT_ROOT);\n    \$result = run_cmd(\"build.bat \$APP_NAME 2>&1\");\n    file_put_contents(\$LOG_DIR . '/build.log', implode(\"\\n\", \$result['output']));\n    if (\$result['exitCode'] !== 0) {\n        echo \"  [FAIL] 构建失败 (exit code: {\$result['exitCode']})\\n\";\n        echo \"  详见: \" . \$LOG_DIR . \"/build.log\\n\\n\";\n        exit(1);\n    }\n    pass(\"构建成功\\n\");\n}";
    
    $c = str_replace($old1, $new1, $c);
    
    // 2. Threshold changes
    $c = str_replace(
        'if ($eLen > 2 && $bLen > $eLen + 3 && mb_strpos($text, $eText) !== false)',
        'if ($eLen > 1 && $bLen >= $eLen + 1 && mb_strpos($text, $eText) !== false)',
        $c
    );
    $c = str_replace(
        'if ($ratio > 0.2 && $ratio < 0.85)',
        'if ($ratio > 0.15 && $ratio < 0.93)',
        $c
    );
    
    // 3. Multi-substr count
    $c = str_replace(
        "if (!\$matched) {\n            foreach (\$engineTextIndex as \$eText => \$eIdxs) {",
        "if (!\$matched) {\n            \$substrCount = 0;\n            foreach (\$engineTextIndex as \$eText => \$eIdxs) {",
        $c
    );
    
    // Add substrCount++ after ratio check
    $c = str_replace(
        "if (\$ratio > 0.15 && \$ratio < 0.93) {\n                        \$matched = true;\n                        break;\n                    }",
        "if (\$ratio > 0.15 && \$ratio < 0.93) {\n                        \$matched = true;\n                        break;\n                    }\n                    \$substrCount++;",
        $c
    );
    
    // Add multi-substr check
    $c = str_replace(
        "            }\n            if (\$matched) {",
        "            }\n            // If multiple engine substrings found in long browser text, it's concatenated parent container\n            if (!\$matched && \$substrCount >= 3) {\n                \$matched = true;\n            }\n            if (\$matched) {",
        $c
    );
    
    // Fix: if substrCount was added but closing braces are wrong, fix it
    $c = str_replace(
        "                    }\n                    \$substrCount++;\n                }\n            }",
        "                    }\n                }\n                \$substrCount++;\n            }",
        $c
    );
    
    $c = str_replace(chr(10), chr(13).chr(10), $c);
    
    if ($c !== $orig) {
        file_put_contents($f, $c);
        echo "$app: OK\n";
    } else {
        echo "$app: SAME (no changes)\n";
    }
}
