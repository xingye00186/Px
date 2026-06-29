<?php

namespace PxTest;

/**
 * HtmlDataPxIdInjector — 为 HTML 中的每个非自闭合元素自动注入自增 data-px-id。
 *
 * 用于在测试流水线中预处理 .vue 模板和 .html 文件，使引擎和浏览器
 * 导出的元素列表中同一元素具有相同的标识符，从而实现元素匹配而非索引匹配。
 *
 * 使用方法：
 *   $injected = HtmlDataPxIdInjector::inject($html);
 *
 * 原理：
 *   对 <tag ...> 形式的开始标签注入 data-px-id="px-N"，N 从 1 开始递增。
 *   忽略：DOCTYPE, 注释, 自闭合标签（br, hr, img, input, meta, link 等）。
 *   对已存在 data-px-id 的元素跳过（允许手动标注的 ID 优先）。
 */
class HtmlDataPxIdInjector
{
    /** 自闭合元素列表（HTML5 void elements） */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** 需要跳过的不生成盒子的元素 */
    private const SKIP_ELEMENTS = [
        'script', 'style', 'textarea',
    ];

    /**
     * 注入自增 data-px-id 到 HTML 字符串中。
     *
     * @param string $html 原始 HTML
     * @return string 注入后的 HTML
     */
    public static function inject(string $html): string
    {
        $counter = 0;

        // 匹配 HTML 标签开始：<tagname ...attrs...>
        // 不匹配 DOCTYPE、注释、结束标签
        return preg_replace_callback(
            '/<(\w+)((?:\s+[^>]*)?)\s*(\/?)>/i',
            function (array $m) use (&$counter): string {
                $tagName = strtolower($m[1]);
                $attrs = $m[2] ?? '';
                $selfClosing = $m[3] ?? '';

                // 跳过 DOCTYPE、注释、自闭合元素、script/style/textarea
                if (in_array($tagName, self::VOID_ELEMENTS, true)) {
                    return $m[0]; // 保持原样
                }
                if (in_array($tagName, self::SKIP_ELEMENTS, true)) {
                    return $m[0];
                }

                // 跳过已存在 data-px-id 的元素（保留手动标注）
                if (preg_match('/\bdata-px-id\s*=/i', $attrs)) {
                    return $m[0];
                }

                $counter++;
                $id = 'px-' . $counter;

                // 在 > 前注入 data-px-id
                if ($selfClosing === '/') {
                    // 自闭合写法 <tag />（不常见但合法）
                    return '<' . $tagName . $attrs . ' data-px-id="' . $id . '" />';
                }

                return '<' . $tagName . $attrs . ' data-px-id="' . $id . '">';
            },
            $html
        );
    }

    /**
     * 从 .vue 文件提取 <template> 部分并注入 data-px-id。
     *
     * @param string $vueContent .vue 文件完整内容
     * @return string 注入后的 .vue 内容
     */
    public static function injectVueTemplate(string $vueContent): string
    {
        // 只对 <template>...</template> 内的内容注入
        return preg_replace_callback(
            '/(<template[\s>])([\s\S]*?)(<\/template>)/i',
            function (array $m): string {
                return $m[1] . self::inject($m[2]) . $m[3];
            },
            $vueContent
        );
    }
}
