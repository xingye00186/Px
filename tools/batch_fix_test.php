<?php
/**
 * batch_fix_test.php — 调试用：枚举项目列表
 *
 * 简单的项目名打印脚本，用于验证 batch_fix 的目标项目列表是否正确。
 * 不修改任何文件。
 *
 * 用法: php tools/batch_fix_test.php
 */
$p = ['social-media','hotel-booking','kanban-board','product-detail','medical-appointment','finance-dashboard'];
foreach ($p as $a) { echo $a . "\n"; }
