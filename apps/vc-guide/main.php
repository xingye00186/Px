<?php

use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1000;
const WINDOW_HEIGHT = 700;
const WINDOW_TITLE  = 'Px Framework Guide';

function main(): int {
    $root = ComponentFactory::create(AppComponent::class);
    $app = Application::create();
    $app->mount($root)->run();
    return 0;
}