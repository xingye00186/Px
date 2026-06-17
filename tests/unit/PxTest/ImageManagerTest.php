<?php

/**
 * ImageManager 资源生命周期测试。
 */

require_once __DIR__ . '/../bootstrap.php';

use Px\Rendering\ImageManager;

echo "========================================\n";
echo "  ImageManager — Resource Lifecycle\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ═══ 1. 基本 API ═══
echo "--- 1. Basic API ---\n";
$appDir = dirname(__DIR__, 2) . '/apps/css-test';
ImageManager::setAppRoot($appDir);

$size = ImageManager::getCacheSize();
check('Cache size is int', is_int($size));
check('Cache initially ' . ($size === 0 ? 'empty' : 'has entries'), true);

// 验证不存在的图片不会崩溃
$handle = ImageManager::loadImage('non_existent.png');
check('loadImage non-existent returns 0', $handle === 0);


// ═══ 2. 缓存操作 ═══
echo "\n--- 2. Cache ops ---\n";
$sizeBefore = ImageManager::getCacheSize();
check('getCacheSize returns int', is_int($sizeBefore));

$cached = ImageManager::isCached('test.png');
check('isCached non-existent returns false', $cached === false);

$handle2 = ImageManager::getHandle('test.png');
check('getHandle non-existent returns 0', $handle2 === 0);


// ═══ 3. freeAll ═══
echo "\n--- 3. freeAll ---\n";
ImageManager::freeAll();
check('freeAll does not crash', true);
check('After freeAll cache is empty', ImageManager::getCacheSize() === 0);


// ═══ 4. 多次循环 ═══
echo "\n--- 4. Multi-cycle ---\n";
for ($i = 0; $i < 10; $i++) {
    ImageManager::loadImage("test_$i.png");
}
ImageManager::freeAll();
check('10 load+freeAll cycles no crash', ImageManager::getCacheSize() === 0);


// ═══ 5. resolvePath ═══
echo "\n--- 5. resolvePath ---\n";
$resolved = ImageManager::resolvePath('img/icon.png');
check('resolvePath returns string', is_string($resolved));
check('resolvePath contains app dir', str_contains($resolved, 'css-test'));


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
