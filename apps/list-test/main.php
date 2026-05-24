<?php

use Px\Core\Application;

const APP_PLATFORM = 'win32';

const WINDOW_TITLE  = 'v-for List Test App';

function main(): int
{
    date_default_timezone_set('Asia/Shanghai');

    $root = ComponentFactory::create(AppComponent::class);
    $app = Application::create();
    $app->mount($root)->run();

    return 0;
}