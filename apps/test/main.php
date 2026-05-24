<?php

use native_types;
use Px\Core\Application;

/**
 * VueCalc v6 M4 — Test Application Entry Point
 *
 * 功能测试:
 *   - Flex 布局引擎
 *   - TextBox 组件 + 键盘事件
 *   - v-model 双向绑定
 *
 * AOT 编译由此文件开始。project.yml sources 引用此文件。
 */



const APP_PLATFORM = 'win32';

const WINDOW_TITLE  = 'Flex/TextBox Test Appp';

function main(): int
{
    date_default_timezone_set('Asia/Shanghai');

    $root = ComponentFactory::create(AppComponent::class);
    $app = Application::create();
    $app->mount($root)->run();

    return 0;
}