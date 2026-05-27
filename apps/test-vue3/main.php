<?php
use Px\Core\Application;
const APP_PLATFORM = 'win32';
const WINDOW_WIDTH = 400;
const WINDOW_HEIGHT = 300;
const WINDOW_TITLE = 'Vue 3 Syntax Test';
function main(): int {
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}