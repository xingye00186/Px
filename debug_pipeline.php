<?php
/**
 * Debug: 逐步检查渲染管线的每一步
 */
require_once __DIR__ . '/tests/css-standards/CssTestBase.php';

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Rendering\VNode;

if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 1440);
if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 900);
if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'Test');

$platform = new StubPlatform(1440, 900);
$scheduler = new Scheduler();
$app = new Application($platform, $scheduler);
echo "App created\n";

$vnode = VNode::h('div', ['style' => 'left:10px;top:20px;width:100px;height:50px'], 'Hello');

$root = new class($vnode, $app, $scheduler) extends \Px\ReactiveComponent {
    private VNode $vnode;
    public function __construct(VNode $vnode, $app, $scheduler) {
        parent::__construct('Root');
        $this->vnode = $vnode;
        $this->setScheduler($scheduler);
        $this->setRenderCallback(function() use ($app) { $app->requestRender(); });
    }
    public function render(): VNode { 
        echo "  [render() called]\n"; 
        $result = VNode::h('#root', [], $this->vnode);
        echo "  [#root created, child type: " . $this->vnode->type . "]\n";
        return $result;
    }
    public function setBindValue(string $k, string $v): void {}
    public function getBindValue(string $k): string { return ''; }
    public function onMount(): void { echo "  [onMount() called]\n"; }
};

echo "Mounting...\n";
$app->mount($root);
echo "Mount complete\n";

echo "Render (reflection)...\n";
$rm = new \ReflectionMethod($app, 'render');
$rm->setAccessible(true);
$rm->invoke($app);
echo "Render complete\n";

$rtm = $app->getRenderTreeManager();
$rootNode = $rtm->getRootRenderNode();
echo "rootNode: " . var_export($rootNode !== null, true) . "\n";

$ref = new \ReflectionProperty($app, 'activeVNodeTree');
$ref->setAccessible(true);
$avt = $ref->getValue($app);
echo "activeVNodeTree: " . var_export($avt !== null, true) . "\n";
if ($avt !== null) {
    echo "  type: " . $avt->type . "\n";
    echo "  children type: " . gettype($avt->children) . "\n";
}

if ($rootNode !== null) {
    $dump = $rtm->dumpRenderTree($rootNode, 1, []);
    echo "Dump length: " . strlen($dump) . "\n";
    echo "Dump:\n$dump\n";
} else {
    echo "Root node is NULL!\n";
    echo "Checking rootRenderNodes: " . count($rtm->getRootRenderNodes()) . "\n";
}
