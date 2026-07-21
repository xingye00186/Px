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
 * 扫描 components/ 目录，构建组件注册表。
 */
function loadComponentRegistry(string $vueFile): \ComponentRegistry
{
    $registry = new ComponentRegistry();
    $appDir = dirname(realpath($vueFile));
    $componentsDir = $appDir . DIRECTORY_SEPARATOR . 'components';

    if (!is_dir($componentsDir)) {
        return $registry;
    }

    $files = glob($componentsDir . DIRECTORY_SEPARATOR . '*.vue');
    foreach ($files as $file) {
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        $tagName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseName));
        $tagName = str_replace('_', '-', $tagName);
        $tagName = strtolower($tagName);
        if ($tagName === '') continue;
        $warn = $registry->register($tagName, $file, 'user');
        if ($warn !== null) {
            echo "  [WARN] ComponentRegistry: $warn\n";
        }
    }

    return $registry;
}
