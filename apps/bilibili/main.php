<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1440;
const WINDOW_HEIGHT = 900;
const WINDOW_TITLE  = '哔哩哔哩 - 热门视频';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root, __DIR__)->run();
    return 0;
}
