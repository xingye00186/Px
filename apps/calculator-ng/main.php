<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 340;
const WINDOW_HEIGHT = 660;
const WINDOW_TITLE  = 'Calculator';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root, __DIR__)->run();
    return 0;
}
