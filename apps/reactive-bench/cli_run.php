<?php
/**
 * reactive-bench PHP CLI 运行器
 * 用于在 PHP CLI 模式下测试（非 AOT 编译）
 * 用法: php apps/reactive-bench/cli_run.php --case=SimpleCounter --cycles=10
 */
$pxRoot = dirname(__DIR__, 2);
require_once $pxRoot . '/tests/bootstrap/autoload.php';
require_once $pxRoot . '/framework/Reactive/Reactive.php';
require_once $pxRoot . '/framework/Reactive/DependentsMap.php';
require_once $pxRoot . '/framework/Reactive/Effect.php';
require_once $pxRoot . '/framework/Reactive/DependencyTracker.php';
require_once $pxRoot . '/framework/Reactive/Notifier.php';
require_once $pxRoot . '/framework/Component/BaseComponent.php';
require_once $pxRoot . '/framework/Component/ReactiveComponent.php';
require_once $pxRoot . '/framework/Dom/VNode.php';
require_once $pxRoot . '/framework/Core/Scheduler.php';
require_once __DIR__ . '/gen/AppComponent.php';
require_once __DIR__ . '/gen/DeepTreeNodeComponent.php';
require_once __DIR__ . '/gen/ComponentFactory.php';

if (!function_exists('objval')) { function objval($v,$t){return $v;} }
if (!function_exists('refval')) { function refval(&$v,$t){return $v;} }
if (!function_exists('any')) { function any($v){return $v;} }

exit(main());
