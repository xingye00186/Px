<?php

use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 840;
const WINDOW_HEIGHT = 660;
const WINDOW_TITLE  = 'Multi-Scroll Test App';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}
