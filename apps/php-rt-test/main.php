<?php

const APP_PLATFORM = 'win32';
const WINDOW_WIDTH = 1600;
const WINDOW_HEIGHT = 800;
const WINDOW_TITLE = 'PHP RT Test';

function main(): int
{
    global $argv;

    \Px\Core\Application::$HEADLESS = in_array('--headless', $argv);

    $root = \ComponentFactory::create(\AppComponent::class);
    $app = \Px\Core\Application::create()->mount($root, __DIR__);

    if (\Px\Core\Application::handleDumpArgs($app, __DIR__, $argv)) {
        return 0;
    }

    $app->run();
    return 0;
}
