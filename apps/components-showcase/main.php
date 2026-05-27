<?php

use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 500;
const WINDOW_HEIGHT = 400;
const WINDOW_TITLE  = 'Px UI Components';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}