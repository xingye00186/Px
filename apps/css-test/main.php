<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1600;
const WINDOW_HEIGHT = 800;
const WINDOW_TITLE  = 'CSS Test Sandbox';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    $appDir = __DIR__;
    $app = Application::create()->mount($root, $appDir);

    global $argv;
    // 支持 --dump-layout 和 --dump-layout-after-frames=N
    if (Application::handleDumpArgs($app, $appDir, $argv)) {
        return 0;
    }

    $app->run();
    return 0;
}
