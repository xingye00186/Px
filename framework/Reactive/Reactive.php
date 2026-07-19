<?php

namespace Px\Reactive;

/**
 * #[Reactive] — 编译时标记属性为响应式
 *
 * PHP 8.0 Attribute，零运行时开销。
 * 由 SFC 编译器的 ScriptAnalyzer 在编译时读取，
 * 自动为标记属性生成 get/set Property Hooks。
 *
 * 使用方式 (SFC <script> 中):
 * ```php
 * #[Reactive]
 * public int $count = 0;
 *
 * #[Reactive]
 * public string $name = '';
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Reactive
{
}
