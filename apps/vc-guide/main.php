<?php

use Px\Core\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1000;
const WINDOW_HEIGHT = 700;
const WINDOW_TITLE  = 'Px Framework Guide — VC-UI 组件库文档';

function main(): int {
    $root = ComponentFactory::create(VcGuideComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}