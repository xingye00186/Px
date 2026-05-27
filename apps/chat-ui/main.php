<?php

use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 800;
const WINDOW_HEIGHT = 600;
const WINDOW_TITLE  = '文件传输助手';

function main(): int
{
    date_default_timezone_set('Asia/Shanghai');

    $root = ComponentFactory::create(AppComponent::class);
    $app = Application::create();
    $app->mount($root)->run();

    return 0;
}