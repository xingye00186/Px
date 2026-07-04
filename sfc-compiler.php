<?php

/**
 * SFC Compiler — entry point
 *
 * 由 build.bat / main_build.bat 调用。
 * 实际编译逻辑在 framework/compiler/sfc-compiler.php 中。
 */

define('SFC_CLI_ENTRY', true);

require_once __DIR__ . '/tests/bootstrap/autoload.php';
require_once __DIR__ . '/framework/Compiler/sfc-compiler.php';