<?php
use Px\Core\Application;

const APP_PLATFORM = 'win32';
const WINDOW_WIDTH = 1200;
const WINDOW_HEIGHT = 800;
const WINDOW_TITLE = 'Px Framework Component Showcase';

function main(): int {
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}