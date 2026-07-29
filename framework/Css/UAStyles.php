<?php

namespace Px\Css;

/**
 * UAStyles — User-Agent 样式表单源（C1.2）。
 *
 * 对应 Blink html.css / DefaultStyleSheets：所有"元素固有默认样式"的
 * 唯一声明地。此前 UA 语义散落 4 处实体散点（TemplateParser 内联标签族 /
 * ComputedStyle 控件特例 / InlineAlgorithm monospace 族 / LayoutOrchestrator
 * 控件度量），各批修复各自寻找注入点（046/048/050/表单控件四批散点税）。
 *
 * 分层边界（文档 §三 C1.2 Out-of-scope，r2 拆分）：
 * - 属"样式声明"的 UA 规则入本表（字体/颜色/对齐/overflow 等）；
 * - 属"内容生成/盒生成/控件度量"的维持原分层——q 引号深度解析在
 *   InlineAlgorithm（对应 Blink LayoutQuote）、<option> 零盒化与控件内在
 *   尺寸在 LayoutOrchestrator（对应 LayoutTheme + 布局树构建），不强行收编。
 *
 * AOT 友好：纯常量数组 + 静态查询，无动态构造。
 */
final class UAStyles
{
    /**
     * 内联格式化标签的 UA 默认样式（html.css 对应段）。
     * 消费点：TemplateParser::parseGenericElement（编译期注入 style 串）。
     * 值为 CSS 内联串形态（与模板 style 属性同构，追加合并语义）。
     */
    public const INLINE_TAG_STYLES = [
        'b'       => 'font-weight:700',
        'strong'  => 'font-weight:700',
        'em'      => 'font-style:italic',
        'i'       => 'font-style:italic',
        'u'       => 'text-decoration:underline',
        'code'    => 'font-family:Consolas,monospace',
        'small'   => 'font-size:smaller',
        'mark'    => 'background:#ffff00',
    ];

    /**
     * 编译期查询：标签的 UA 内联样式串（无则 null）。
     */
    public static function inlineTagStyle(string $tag): ?string
    {
        return self::INLINE_TAG_STYLES[$tag] ?? null;
    }

    // ── UA display 表（html.css §15.3.2 表格族 + 内联族）──

    /** 表格族 UA display 映射（原 ComputedStyle::TABLE_DISPLAY_MAP，单源迁入）。 */
    public const TABLE_DISPLAY_MAP = [
        'table'    => 'table',
        'caption'  => 'table-caption',
        'colgroup' => 'table-column-group',
        'col'      => 'table-column',
        'thead'    => 'table-header-group',
        'tbody'    => 'table-row-group',
        'tfoot'    => 'table-footer-group',
        'tr'       => 'table-row',
        'td'       => 'table-cell',
        'th'       => 'table-cell',
    ];

    /** UA inline 元素集（原 ComputedStyle::INLINE_TYPES，单源迁入）。 */
    public const INLINE_TYPES = [
        'span', '#text', 'text', 'b', 'strong', 'em', 'i', 'code', 'a', 'label', 'br',
        'abbr', 'cite', 'dfn', 'kbd', 'mark', 'q', 'samp', 'small', 'sub',
        'sup', 'time', 'var', 'u', 's',
    ];

    // ── 表单控件族（Chrome UA html.css + LayoutTheme 样式声明部分）──

    /** inline-block 盒控件族（替换/控件类元素）。 */
    public const CONTROL_INLINE_BLOCK_TYPES = ['select', 'input', 'textarea', 'button', 'progress', 'meter'];

    /** 控件 UA 样式族（bg=white / align-items:center，046 B 真值实锤）。 */
    public const FORM_CONTROL_TYPES = ['select', 'input', 'textarea', 'button'];

    /** overflow:clip 仅 input/textarea（select/button 为 visible，046 elem[59] 实锤）。 */
    public const CLIP_CONTROL_TYPES = ['input', 'textarea'];

    /** Chrome UA 表格系 border-color:inherit 名单（非真继承属性）。 */
    public const TABLE_BORDER_COLOR_INHERIT_TYPES = ['tbody', 'thead', 'tfoot', 'tr', 'td', 'th'];

    /**
     * monospace UA 字体族（html.css pre,code,kbd,samp,tt{font-family:monospace}）。
     * 消费点：InlineAlgorithm 字体盒度量分支（度量逻辑留布局层，名单归本表）。
     */
    public const MONOSPACE_TYPES = ['code', 'kbd', 'samp', 'tt', 'pre'];

    public static function isMonospaceType(string $elementType): bool
    {
        return in_array($elementType, self::MONOSPACE_TYPES, true);
    }

    /** 元素的 UA 默认 display（控件 inline-block > 表格族 > inline 集 > block）。 */
    public static function defaultDisplay(string $elementType): string
    {
        if (in_array($elementType, self::CONTROL_INLINE_BLOCK_TYPES, true)) {
            return 'inline-block';
        }
        return self::TABLE_DISPLAY_MAP[$elementType]
            ?? (in_array($elementType, self::INLINE_TYPES, true) ? 'inline' : 'block');
    }

    public static function isFormControl(string $elementType): bool
    {
        return in_array($elementType, self::FORM_CONTROL_TYPES, true);
    }

    public static function isClipControl(string $elementType): bool
    {
        return in_array($elementType, self::CLIP_CONTROL_TYPES, true);
    }

    /** caption UA 居中（html.css caption{text-align:center}，048 实锤）。 */
    public static function defaultTextAlign(string $elementType): string
    {
        return $elementType === 'caption' ? 'center' : 'start';
    }
}
