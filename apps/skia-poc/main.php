<?php
use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const APP_RENDERER  = 'skia';   // 关键开关：启用 Skia 路径（默认 GDI）
const WINDOW_WIDTH  = 400;
const WINDOW_HEIGHT = 300;
const WINDOW_TITLE  = 'Skia POC';

function main(): int
{
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}
