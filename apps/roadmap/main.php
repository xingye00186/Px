<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1100;
const WINDOW_HEIGHT = 900;
const WINDOW_TITLE  = 'Architecture Roadmap';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}
