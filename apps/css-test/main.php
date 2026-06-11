<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1280;
const WINDOW_HEIGHT = 3000;
const WINDOW_TITLE  = 'CSS Layout Test Suite';

// 强制使用 Skia CPU 后端渲染（确保抗锯齿+字体一致性）
putenv('PX_RENDERER=skia-cpu');

function main(): int
{
    $root = \ComponentFactory::create(AppComponent::class);
    $appDir = __DIR__;
    $app = Application::create()->mount($root, $appDir);

    // 支持 --dump-layout 命令行参数：输出布局快照 JSON
    global $argv;
    if (in_array('--dump-layout', $argv)) {
        $app->render();
        $app->dumpLayoutToFile($appDir . '/engine_layout.json');
        return 0;
    }

    $app->run();
    return 0;
}
