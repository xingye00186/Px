<?php

use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1080;
const WINDOW_HEIGHT = 720;
const WINDOW_TITLE  = 'Px Design Guide';

function main(): int
{
    $root = \ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}
