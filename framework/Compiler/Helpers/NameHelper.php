<?php

/**
 * NameHelper — 纯字符串名称转换工具
 */

// Native HTML tags whitelist — 这些标签不会被编译为自定义组件
const NATIVE_HTML_TAGS = [
    'div', 'span', 'button', 'input', 'p',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'a', 'img', 'ul', 'ol', 'li',
    'table', 'tr', 'td', 'th', 'thead', 'tbody',
    'form', 'label', 'textarea', 'select', 'option',
    'br', 'hr', 'strong', 'em', 'code', 'pre',
    'header', 'footer', 'nav', 'main', 'section', 'aside',
    'template'
];

/**
 * 从模板字符串中提取非原生 HTML 标签名（用于组件注册）。
 */
function extractCustomTags(string $template): array
{
    preg_match_all('/<([a-z][a-z0-9-]*)/i', $template, $matches);
    $tags = array_map('strtolower', $matches[1]);
    return array_values(array_unique(array_diff($tags, NATIVE_HTML_TAGS, ['component'])));
}

/**
 * 将 kebab-case 标签名转换为 PascalCase 组件类名。
 * e.g. "my-button" → "MyButtonComponent"
 */
function componentTagToComponentName(string $tag): string
{
    $tag = preg_replace('/Component$/i', '', $tag);
    $parts = explode('-', $tag);
    $result = '';
    foreach ($parts as $part) {
        $result .= ucfirst($part);
    }
    return $result . 'Component';
}

/**
 * 将连字符命名转换为驼峰命名 (cover-bg → coverBg)
 */
function hyphenToCamel(string $str): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $str))));
}

/**
 * 扫描组件注册表。
 *
 * 两个扫描源：
 *   1. `$vueFile` 同级目录下的 `components/` 子目录
 *      → 编译主 App.vue 时把子组件注入 registry
 *   2. `$vueFile` 同级目录自身的其他 vue 文件
 *      → 编译子组件 X.vue 时，同目录其他组件（含 X.vue 自身）均可互相引用
 *      也包含递归组件 self-reference（例如 DeepTreeNode 内部引用自身）
 */
function loadComponentRegistry(string $vueFile): \ComponentRegistry
{
    $registry = new ComponentRegistry();
    $appDir = dirname(realpath($vueFile));

    $seen = [];

    // 内部工具：注册单个 vue 文件到 registry（去重）
    $registerOne = function (string $file) use ($registry, &$seen) {
        $real = realpath($file);
        if ($real === false || isset($seen[$real])) return;
        $seen[$real] = true;

        $baseName = pathinfo($file, PATHINFO_FILENAME);
        $tagName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseName));
        $tagName = str_replace('_', '-', $tagName);
        $tagName = strtolower($tagName);
        if ($tagName === '') return;
        $warn = $registry->register($tagName, $file, 'user');
        if ($warn !== null) {
            echo "  [WARN] ComponentRegistry: $warn\n";
        }
    };

    // 1. 同级 components/ 子目录（主 App.vue 使用场景）
    $componentsDir = $appDir . DIRECTORY_SEPARATOR . 'components';
    if (is_dir($componentsDir)) {
        foreach (glob($componentsDir . DIRECTORY_SEPARATOR . '*.vue') as $file) {
            $registerOne($file);
        }
    }

    // 2. 同级目录自身（子组件使用场景）
    //    编译 apps/xxx/components/X.vue 时，$appDir = apps/xxx/components，
    //    同目录其他 vue 文件（含 X.vue 自身，支持递归组件）均可互引
    //    主 App.vue 场景下 $appDir = apps/xxx，同目录一般只有 App.vue 自己 —
    //    注册为 `app` 不会影响现有模板（模板里不写 <app> 标签）
    foreach (glob($appDir . DIRECTORY_SEPARATOR . '*.vue') as $file) {
        $registerOne($file);
    }

    return $registry;
}
