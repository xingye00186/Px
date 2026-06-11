<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1280;
const WINDOW_HEIGHT = 700;
const WINDOW_TITLE  = 'Social Media';

function main(): int
{
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
