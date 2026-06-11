<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1800;
const WINDOW_HEIGHT = 1200;
const WINDOW_TITLE  = 'Music Player';

function main(): int
{
    // 强制使用 Skia CPU 后端渲染（确保抗锯齿+字体一致性）
    putenv('PX_RENDERER=skia-cpu');
    $root = ComponentFactory::create(AppComponent::class);
    $appDir = __DIR__;
    $app = Application::create()->mount($root, $appDir);

    global $argv;
    if (in_array('--dump-layout', $argv)) {
        $app->render();
        $app->dumpLayoutToFile($appDir . '/engine_layout.json');
        return 0;
    }

    $app->run();
    return 0;
}
