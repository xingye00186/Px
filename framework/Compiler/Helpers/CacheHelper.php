<?php

/**
 * CacheHelper — 依赖缓存与项目配置加载
 */

/**
 * 获取 SFC 编译器依赖的所有框架文件路径列表。
 * 当任何一个文件变更时，编译缓存失效。
 */
function getFrameworkFiles(): array
{
    $compilerDir = __DIR__;
    $frameworkDir = dirname($compilerDir, 2);
    return [
        $frameworkDir . '/Compiler/sfc-compiler.php',
        $frameworkDir . '/Dom/VNode.php',
        $frameworkDir . '/Css/CssMappings.php',
        $frameworkDir . '/Compiler/TemplateParser.php',
        $frameworkDir . '/Compiler/AotValidator.php',
        $frameworkDir . '/Compiler/ScriptAnalyzer.php',
        $frameworkDir . '/Compiler/ComponentRegistry.php',
        $frameworkDir . '/Compiler/expression/ExpressionType.php',
        $frameworkDir . '/Compiler/expression/ExpressionParser.php',
        $frameworkDir . '/Compiler/expression/TernaryExpression.php',
        $frameworkDir . '/Compiler/expression/ComparisonExpression.php',
        $frameworkDir . '/Compiler/expression/LogicalExpression.php',
        $frameworkDir . '/Compiler/expression/ConcatenationExpression.php',
        $frameworkDir . '/Compiler/Helpers/CacheHelper.php',
        $frameworkDir . '/Compiler/Helpers/CollectorHelper.php',
        $frameworkDir . '/Compiler/Helpers/MiscHelper.php',
        $frameworkDir . '/Compiler/Helpers/NameHelper.php',
        $frameworkDir . '/Compiler/Helpers/StyleExprHelper.php',
        $frameworkDir . '/Compiler/Transform/TransformInterface.php',
        $frameworkDir . '/Compiler/Transform/TransformPipeline.php',
        $frameworkDir . '/Compiler/Transform/StaticHoistTransform.php',
        $frameworkDir . '/Compiler/Transform/StyleArrayTransform.php',
        $frameworkDir . '/Compiler/Transform/PatchFlagTransform.php',
        $frameworkDir . '/Compiler/Transform/ComponentResolveTransform.php',
        $frameworkDir . '/Compiler/CompilerPipeline.php',
        $frameworkDir . '/Compiler/Codegen/DispatchGenerator.php',
        $frameworkDir . '/Compiler/Codegen/BindValueGenerator.php',
        $frameworkDir . '/Compiler/Codegen/VForHelperGenerator.php',
        $frameworkDir . '/Compiler/Codegen/ReactiveHookGenerator.php',
        $frameworkDir . '/Compiler/Codegen/ClassAssembler.php',
    ];
}

/**
 * 加载并验证 .dep-cache.json 缓存。
 * 检测框架依赖是否已变更，若变更则返回 null 触发全量重编译。
 */
function loadDepCache(string $genDir): ?array
{
    $cachePath = $genDir . DIRECTORY_SEPARATOR . '.dep-cache.json';
    if (!file_exists($cachePath)) {
        return null;
    }

    // Check if any framework file is newer than the cache file itself.
    // If so, the compiler (or its dependencies) has changed since the
    // cache was written — invalidate everything to force recompilation.
    $cacheMtime = @filemtime($cachePath) ?: 0;
    foreach (getFrameworkFiles() as $fwFile) {
        if (file_exists($fwFile) && (@filemtime($fwFile) ?: 0) > $cacheMtime) {
            echo "  [CACHE] Framework file changed: " . basename($fwFile) . ", invalidating dep-cache\n";
            return null;
        }
    }

    $content = file_get_contents($cachePath);
    if ($content === false) {
        return null;
    }
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['compiled']) || !isset($data['mtimes'])) {
        return null;
    }
    return $data;
}

/**
 * 将编译集和文件 mtime 持久化到 .dep-cache.json，用于增量编译。
 */
function saveDepCache(string $genDir, array $compiledSet): void
{
    $mtimes = [];
    foreach ($compiledSet as $filePath) {
        if (is_string($filePath) && file_exists($filePath)) {
            $mtimes[$filePath] = @filemtime($filePath) ?: 0;
        }
    }
    $cacheData = [
        'compiled' => $compiledSet,
        'mtimes' => $mtimes,
    ];
    $cachePath = $genDir . DIRECTORY_SEPARATOR . '.dep-cache.json';
    $json = json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($cachePath, $json);
}

/**
 * 从应用目录加载并解析 project.yml 配置文件。
 */
function loadProjectConfig(string $appDir): array
{
    $ymlPath = $appDir . DIRECTORY_SEPARATOR . 'project.yml';
    if (!file_exists($ymlPath)) {
        return [];
    }

    $lines = file($ymlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];

    return parseProjectYamlLines($lines);
}

/**
 * Minimal YAML parser for project.yml.
 * Only extracts top-level keys and list items.
 * Does NOT handle nested objects deeply — only enough for component-libraries config.
 */
function parseProjectYamlLines(array $lines): array
{
    $config = [];
    $currentKey = null;
    $currentList = null;
    $inComponentLibraries = false;
    $inMappings = false;
    $inForcedComponents = false;
    $libraries = [];
    $currentLib = null;

    foreach ($lines as $line) {
        // Skip comments and empty lines
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '#') continue;

        // Count leading spaces for indentation
        $indent = strlen($line) - strlen($trimmed);

        // Top-level key: value
        if ($indent === 0 && preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $trimmed, $m)) {
            $key = $m[1];
            $value = trim($m[2]);

            if ($value === '') {
                // Flush pending library before switching to a new top-level key
                if ($inComponentLibraries && $currentLib !== null) {
                    $libraries[] = $currentLib;
                    $currentLib = null;
                }
                // Flush previous list before starting a new one.
                // This prevents PHP reference aliasing (all lists sharing the same array).
                if ($currentList !== null && $currentKey !== null) {
                    $config[$currentKey] = $currentList;
                }
                $currentKey = $key;
                if ($key === 'component-libraries') {
                    $inComponentLibraries = true;
                    $inForcedComponents = false;
                    $libraries = [];
                } elseif ($key === 'forced-components') {
                    $inForcedComponents = true;
                    $inComponentLibraries = false;
                } else {
                    $inComponentLibraries = false;
                    $inForcedComponents = false;
                }
                // unset breaks the reference binding so $config[$key] retains its own array
                unset($currentList);
                $currentList = [];
                $config[$key] = &$currentList;
            } else {
                // Non-list top-level key with a value (e.g., name: vc-guide)
                // Flush any active list before storing scalar.
                if ($currentList !== null && $currentKey !== null) {
                    $config[$currentKey] = $currentList;
                    $currentList = null;
                }
                $inComponentLibraries = false;
                $inForcedComponents = false;
                $config[$key] = $value;
                $currentKey = null;
            }
            continue;
        }

        // List item: - value (at indent 2 AND starts with '- ', i.e., "  - item")
        if ($indent === 2 && $trimmed[0] === '-' && isset($trimmed[1]) && $trimmed[1] === ' ') {
            $itemValue = trim(substr($trimmed, 2));

            if ($inComponentLibraries) {
                if ($itemValue === '' || preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $itemValue)) {
                    // New library entry object
                    if ($currentLib !== null) {
                        $libraries[] = $currentLib;
                    }
                    $currentLib = [];
                    $inMappings = false;
                    if ($itemValue !== '') {
                        // Key: value on same line as dash
                        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $itemValue, $m2)) {
                            $currentLib[$m2[1]] = trim($m2[2]);
                        }
                    }
                }
            } elseif ($inForcedComponents) {
                // forced-components list items
                $currentList[] = $itemValue;
            } elseif ($currentList !== null) {
                // sources, ignore, etc.
                $currentList[] = $itemValue;
            }
            continue;
        }

        // Indented key: value (inside an object)
        if ($indent >= 4 && $currentLib !== null && $inComponentLibraries) {
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $trimmed, $m)) {
                $subKey = $m[1];
                $subValue = trim($m[2]);

                if ($subKey === 'mappings') {
                    $inMappings = true;
                    $currentLib['mappings'] = [];
                } elseif ($inMappings && $subValue !== '') {
                    // mappings key: value
                    $currentLib['mappings'][$subKey] = $subValue;
                } else {
                    $currentLib[$subKey] = $subValue;
                }
            }
            continue;
        }
    }

    // Finalize last library entry
    if ($currentLib !== null) {
        $libraries[] = $currentLib;
    }

    // Always write component-libraries when we collected any (semantically equivalent to $inComponentLibraries check)
    if (!empty($libraries)) {
        $config['component-libraries'] = $libraries;
    }

    return $config;
}
