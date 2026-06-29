<?php

namespace PxTest;

/**
 * HtmlToVueConverter — 从 .html 文件自动生成 .vue 文件。
 *
 * 单源策略：.html 是唯一事实源，.vue 由此代码自动生成。
 * 注入 data-px-id 只需处理 .html 一侧，生成的 .vue 天然继承相同的 data-px-id。
 *
 * 转换规则：
 *   1. <body> 内 HTML → <template>
 *   2. <style> 中提取 content 样式（排除 html,body 基线）→ <style>
 *   3. caseName → PascalCase 类名 → <script lang="php">
 *   4. 从 html,body 基线提取 font-family，注入到根 div 的 inline style
 */
class HtmlToVueConverter
{
    /**
     * 将 .html 内容转换为 .vue 内容。
     *
     * @param string $htmlContent 原始 .html 内容
     * @param string $caseName case 名（如 'prt-09-box-sizing'）
     * @return string 生成的 .vue 内容
     */
    public static function convert(string $htmlContent, string $caseName): string
    {
        // 1. 提取 <body> 内 HTML
        $bodyHtml = self::extractBodyContent($htmlContent);

        // 2. 提取 content 样式（过滤 html,body 基线，含 font-family）
        $vueStyle = self::extractContentStyles($htmlContent);

        // 3. 生成组件类名
        $className = self::caseNameToClassName($caseName);

        // 4. 组装 .vue
        $vue = "<template>\n";
        $vue .= $bodyHtml . "\n";
        $vue .= "</template>\n";
        $vue .= "<script lang=\"php\">\n";
        $vue .= "class {$className} extends ReactiveComponent {}\n";
        $vue .= "</script>\n";
        $vue .= "<style>\n";
        $vue .= $vueStyle . "\n";
        $vue .= "</style>\n";

        return $vue;
    }

    /**
     * 从 HTML 中提取 <body> 标签内的内容。
     */
    private static function extractBodyContent(string $html): string
    {
        if (preg_match('/<body[^>]*>([\s\S]*)<\/body>/i', $html, $m)) {
            return trim($m[1]);
        }
        // 没有 <body> 标签时，返回整个内容
        return trim($html);
    }

    /**
     * 从 .html 的 <style> 块提取 content 样式（排除 html,body 基线）。
     */
    private static function extractContentStyles(string $html): string
    {
        $styles = [];
        $fontFamily = '';

        // 提取所有 <style> 块的内容
        if (preg_match_all('/<style[^>]*>([\s\S]*?)<\/style>/i', $html, $matches)) {
            foreach ($matches[1] as $css) {
                // 按规则拆分
                $rules = preg_split('/\}/', $css);
                foreach ($rules as $rule) {
                    $rule = trim($rule);
                    if ($rule === '') continue;

                    // 包含 {} 的完整规则
                    if (preg_match('/([^{]+)\{([^}]*)/s', $rule, $rm)) {
                        $selector = trim($rm[1]);
                        $declarations = trim($rm[2]);

                        // 从 html,body 基线提取 font-family（用于 .vue 无 html 的环境）
                        if (preg_match('/^(html|body|\*)\s*(,\s*(html|body|\*)\s*)*$/i', $selector)) {
                            if (preg_match('/font-family\s*:\s*([^;}]+)/i', $declarations, $ffm)) {
                                $fontFamily = trim($ffm[1]);
                            }
                            // 检查是否包含 baseline 属性
                            if (preg_match('/\b(width|height|font-size|background)\s*:/i', $declarations)) {
                                continue; // 跳过 html,body 基线
                            }
                        }

                        $styles[] = $selector . ' {' . $declarations . '}';
                    }
                }
            }
        }

        // 将 font-family 添加到全局样式（引擎无 html/body 基线，需显式设置）
        if ($fontFamily !== '') {
            $ffClean = str_replace('"', "'", $fontFamily);
            $styles[] = '* { font-family:' . $ffClean . '; }';
        }

        return implode("\n", $styles);
    }

    /**
     * 将 case 名转换为 PHP 类名。
     * 'prt-09-box-sizing' → 'Prt09BoxSizing'
     */
    public static function caseNameToClassName(string $caseName): string
    {
        // 按 - 分割
        $parts = explode('-', $caseName);

        // 首字母大写拼接
        $className = '';
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }

        return $className;
    }
}
