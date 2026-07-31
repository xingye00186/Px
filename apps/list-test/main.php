<?php

use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 400;
const WINDOW_HEIGHT = 500;
const WINDOW_TITLE  = 'v-for List Test App';

function main(): int
{
    date_default_timezone_set('Asia/Shanghai');

    $root = ComponentFactory::create(AppComponent::class);
    $app = Application::create();
    $app->mount($root, __DIR__)->run();

    return 0;
}