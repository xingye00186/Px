<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1280;
const WINDOW_HEIGHT = 1200;
const WINDOW_TITLE  = 'Medical Appointment';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    $appDir = __DIR__;
    $app = Application::create()->mount($root, $appDir);

    global $argv;
    // 使用 Application 的通用 CLI 参数处理器
    // 支持: --dump-layout, --dump-layout-after-frames=N
    if (Application::handleDumpArgs($app, $appDir, $argv)) {
        return 0;
    }

    $app->run();
    return 0;
}
