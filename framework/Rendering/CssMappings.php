<?php

namespace Px\Rendering;

use native_types;

/**
 * CSS �?GDI Mapping Table
 *
 * Defines which CSS properties are supported, how they map to GDI rendering
 * parameters, and what keys they produce in the layout output.
 *
 * Usage:
 *   $mapped = CssValueParser::parseStyleBlock($styleCss);
 *   // �?['app-bg' => ['bg'=>1973790], 'display-text' => ['fg'=>16777215, 'fontSize'=>32, 'bold'=>1], ...]
 *
 * Extend $PROPERTY_MAP to add new CSS property support.
 */
class CssMappings
{
    /**
     * CSS property �?[outputKey, parserFunction, default]
     *
     * Each entry maps a CSS property name to:
     *   - outputKey: the key in the generated layout array
     *   - parser: a callable that converts the CSS value string to the output type
     *   - default: fallback value if property is not specified
     *
     * Adding a new CSS property is as simple as adding one entry here.
     */
    const PROPERTY_MAP = [
        'background' => [
            'key'     => 'bg',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseHexColor',
            'default' => 0,
        ],
        'background-size' => [
            'key'     => 'backgroundSize',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => '',
        ],
        'background-position' => [
            'key'     => 'backgroundPosition',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => '',
        ],
        'background-repeat' => [
            'key'     => 'backgroundRepeat',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'repeat',
        ],
        'background-attachment' => [
            'key'     => 'backgroundAttachment',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'scroll',
        ],
        'background-clip' => [
            'key'     => 'backgroundClip',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'border-box',
        ],
        'background-origin' => [
            'key'     => 'backgroundOrigin',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'padding-box',
        ],
        'color' => [
            'key'     => 'fg',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseHexColor',
            'default' => 0x000000,
        ],
        'font-size' => [
            'key'     => 'fontSize',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 16,
        ],
        'font-weight' => [
            'key'     => 'bold',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseFontWeight',
            'default' => 0,
        ],
        // ---- Layout properties (width/height for CSS class styles) ----
        'width' => [
            'key'     => 'width',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'height' => [
            'key'     => 'height',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'box-sizing' => [
            'key'     => 'boxSizing',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'content-box',
        ],
        // ---- Extensions for future GDI/Direct2D support ----
        'border-radius' => [
            'key'     => 'borderRadius',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'padding-top' => [
            'key'     => 'paddingTop',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'padding-right' => [
            'key'     => 'paddingRight',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'padding-bottom' => [
            'key'     => 'paddingBottom',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'padding-left' => [
            'key'     => 'paddingLeft',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'margin-top' => [
            'key'     => 'marginTop',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'margin-right' => [
            'key'     => 'marginRight',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'margin-bottom' => [
            'key'     => 'marginBottom',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'margin-left' => [
            'key'     => 'marginLeft',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'padding' => [
            'key'     => 'padding',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'margin' => [
            'key'     => 'margin',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'text-align' => [
            'key'     => 'textAlign',
            'parser'  => 'Px\Rendering\CssValueParser::parseTextAlign',
            'default' => 'left',
        ],
        'text-indent' => [
            'key'     => 'textIndent',
            'parser'  => 'Px\Rendering\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'text-transform' => [
            'key'     => 'textTransform',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'none',
        ],
        'vertical-align' => [
            'key'     => 'verticalAlign',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'baseline',
        ],
        'line-height' => [
            'key'     => 'lineHeight',
            'parser'  => 'Px\Rendering\CssValueParser::parseLineHeight',
            'default' => '',
        ],
        'font-family' => [
            'key'     => 'fontFamily',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => '',
        ],
        // ---- v8 UI extensions ----
        'border' => [
            'key'     => 'border',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseBorder',
            'default' => '',
        ],
        'border-bottom' => [
            'key'     => 'borderBottom',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseBorder',
            'default' => '',
        ],
        'border-top' => [
            'key'     => 'borderTop',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseBorder',
            'default' => '',
        ],
        'border-left' => [
            'key'     => 'borderLeft',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseBorder',
            'default' => '',
        ],
        'border-right' => [
            'key'     => 'borderRight',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseBorder',
            'default' => '',
        ],
        'border-width' => [
            'key'     => 'borderWidth',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'border-top-width' => [
            'key'     => 'borderTopWidth',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'border-right-width' => [
            'key'     => 'borderRightWidth',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'border-bottom-width' => [
            'key'     => 'borderBottomWidth',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'border-left-width' => [
            'key'     => 'borderLeftWidth',
            'parser'  => 'Px\\Rendering\\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'border-color' => [
            'key'     => 'borderColor',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseHexColor',
            'default' => 0,
        ],
        // ---- border-style (CSS 2.2 §8.5.3) ----
        'border-style' => [
            'key'     => 'borderStyle',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'solid',
        ],
        'border-top-style' => [
            'key'     => 'borderTopStyle',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'solid',
        ],
        'border-right-style' => [
            'key'     => 'borderRightStyle',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'solid',
        ],
        'border-bottom-style' => [
            'key'     => 'borderBottomStyle',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'solid',
        ],
        'border-left-style' => [
            'key'     => 'borderLeftStyle',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'solid',
        ],
        // ---- per-side border colors (CSS 2.2 §8.5.2) ----
        'border-top-color' => [
            'key'     => 'borderTopColor',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseHexColor',
            'default' => 0,
        ],
        'border-right-color' => [
            'key'     => 'borderRightColor',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseHexColor',
            'default' => 0,
        ],
        'border-bottom-color' => [
            'key'     => 'borderBottomColor',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseHexColor',
            'default' => 0,
        ],
        'border-left-color' => [
            'key'     => 'borderLeftColor',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseHexColor',
            'default' => 0,
        ],
        'box-shadow' => [
            'key'     => 'boxShadow',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseBoxShadow',
            'default' => '',
        ],
        'cursor' => [
            'key'     => 'cursor',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'default',
        ],
        'opacity' => [
            'key'     => 'opacity',
            'parser'  => 'Px\Rendering\CssValueParser::parseOpacity',
            'default' => 1.0,
        ],
        'scroll-behavior' => [
            'key'     => 'scrollBehavior',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'auto',
        ],
        // ---- Layout/positioning properties ----
        'left'             => ['key' => 'left',             'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 'auto'],
        'top'              => ['key' => 'top',              'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 'auto'],
        'right'            => ['key' => 'right',            'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 'auto'],
        'bottom'           => ['key' => 'bottom',           'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 'auto'],
        'display'          => ['key' => 'display',          'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'block'],
        'position'         => ['key' => 'position',         'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'static'],
        'z-index'          => ['key' => 'zIndex',           'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 0],
        'overflow'         => ['key' => 'overflow',         'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'visible'],
        'overflow-x'       => ['key' => 'overflowX',        'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'visible'],
        'overflow-y'       => ['key' => 'overflowY',        'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'visible'],
        'text-overflow'    => ['key' => 'textOverflow',      'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'clip'],
        'white-space'      => ['key' => 'whiteSpace',       'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'word-break'       => ['key' => 'wordBreak',        'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'overflow-wrap'    => ['key' => 'overflowWrap',      'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'word-wrap'        => ['key' => 'overflowWrap',      'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'font-style'       => ['key' => 'fontStyle',        'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'font-variant'     => ['key' => 'fontVariant',      'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'font-stretch'     => ['key' => 'fontStretch',      'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'appearance'       => ['key' => 'appearance',       'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'auto'],
        'flex-direction'   => ['key' => 'flexDirection',    'parser' => 'PxRenderingCssValueParser::parseIdent',  'default' => 'row'],
        // ---- CSS Lists (CSS Lists L3 §3-4) ----
        'list-style-type'  => ['key' => 'listStyleType',    'parser' => 'PxRenderingCssValueParser::parseIdent', 'default' => 'disc'],
        'list-style-position' => ['key' => 'listStylePosition','parser' => 'PxRenderingCssValueParser::parseIdent','default' => 'outside'],
        'flex-wrap'        => ['key' => 'flexWrap',         'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'nowrap'],
        'justify-content'  => ['key' => 'justifyContent',   'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'flex-start'],
        'align-items'      => ['key' => 'alignItems',       'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'stretch'],
        'align-content'    => ['key' => 'alignContent',     'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'stretch'],
        'gap'              => ['key' => 'gap',              'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 0],
        // ---- CSS Tables (CSS 2.2 §17) ----
        'border-collapse'  => ['key' => 'borderCollapse',   'parser' => 'Px\Rendering\CssValueParser::parseIdent', 'default' => 'separate'],
        'border-spacing'   => ['key' => 'borderSpacing',    'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 0],
        'table-layout'     => ['key' => 'tableLayout',      'parser' => 'Px\Rendering\CssValueParser::parseIdent', 'default' => 'auto'],
        'caption-side'     => ['key' => 'captionSide',       'parser' => 'Px\Rendering\CssValueParser::parseIdent', 'default' => 'top'],
        'flex'             => ['key' => 'flex',              'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => ''],
        'flex-grow'        => ['key' => 'flexGrow',    'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 0],
        'flex-basis'       => ['key' => 'flexBasis',    'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'auto'],
        'flex-shrink'      => ['key' => 'flexShrink',   'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 1],
        'order'            => ['key' => 'order',        'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 0],
        'align-self'       => ['key' => 'alignSelf',   'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => 'auto'],
        'justify-self'     => ['key' => 'justifySelf', 'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'auto'],
        'justify-items'    => ['key' => 'justifyItems', 'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'min-width'        => ['key' => 'minWidth',  'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 0],
        'max-width'        => ['key' => 'maxWidth',  'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 0],
        'min-height'       => ['key' => 'minHeight', 'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 0],
        'max-height'       => ['key' => 'maxHeight', 'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 0],
        'grid-template-columns' => ['key' => 'gridTemplateColumns', 'parser' => 'Px\\Rendering\\CssValueParser::parseIdent', 'default' => ''],
        'grid-template-rows'    => ['key' => 'gridTemplateRows',    'parser' => 'Px\\Rendering\\CssValueParser::parseIdent', 'default' => ''],
        'grid-column-gap'      => ['key' => 'gridColumnGap', 'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 0],
        'grid-row-gap'         => ['key' => 'gridRowGap',    'parser' => 'Px\\Rendering\\CssValueParser::parsePixels', 'default' => 0],
        'grid-row'             => ['key' => 'gridRow',       'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => ''],
        'grid-column'          => ['key' => 'gridColumn',    'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',  'default' => ''],
        'grid-area'            => ['key' => 'gridArea',       'parser' => 'Px\\Rendering\\CssValueParser::parseIdent',   'default' => ''],
        'grid-auto-rows'       => ['key' => 'gridAutoRows',    'parser' => 'Px\\Rendering\\CssValueParser::parsePixels',  'default' => 0],
        'grid-template-areas'  => ['key' => 'gridTemplateAreas','parser' => 'Px\\Rendering\\CssValueParser::parseIdent',   'default' => ''],
        'object-fit'           => ['key' => 'objectFit',     'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'fill'],
        'background-image'     => ['key' => 'backgroundImage', 'parser' => 'Px\Rendering\CssValueParser::parseBackgroundImage', 'default' => ''],
        'transform'            => ['key' => 'transform',       'parser' => 'Px\\Rendering\\CssValueParser::parseTransform', 'default' => ''],
        'pointer-events'       => ['key' => 'pointerEvents',   'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => ''],
        'direction'            => ['key' => 'direction',        'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'ltr'],
        'unicode-bidi'         => ['key' => 'unicodeBidi',     'parser' => 'Px\Rendering\CssValueParser::parseIdent',  'default' => 'normal'],
        'text-shadow'          => ['key' => 'textShadow',       'parser' => 'Px\Rendering\CssValueParser::parseIdent', 'default' => ''],
        'letter-spacing'       => ['key' => 'letterSpacing',    'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 0],
        'word-spacing'         => ['key' => 'wordSpacing',      'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 0],
            
        // ---- Text Decoration (CSS Text Decoration Module Level 3) ----
        'text-decoration-line'      => ['key' => 'textDecorationLine',     'parser' => 'Px\Rendering\CssValueParser::parseIdent',     'default' => 'none'],
        'text-decoration-color'     => ['key' => 'textDecorationColor',    'parser' => 'Px\Rendering\CssValueParser::parseHexColor',   'default' => 0xFFFFFF],
        'text-decoration-style'     => ['key' => 'textDecorationStyle',    'parser' => 'Px\Rendering\CssValueParser::parseIdent',     'default' => 'solid'],
        'text-decoration-thickness' => ['key' => 'textDecorationThickness','parser' => 'Px\Rendering\CssValueParser::parsePixels',    'default' => 0],
        // ---- Text Emphasis (CSS Text Decoration Module Level 3 §8) ----
        'text-emphasis-style'    => ['key' => 'textEmphasisStyle',  'parser' => 'Px\Rendering\CssValueParser::parseIdent',   'default' => 'none'],
        'text-emphasis-color'    => ['key' => 'textEmphasisColor',  'parser' => 'Px\Rendering\CssValueParser::parseHexColor', 'default' => 0xFF0000],
        'text-emphasis-position' => ['key' => 'textEmphasisPosition','parser' => 'Px\Rendering\CssValueParser::parseIdent',   'default' => 'over'],
        'text-underline-offset'     => ['key' => 'textUnderlineOffset',    'parser' => 'Px\Rendering\CssValueParser::parsePixels',    'default' => 0],
    
        // CSS Inline Layout: vertical-align (CSS 2.2 §10.8.1)
        'vertical-align' => [
            'key'     => 'verticalAlign',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'baseline',
        ],
    
        // CSS Basic User Interface Module Level 3: outline (不占布局空间)
        'outline-width' => [
            'key'     => 'outlineWidth',
            'parser'  => 'Px\Rendering\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'outline-style' => [
            'key'     => 'outlineStyle',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'none',
        ],
        'outline-color' => [
            'key'     => 'outlineColor',
            'parser'  => 'Px\Rendering\CssValueParser::parseHexColor',
            'default' => 0,
        ],
        'outline-offset' => [
            'key'     => 'outlineOffset',
            'parser'  => 'Px\Rendering\CssValueParser::parsePixels',
            'default' => 0,
        ],

        // CSS Multi-column Layout Module Level 1
        'column-count' => [
            'key'     => 'columnCount',
            'parser'  => 'Px\Rendering\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'column-width' => [
            'key'     => 'columnWidth',
            'parser'  => 'Px\Rendering\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'column-gap' => [
            'key'     => 'columnGap',
            'parser'  => 'Px\Rendering\CssValueParser::parsePixels',
            'default' => 16,
        ],
        'column-rule-width' => [
            'key'     => 'columnRuleWidth',
            'parser'  => 'Px\Rendering\CssValueParser::parsePixels',
            'default' => 0,
        ],
        'column-rule-style' => [
            'key'     => 'columnRuleStyle',
            'parser'  => 'Px\\Rendering\\CssValueParser::parseIdent',
            'default' => 'none',
        ],
        'column-rule-color' => [
            'key'     => 'columnRuleColor',
            'parser'  => 'Px\Rendering\CssValueParser::parseHexColor',
            'default' => 0,
        ],
    ];
        
    /**
     * Inline style �?布局属性映射（�?PROPERTY_MAP 未覆盖的属性）
     *
     * 布局/定位/弹�?网格属性在 PROPERTY_MAP 中已定义，此处只加补充项�?
     * parseInlineStyle() 通过 array_merge(PROPERTY_MAP, INLINE_PROPERTY_MAP) 合并使用�?
     */
    const INLINE_PROPERTY_MAP = [
        // ---- 滚动条样�?(PROPERTY_MAP 中未包含, 其余布局属性从 PROPERTY_MAP 合并) ----
        'scrollbar-width'        => ['key' => 'scrollbarWidth',      'parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 12],
        'scrollbar-track-color'  => ['key' => 'scrollbarTrackColor', 'parser' => 'Px\Rendering\CssValueParser::parseHexColor', 'default' => 0x4A4A4A],
        'scrollbar-thumb-color'  => ['key' => 'scrollbarThumbColor', 'parser' => 'Px\Rendering\CssValueParser::parseHexColor', 'default' => 0x888888],
        'scrollbar-border-radius'=> ['key' => 'scrollbarBorderRadius','parser' => 'Px\Rendering\CssValueParser::parsePixels', 'default' => 0],
    ];

    // ============================================================
    // Color helpers
    // ============================================================

    /**
     * Convert CSS hex color #RRGGBB to GDI BGR integer (COLORREF).
     * Supports shorthand #RGB (expanded to #RRGGBB).
     */
    public static function hexToBgr(string $hex): int
    {
        return CssValueParser::hexToBgr($hex);
    }

    /**
     * Derive border color from background (lighten each channel by a delta).
     */
    public static function borderColor(int $bg, int $delta = 20): int
    {
        return CssValueParser::borderColor($bg, $delta);
    }

    // ============================================================
    // Property parsers (each returns a typed value from CSS string)
    // ============================================================

    /**
     * Parse CSS color value �?BGR integer
     *
     * Supports:
     *   - "#RRGGBB" / "#RGB" (hex colors)
     *   - rgb(r, g, b) / rgba(r, g, b, a)
     *   - linear-gradient(...) �?extract first color stop
     */
    public static function parseHexColor(string $value): int
    {
        return CssValueParser::parseHexColor($value);
    }

    /**
     * Parse "16px" �?16 (int)
     */
    public static function parsePixels(string $value): int
    {
        return CssValueParser::parsePixels($value);
    }

    /**
     * Parse "1" / "1.5" / "0" �?flex grow value as string (e.g., "1", "2")
     */
    public static function parseFlex(string $value): string
    {
        return CssValueParser::parseFlex($value);
    }

    /**
     * Parse flex shorthand value into structured array per CSS spec.
     *
     * CSS flex shorthand (https://www.w3.org/TR/css-flexbox-1/#flex-shorthand):
     *   auto    �?flex: 1 1 auto
     *   initial �?flex: 0 1 auto
     *   none    �?flex: 0 0 auto
     *   <num>        �?flex-grow: <num>, flex-shrink: 1, flex-basis: 0
     *   <num> <num>  �?flex-grow + flex-shrink, flex-basis: 0
     *   <num> <num> <basis>  �?all three
     *
     * Examples:
     *   "1"         �?['grow'=>1.0, 'shrink'=>1.0, 'basis'=>0]
     *   "auto"      �?['grow'=>1.0, 'shrink'=>1.0, 'basis'=>'auto']
     *   "none"      �?['grow'=>0.0, 'shrink'=>0.0, 'basis'=>'auto']
     *   "initial"   �?['grow'=>0.0, 'shrink'=>1.0, 'basis'=>'auto']
     *   "1 0 auto"  �?['grow'=>1.0, 'shrink'=>0.0, 'basis'=>'auto']
     *   "2 0 100px" �?['grow'=>2.0, 'shrink'=>0.0, 'basis'=>100]
     *   ""          �?['grow'=>0.0, 'shrink'=>1.0, 'basis'=>0]
     */
    public static function parseFlexValue(string $flex): array
    {
        return CssValueParser::parseFlexValue($flex);
    }

    /**
     * Parse "bold" / "700" �?1, "normal" / "400" �?0
     */
    public static function parseFontWeight(string $value): int
    {
        return CssValueParser::parseFontWeight($value);
    }

    /**
     * Parse "left" / "right" / "center" �?align string
     */
    public static function parseTextAlign(string $value): string
    {
        return CssValueParser::parseTextAlign($value);
    }

    /**
     * Parse line-height value.
     * Supports: unitless number (1.7), px (28px), em (1.6em), % (150%).
     * For unitless numbers, returns as 'N.N' string for runtime line spacing calculation.
     * For px values, returns pixel count.
     * For em/%, returns multiplier string (e.g., '1.6em' �?'1.6').
     */
    public static function parseLineHeight(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if ($value === 'normal') return '';
        // unitless number (most common in base.html: line-height: 1.7)
        if (preg_match('/^(\d+(\.\d+)?)$/', $value, $m)) {
            return $m[1];
        }
        // px value
        if (str_ends_with($value, 'px')) {
            return (string)(int)$value;
        }
        // em value: return multiplier (CSS: line-height:1.6em = 1.6 × font-size)
        if (str_ends_with($value, 'em')) {
            $num = (float)$value;
            return (string)$num;
        }
        // percentage: return multiplier (CSS: line-height:150% = 1.5 × font-size)
        if (str_ends_with($value, '%')) {
            $num = (float)$value / 100.0;
            return (string)$num;
        }
        // rem, viewport units: store as "value|unit" for runtime resolution
        if (preg_match('/^(\d+(\.\d+)?)\s*(rem|vw|vh|vmin|vmax|ch|ex)$/i', $value, $m)) {
            return $m[1] . '|' . strtolower($m[3]);
        }
        // Physical units: convert to px immediately
        // CSS Values §5: 1pt=1/72in, 1pc=12pt, 1cm=96/2.54px, 1mm=96/25.4px, 1in=96px
        $unitMap = [
            'pt' => 96.0 / 72.0,   // 1.333px
            'pc' => 96.0 / 6.0,     // 16px
            'in' => 96.0,           // 96px
            'cm' => 96.0 / 2.54,    // ~37.8px
            'mm' => 96.0 / 25.4,    // ~3.78px
        ];
        foreach ($unitMap as $unit => $pxPerUnit) {
            if (preg_match('/^(\d+(\.\d+)?)\s*' . $unit . '$/i', $value, $m)) {
                return (string)(int)round((float)$m[1] * $pxPerUnit);
            }
        }
        return $value;
    }

    /**
     * Parse "1px solid #d9d9d9" �?border string (v8)
     */
    public static function parseBorder(string $value): string
    {
        return CssValueParser::parseBorder($value);
    }

    /**
     * Parse "h-offset v-offset [blur] [spread] [color]" into structured string.
     * Returns "h|v|blur|spread|color" for downstream use.
     */
    public static function parseBoxShadow(string $value): string
    {
        return CssValueParser::parseBoxShadow($value);
    }

    /**
     * Parse "h|v|blur|spread|color" box-shadow string to offset array.
     * Extracted to eliminate 3x duplicate in VNodeRenderer.
     *
     * @return array{h:int, v:int, color:int}
     */
    public static function parseBoxShadowOffsets(string $boxShadow): array
    {
        $parts = explode('|', $boxShadow);
        $offset = 0;
        $isInset = false;
        if (isset($parts[0]) && $parts[0] === 'inset') {
            $isInset = true;
            $offset = 1;
        }
        return [
            'h'      => (int)($parts[0 + $offset] ?? 0),
            'v'      => (int)($parts[1 + $offset] ?? 0),
            'blur'   => (int)($parts[2 + $offset] ?? 0),
            'spread' => (int)($parts[3 + $offset] ?? 0),
            'color'  => self::hexToBgr($parts[4 + $offset] ?? '#000000'),
            'alpha'  => (float)($parts[5 + $offset] ?? 0.5),
            'inset'  => $isInset,
        ];
    }

    /**
     * Parse "0.5" or "50%" �?float 0.0-1.0 (v8)
     */
    public static function parseOpacity(string $value): float
    {
        return CssValueParser::parseOpacity($value);
    }

    /**
     * Parse identity: return the trimmed value as-is
     * Used for display, flex-direction, overflow, position 等关键字属�?
     */
    public static function parseIdent(string $value): string
    {
        return CssValueParser::parseIdent($value);
    }

    /**
     * Parse `url("path/to/image.png")` �?extract the image path
     * Matches CSS background-image property: background-image: url("...")
     * Supports both single/double quotes and unquoted URLs.
     */
    public static function parseBackgroundImage(string $value): string
    {
        return CssValueParser::parseBackgroundImage($value);
    }

    /** @return array 供外部（�?StyleResolver）使用的 PROPERTY_MAP */
    public static function getPropertyMap(): array
    {
        return self::PROPERTY_MAP;
    }

    /** @return array 供外部使用的 INLINE_PROPERTY_MAP */
    public static function getInlinePropertyMap(): array
    {
        return self::INLINE_PROPERTY_MAP;
    }

    /**
     * AOT-compatible parser dispatcher.
     * Replaces call_user_func() which is not supported by AOT.
     */
    private static function dispatchParser(string $parser, string $value): mixed
    {
        // �?"Px\\Rendering\\CssValueParser::parseHexColor" 提取方法�?parseHexColor
        $method = substr($parser, (int)strrpos($parser, '::') + 2);
        return match($method) {
            'parseHexColor'        => CssValueParser::parseHexColor($value),
            'parsePixels'          => CssValueParser::parsePixelsRaw($value),
            'parseFlex'            => CssValueParser::parseFlex($value),
            'parseFontWeight'      => CssValueParser::parseFontWeight($value),
            'parseTextAlign'       => CssValueParser::parseTextAlign($value),
            'parseBorder'          => CssValueParser::parseBorder($value),
            'parseOpacity'         => CssValueParser::parseOpacity($value),
            'parseIdent'           => CssValueParser::parseIdentRaw($value),
            'parseBackgroundImage' => CssValueParser::parseBackgroundImage($value),
            'parseTransform'       => CssValueParser::parseTransform($value),
            'parseBoxShadow'       => CssValueParser::parseBoxShadow($value),
            default                => $value,
        };
    }

    // ============================================================
    // Inline Style Parsing (HTML style="..." attribute)
    // ============================================================

    /**
     * Expand CSS shorthand padding/margin into individual direction properties.
     *
     * Input "padding: 10px" �?padding-top, padding-right, padding-bottom, padding-left = 10
     * Input "margin: 10px 20px" �?margin-top=margin-bottom=10, margin-left=margin-right=20
     * Input "padding: 1px 2px 3px" �?top=1, left/right=2, bottom=3
     * Input "margin: 1px 2px 3px 4px" �?top=1, right=2, bottom=3, left=4
     */
    private static function expandBoxShorthand(array $raw): array
    {
        foreach (['padding', 'margin'] as $prop) {
            if (!isset($raw[$prop])) continue;

            $parts = preg_split('/\s+/', trim($raw[$prop]));
            $nums = [];
            foreach ($parts as $p) {
                $nums[] = (int) preg_replace('/[^-0-9]/', '', $p);
            }
            $count = count($nums);
            if ($count === 0) continue;

            $top    = $nums[0];
            $right  = $nums[1] ?? $top;
            $bottom = $nums[2] ?? $top;
            $left   = $nums[3] ?? $right;

            $raw[$prop . '-top']    = $top . 'px';
            $raw[$prop . '-right']  = $right . 'px';
            $raw[$prop . '-bottom'] = $bottom . 'px';
            $raw[$prop . '-left']   = $left . 'px';

            // Keep original shorthand for backward compat
            $raw[$prop] = $top . 'px';
        }

        // ---- border-width: 1-4 value expansion (CSS 2.2 §8.5.1) ----
        // Same pattern as padding/margin: strips non-numeric, appends px
        if (isset($raw['border-width'])) {
            $parts = preg_split('/\s+/', trim($raw['border-width']));
            $nums = [];
            foreach ($parts as $p) {
                $nums[] = (int) preg_replace('/[^-0-9]/', '', $p);
            }
            $count = count($nums);
            if ($count >= 2) {
                $top    = $nums[0];
                $right  = $nums[1] ?? $top;
                $bottom = $nums[2] ?? $top;
                $left   = $nums[3] ?? $right;
                $raw['border-top-width']    = $top . 'px';
                $raw['border-right-width']  = $right . 'px';
                $raw['border-bottom-width'] = $bottom . 'px';
                $raw['border-left-width']   = $left . 'px';
                $raw['border-width'] = $top . 'px';
            }
        }

        // ---- border-color: 1-4 value expansion (CSS 2.2 §8.5.2) ----
        // Colors are strings (not pixels), only expand when 2-4 values
        if (isset($raw['border-color'])) {
            $parts = preg_split('/\s+/', trim($raw['border-color']));
            $count = count($parts);
            if ($count >= 2) {
                $top    = $parts[0];
                $right  = $parts[1] ?? $top;
                $bottom = $parts[2] ?? $top;
                $left   = $parts[3] ?? $right;
                $raw['border-top-color']    = $top;
                $raw['border-right-color']  = $right;
                $raw['border-bottom-color'] = $bottom;
                $raw['border-left-color']   = $left;
                $raw['border-color'] = $top;
            }
        }

        // ---- border-style: 1-4 value expansion (CSS 2.2 §8.5.3) ----
        if (isset($raw['border-style'])) {
            $parts = preg_split('/\s+/', trim($raw['border-style']));
            $count = count($parts);
            if ($count >= 2) {
                $top    = $parts[0];
                $right  = $parts[1] ?? $top;
                $bottom = $parts[2] ?? $top;
                $left   = $parts[3] ?? $right;
                $raw['border-top-style']    = $top;
                $raw['border-right-style']  = $right;
                $raw['border-bottom-style'] = $bottom;
                $raw['border-left-style']   = $left;
                $raw['border-style'] = $top;
            }
        }

        return $raw;
    }

    /**
     * Parse CSS background shorthand into individual sub-properties.
     *
     * CSS background shorthand syntax:
     *   [bg-color] [bg-image] [bg-position]/[bg-size] [bg-repeat] [bg-attachment] [bg-origin] [bg-clip]
     *
     * This method extracts sub-properties by detecting distinctive patterns:
     *   1. Color first (hex, rgb/rgba, linear-gradient)
     *   2. Image (url(...))
     *   3. Position/Size (".../size" pattern)
     *   4. Repeat keywords (no-repeat, repeat-x, etc.)
     *   5. Attachment keywords (scroll, fixed, local)
     *   6. Remainder �?position (if /size was extracted) or empty
     *
     * Only expands multi-value shorthands containing url() or /
     * (position/size delimiter). Single color values pass through unchanged.
     *
     * Examples:
     *   "#FB7299"                                    �?unchanged (single color)
     *   "url('bg.png')"                              �?image only
     *   "url('bg.png') center/cover no-repeat"       �?image + position + size
     *   "#FB7299 url('bg.png') center/cover no-repeat" �?full shorthand
     *   "rgba(251,114,153,0.4) url('bg.png')"         �?rgba color + image
     *
     * @param array $raw Raw style declarations
     * @return array Updated raw declarations with expanded sub-properties
     */
    private static function expandBackgroundShorthand(array $raw): array
    {
        if (!isset($raw['background']) || $raw['background'] === '') {
            return $raw;
        }

        $value = trim($raw['background']);

        // Single color value (hex, rgb/rgba, gradient, transparent, none) �?no expansion
        if (preg_match('/^#[\da-fA-F]{3,8}$/', $value) ||
            preg_match('/^rgba?\s*\([^)]*\)$/i', trim($value)) ||
            preg_match('/^linear-gradient\s*\([^)]*\)$/i', trim($value)) ||
            strtolower($value) === 'transparent' ||
            strtolower($value) === 'none') {
            return $raw;
        }

        // No url() and no position/size delimiter �?not a multi-value shorthand
        if (!preg_match('/url\s*\(/i', $value) && !str_contains($value, '/')) {
            return $raw;
        }

        // Parse the multi-value shorthand
        $rest = $value;
        $bgColor = '';
        $bgImage = '';
        $bgPosition = '';
        $bgSize = '';
        $bgRepeat = '';

        // 1. Extract color (distinctive formats)
        if (preg_match('/#[\da-fA-F]{3,8}\b/', $rest, $m)) {
            $bgColor = $m[0];
            $rest = trim(str_replace($m[0], '', $rest));
        }
        if (preg_match('/rgba?\s*\([^)]*\)/i', $rest, $m)) {
            $bgColor = $m[0];
            $rest = trim(str_replace($m[0], '', $rest));
        }
        if (preg_match('/linear-gradient\s*\([^)]*\)/i', $rest, $m)) {
            $bgColor = $m[0];
            $rest = trim(str_replace($m[0], '', $rest));
        }

        // 2. Extract image: url(...)
        if (preg_match('/url\s*\(\s*["\']?([^"\'\)]+)["\']?\s*\)/i', $rest, $m)) {
            $bgImage = trim($m[1]);
            $rest = trim(str_replace($m[0], '', $rest));
        }

        // 3. Extract position/size pair: ".../size"
        if (preg_match('#/\s*(\S+)#', $rest, $m)) {
            $bgSize = trim($m[1]);
            $rest = trim(str_replace($m[0], '', $rest));
        }

        // 4. Extract repeat keywords
        foreach (['repeat-x', 'repeat-y', 'no-repeat', 'repeat', 'space', 'round'] as $kw) {
            if (stripos($rest, $kw) !== false) {
                $bgRepeat = $kw;
                $rest = trim(str_ireplace($kw, '', $rest));
                break;
            }
        }

        // 5. Extract attachment keywords
        foreach (['scroll', 'fixed', 'local'] as $kw) {
            if (stripos($rest, $kw) !== false) {
                $rest = trim(str_ireplace($kw, '', $rest));
                break;
            }
        }

        // 6. Remaining rest is position (if /size was extracted)
        $rest = trim(preg_replace('/\s+/', ' ', $rest));
        if ($bgSize !== '' && $rest !== '') {
            $bgPosition = $rest;
            $rest = '';
        }

        // Set sub-properties only if not already explicitly specified
        if ($bgImage !== '' && !isset($raw['background-image'])) {
            $raw['background-image'] = 'url("' . $bgImage . '")';
        }
        if ($bgPosition !== '' && !isset($raw['background-position'])) {
            $raw['background-position'] = $bgPosition;
        }
        if ($bgSize !== '' && !isset($raw['background-size'])) {
            $raw['background-size'] = $bgSize;
        }
        if ($bgRepeat !== '' && !isset($raw['background-repeat'])) {
            $raw['background-repeat'] = $bgRepeat;
        }

        // Update background to color-only value for PROPERTY_MAP parsing
        if ($bgColor !== '') {
            $raw['background'] = $bgColor;
        } else {
            // No color specified �?transparent (parseHexColor returns 0)
            $raw['background'] = 'transparent';
        }

        return $raw;
    }

    /**
     * Expand text-decoration shorthand into individual sub-properties.
     *
     * CSS text-decoration shorthand syntax (CSS Text Decoration Module Level 3):
     *   text-decoration: <line> || <style> || <color> || <thickness>
     *
     * Examples:
     *   "underline"                        �?line=underline
     *   "underline wavy red"               �?line=underline, style=wavy, color=red
     *   "underline overline"               �?line=underline overline
     *   "underline wavy #FF0000 2px"        �?line=underline, style=wavy, color=#FF0000, thickness=2
     *   "none"                             �?line=none (no decoration)
     *
     * All four components can appear in any order. Multiple line keywords are
     * space-separated (e.g., "underline overline line-through").
     *
     * @param array $raw Raw style declarations
     * @return array Updated raw declarations with expanded sub-properties
     */
    public static function expandTextDecorationShorthand(array $raw): array
    {
        if (!isset($raw['text-decoration']) || $raw['text-decoration'] === '') {
            return $raw;
        }
        $expanded = self::expandTextDecorationValue(trim($raw['text-decoration']));
        foreach ($expanded as $key => $val) {
            // Convert camelCase back to kebab-case for the raw CSS property array
            $cssKey = strtolower(preg_replace('/([A-Z])/', '-$1', $key));
            if (!isset($raw[$cssKey])) {
                $raw[$cssKey] = $val;
            }
        }
        return $raw;
    }

    /**
     * Parse a text-decoration shorthand value into individual CSS declarations.
     *
     * @param string $value The raw shorthand value (e.g., "underline wavy red")
     * @return array CSS property name => value pairs
     */
    private static function expandTextDecorationValue(string $value): array
    {
        $result = [];
        $parts = preg_split('/\s+/', $value);

        $lineParts = [];
        $hasLine = false;

        foreach ($parts as $part) {
            if ($part === '') continue;
            $lower = strtolower($part);

            // Line keywords: underline, overline, line-through, none, blink
            if (in_array($lower, ['underline', 'overline', 'line-through', 'none', 'blink'], true)) {
                $lineParts[] = $lower;
                $hasLine = true;
                continue;
            }

            // Style keywords: solid, double, dotted, dashed, wavy
            if (in_array($lower, ['solid', 'double', 'dotted', 'dashed', 'wavy'], true)) {
                $result['textDecorationStyle'] = $lower;
                continue;
            }

            // Color: hex or rgb/rgba
            if (str_starts_with($part, '#') || preg_match('/^rgba?\s*\(/i', $part)) {
                $result['textDecorationColor'] = $part;
                continue;
            }

            // Thickness: numeric with optional px unit
            if (preg_match('/^\d+(\.\d+)?(px)?$/', $part)) {
                $result['textDecorationThickness'] = $part;
                continue;
            }
        }

        if ($hasLine) {
            $result['textDecorationLine'] = implode(' ', $lineParts);
        }

        return $result;
    }

    /**
     * Convert kebab-case to camelCase
     * e.g., "align-items" -> "alignItems"
     */
    private static function kebabToCamelCase(string $str): string
    {
        $parts = explode('-', $str);
        $result = array_shift($parts);
        foreach ($parts as $part) {
            $result .= ucfirst($part);
        }
        return $result;
    }

    /**
     * Parse grid-template-columns / grid-template-rows value.
     *
     * Examples:
     *   "repeat(4, 80px)" �?['repeat' => true, 'count' => 4, 'size' => 80]
     *   "1fr 1fr 1fr 1fr" �?['type' => 'explicit', 'sizes' => ['1fr','1fr','1fr','1fr']]
     *   "auto"            �?['type' => 'auto']
     *
     * @param string $val Raw CSS value
     * @return array
     */
    public static function parseGridTemplateValue(string $val): array
    {
        return CssValueParser::parseGridTemplateValue($val);
    }

    // ============================================================
    // Block-level parsing
    // ============================================================

    /**
     * Parse a <style> block and return class→properties map,
     * including :hover, :focus, :active pseudo-class variants
     * and complex selector rules.
     * 
     * Pseudo-class variants are stored with key "{className}__{pseudo}".
     * Complex selectors are stored as "__complex__{index}" with metadata.
     * 
     * @param string $styleCss  Raw content of <style>...</style>
     * @param array  $warnings  Output: collects parse warnings
     * @return array  [className => [outputKey => value], ...]
     */
    public static function parseStyleBlock(string $styleCss, array &$warnings = []): array
    {
        $classStyles = [];

        // Extract :root custom properties for var() resolution
        $variables = self::extractCustomProperties($styleCss);

        // --- First pass: Parse simple class rules ---
        // Match .className { ... }
        if (!preg_match_all('#\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $styleCss, $rules, PREG_SET_ORDER)) {
            // Even if no normal rules, still check for pseudo-class and complex rules
        }

        // --- Also parse universal selector rules: *, html, body ---
        // These apply to all elements and are stored under key '*', 'html', 'body'.
        $universalSelectors = ['*', 'html', 'body'];
        $universalRules = [];
        foreach ($universalSelectors as $us) {
            $escaped = preg_quote($us, '#');
            if (preg_match_all('#' . $escaped . '\s*\{([^}]*)\}#s', $styleCss, $m)) {
                foreach ($m[1] as $body) {
                    if (!isset($universalRules[$us])) $universalRules[$us] = '';
                    $universalRules[$us] .= $body;
                }
            }
        }
        // Treat universal rules as if they were .* { ... } in the rules list
        foreach ($universalRules as $us => $body) {
            $rules[] = [$us, $us, $body];
        }

        foreach ($rules as $rule) {
            $className = $rule[1];
            $body      = $rule[2];
            $props     = [];

            foreach (self::PROPERTY_MAP as $cssProp => $map) {
                $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                if (preg_match($pattern, $body, $m)) {
                    $value = trim($m[1]);
                    // Resolve CSS variables var(--name, fallback)
                    if (count($variables) > 0) {
                        $value = CssValueParser::resolveCSSVariables($value, $variables);
                    }
                    $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                }
            }

            // If neither background nor color was specified, log a warning
            // Skip for universal selectors �?they apply to all elements and
            // don't need explicit styling.
            if (!isset($props['bg']) && !isset($props['fg'])) {
                if (!in_array($className, ['*', 'html', 'body'], true)) {
                    $warnings[] = "CSS class '$className': no background or color property (will render as transparent)";
                }
            }

            // Parse border shorthand into individual properties (only if not already explicitly set)
            // Directional borders (更具�? 优先于通用 border 处理
            foreach (['borderBottom', 'borderTop', 'borderLeft', 'borderRight', 'border'] as $borderProp) {
                if (isset($props[$borderProp]) && $props[$borderProp] !== '') {
                    $parts = explode('|', $props[$borderProp]);
                    if (!isset($props['borderWidth'])) {
                        $props['borderWidth'] = (int)($parts[0] ?? 0);
                    }
                    if (!isset($props['borderColor'])) {
                        $props['borderColor'] = (int)($parts[1] ?? 0);
                    }
                }
            }

            // Expand text-decoration shorthand in class body
            if (preg_match('~text-decoration\s*:\s*([^;]+)~', $body, $tdMatch)) {
                $expanded = self::expandTextDecorationValue(trim($tdMatch[1]));
                foreach ($expanded as $tdKey => $tdVal) {
                    if (!isset($props[$tdKey])) {
                        $props[$tdKey] = $tdVal;
                    }
                }
            }



            // Preserve original CSS font-weight numeric value for test comparison.
            if (preg_match('/font-weight\s*:\s*(\d+)/i', $body, $fwMatch)) {
                $props['fontWeight'] = (int)$fwMatch[1];
            }

            $classStyles[$className] = $props;
        }

        // --- Pass 1.5: Parse universal/tag selectors (*, html, body, etc.) ---
        // Store as '*' for universal base styles
        if (preg_match_all('#([a-zA-Z*]+)\s*\{([^}]*)\}#s', $styleCss, $tagRules, PREG_SET_ORDER)) {
            foreach ($tagRules as $rule) {
                $selector = $rule[1];
                // Skip class-based rules (already parsed above) and pseudo-rules
                if (str_starts_with($selector, '.') || $selector === '') continue;
                $body = $rule[2];
                $props = [];
                foreach (self::PROPERTY_MAP as $cssProp => $map) {
                    $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                    if (preg_match($pattern, $body, $m)) {
                        $value = trim($m[1]);
                        if (count($variables) > 0) {
                            $value = CssValueParser::resolveCSSVariables($value, $variables);
                        }
                        $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                    }
                }
                // Preserve original CSS font-weight numeric value for test comparison.
                if (preg_match('/font-weight\s*:\s*(\d+)/i', $body, $fwMatch)) {
                    $props['fontWeight'] = (int)$fwMatch[1];
                }
                if (!empty($props)) {
                    // 通用选择�?* 归入 '*' 键，其他标签选择器归入对应键
                    $key = $selector === '*' ? '*' : $selector;
                    $classStyles[$key] = $props;
                }
            }
        }

        // Parse pseudo-class variants: .className:hover { ... }, .className:focus { ... }, .className:active { ... }
        // Store as "{className}__hover", "{className}__focus", "{className}__active"
        $pseudoClasses = ['hover', 'focus', 'active'];
        foreach ($pseudoClasses as $pseudo) {
            if (preg_match_all('#\.([a-zA-Z0-9_-]+):' . $pseudo . '\s*\{([^}]*)\}#s', $styleCss, $pseudoRules, PREG_SET_ORDER)) {
                foreach ($pseudoRules as $rule) {
                    $className = $rule[1];
                    $body      = $rule[2];
                    $props     = [];

                    foreach (self::PROPERTY_MAP as $cssProp => $map) {
                        $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                        if (preg_match($pattern, $body, $m)) {
                            $value = trim($m[1]);
                            // Resolve CSS variables
                            if (count($variables) > 0) {
                                $value = CssValueParser::resolveCSSVariables($value, $variables);
                            }
                            $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                        }
                    }

                    // Expand border shorthand
                    foreach (['borderBottom', 'borderTop', 'borderLeft', 'borderRight', 'border'] as $borderProp) {
                        if (isset($props[$borderProp]) && $props[$borderProp] !== '') {
                            $parts = explode('|', $props[$borderProp]);
                            if (!isset($props['borderWidth'])) {
                                $props['borderWidth'] = (int)($parts[0] ?? 0);
                            }
                            if (!isset($props['borderColor'])) {
                                $props['borderColor'] = (int)($parts[1] ?? 0);
                            }
                        }
                    }

                    // Expand text-decoration shorthand
                    if (preg_match('~text-decoration\s*:\s*([^;]+)~', $body, $tdMatch)) {
                        $expanded = self::expandTextDecorationValue(trim($tdMatch[1]));
                        foreach ($expanded as $tdKey => $tdVal) {
                            if (!isset($props[$tdKey])) {
                                $props[$tdKey] = $tdVal;
                            }
                        }
                    }



                    $pseudoKey = $className . '__' . $pseudo;
                    $classStyles[$pseudoKey] = $props;
                }
            }
        }

        // --- Parse ::before / ::after pseudo-elements (CSS Pseudo-Elements Module Level 4 §4) ---
        // Match .className::before { content: "..."; color: #...; }
        if (preg_match_all('#\.([a-zA-Z0-9_-]+)::(before|after)\s*\{([^}]*)\}#s', $styleCss, $pseudoElRules, PREG_SET_ORDER)) {
            foreach ($pseudoElRules as $rule) {
                $className = $rule[1];
                $pseudoEl = $rule[2]; // 'before' or 'after'
                $body = $rule[3];
                $props = [];

                // Extract 'content' property specially (not in PROPERTY_MAP)
                if (preg_match('~content\s*:\s*["\']?([^;"\'\}]+)["\']?\s*;?~', $body, $cMatch)) {
                    $props['content'] = trim($cMatch[1]);
                }

                foreach (self::PROPERTY_MAP as $cssProp => $map) {
                    $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                    if (preg_match($pattern, $body, $m)) {
                        $value = trim($m[1]);
                        if (count($variables) > 0) {
                            $value = CssValueParser::resolveCSSVariables($value, $variables);
                        }
                        $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                    }
                }

                $pseudoElKey = $className . '__' . $pseudoEl;
                $classStyles[$pseudoElKey] = $props;
            }
        }

        // --- Second pass: Parse complex selectors (descendant, child, sibling) ---
        // CSS Selectors Level 3: supported combinators:
        //   ' ' (descendant), '>' (child), '+' (adjacent sibling), '~' (general sibling)
        // Match .parent .child { }, .parent > .child { }, .sibling + .sibling { }, etc.
        if (preg_match_all('#\.([a-zA-Z0-9_-]+)\s*([>+~ ])\s*\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $styleCss, $complexRules, PREG_SET_ORDER)) {
            $complexIdx = 0;
            foreach ($complexRules as $rule) {
                $firstClass = $rule[1];
                $combinator = trim($rule[2]);
                $secondClass = $rule[3];
                $body = $rule[4];
                $props = [];

                foreach (self::PROPERTY_MAP as $cssProp => $map) {
                    $pattern = '~' . preg_quote($cssProp, '~') . '\s*:\s*([^;]+)~';
                    if (preg_match($pattern, $body, $m)) {
                        $value = trim($m[1]);
                        if (count($variables) > 0) {
                            $value = CssValueParser::resolveCSSVariables($value, $variables);
                        }
                        $props[$map['key']] = self::dispatchParser($map['parser'], $value);
                    }
                }

                // Expand border shorthand
                foreach (['borderBottom', 'borderTop', 'borderLeft', 'borderRight', 'border'] as $borderProp) {
                    if (isset($props[$borderProp]) && $props[$borderProp] !== '') {
                        $parts = explode('|', $props[$borderProp]);
                        if (!isset($props['borderWidth'])) {
                            $props['borderWidth'] = (int)($parts[0] ?? 0);
                        }
                        if (!isset($props['borderColor'])) {
                            $props['borderColor'] = (int)($parts[1] ?? 0);
                        }
                    }
                }

                // Expand text-decoration shorthand
                if (preg_match('~text-decoration\s*:\s*([^;]+)~', $body, $tdMatch)) {
                    $expanded = self::expandTextDecorationValue(trim($tdMatch[1]));
                    foreach ($expanded as $tdKey => $tdVal) {
                        if (!isset($props[$tdKey])) {
                            $props[$tdKey] = $tdVal;
                        }
                    }
                }



                // Store complex selector rule
                $complexKey = '__complex__' . $complexIdx;
                $classStyles[$complexKey] = [
                    'firstClass'  => $firstClass,
                    'combinator'  => $combinator,
                    'secondClass' => $secondClass,
                    'props'       => $props,
                    'specificity' => self::calculateSpecificity('.' . $firstClass . ' ' . $combinator . ' .' . $secondClass),
                ];
                $complexIdx++;
            }
        }

        return $classStyles;
    }

    /**
     * Calculate CSS selector specificity (a,b,c,d) per CSS Cascading and Inheritance Level 4 §6.
     *
     * Specificity calculation:
     *   - a: inline style (not calculated here, handled externally)
     *   - b: number of ID selectors (#id)
     *   - c: number of class selectors (.class), attribute selectors, pseudo-classes
     *   - d: number of element selectors, pseudo-elements
     *
     * @param string $selector Raw CSS selector string
     * @return array [a, b, c, d] specificity values
     */
    public static function calculateSpecificity(string $selector): array
    {
        $a = 0; // inline style (always 0 here)
        $b = 0; // ID selectors
        $c = 0; // class, attribute, pseudo-class
        $d = 0; // element, pseudo-element

        // Count ID selectors
        $b = (int)preg_match_all('/#[a-zA-Z0-9_-]+/', $selector);

        // Count class selectors
        $c = (int)preg_match_all('/\.[a-zA-Z0-9_-]+/', $selector);

        // Count pseudo-classes (:hover, :focus, :active, etc.)
        $c += (int)preg_match_all('/:(?:hover|focus|active|visited|link|first-child|last-child|nth-child|nth-of-type|not|is|where|has|enabled|disabled|checked|empty|target)/', $selector);

        // Count attribute selectors [attr]
        $c += (int)preg_match_all('/\[[^\]]+\]/', $selector);

        // Remaining simple selectors are element selectors
        // Remove ID, class, pseudo-class, attribute selectors first
        $remaining = preg_replace(
            ['/#[a-zA-Z0-9_-]+/', '/\.[a-zA-Z0-9_-]+/', '/:[a-zA-Z-]+(?:([^)]*))?/', '/\[[^\]]+\]/'],
            '',
            $selector
        );
        // Count remaining tags (words not separated by combinators)
        // Split by combinators (space, >, +, ~) and count non-empty parts that look like element names
        $parts = preg_split('/\s*[>+~ ]\s*/', $remaining);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !str_starts_with($part, '.') && !str_starts_with($part, '#') && !str_starts_with($part, ':') && !str_starts_with($part, '[')) {
                // It's an element selector (e.g., 'div', 'span')
                if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $part)) {
                    $d++;
                }
            }
        }
        // Also count pseudo-elements (::before, ::after, etc.)
        $d += (int)preg_match_all('/::[a-zA-Z-]+/', $selector);

        return [$a, $b, $c, $d];
    }

    /**
     * Alias for calculateSpecificity().
     *
     * @param string $selector Raw CSS selector string
     * @return array [a, b, c, d] specificity values
     */
    public static function countSpecificity(string $selector): array
    {
        return self::calculateSpecificity($selector);
    }

    /**
     * Compare two specificity arrays.
     * Returns -1 if $a < $b, 0 if equal, 1 if $a > $b.
     *
     * @param array $specA First specificity [a,b,c,d]
     * @param array $specB Second specificity [a,b,c,d]
     * @return int Comparison result
     */
    public static function compareSpecificity(array $specA, array $specB): int
    {
        for ($i = 0; $i < 4; $i++) {
            $sa = $specA[$i] ?? 0;
            $sb = $specB[$i] ?? 0;
            if ($sa !== $sb) {
                return $sa > $sb ? 1 : -1;
            }
        }
        return 0;
    }

    /**
     * Check if a complex selector matches given parent and child class names.
     *
     * @param string $combinator  The combinator character (' ', '>', '+', '~')
     * @param string $firstClass  The first/left class in the selector
     * @param string $secondClass The second/right class in the selector
     * @param string $parentClassStr  The parent VNode's class attribute string
     * @param string $childClassStr   The child VNode's class attribute string
     * @param array  $parentSiblings  Array of preceding sibling class strings (for + and ~)
     * @return bool True if the selector matches
     */
    public static function matchComplexSelector(string $combinator, string $firstClass, string $secondClass, string $parentClassStr, string $childClassStr, array $parentSiblings = []): bool
    {
        $childClasses = explode(' ', $childClassStr);
        // The second class must match the child element
        if (!in_array($secondClass, $childClasses, true)) {
            return false;
        }

        $parentClasses = explode(' ', $parentClassStr);

        switch ($combinator) {
            case ' ':
                // Descendant: parent must contain the first class
                return in_array($firstClass, $parentClasses, true);

            case '>':
                // Child: direct parent must contain the first class
                return in_array($firstClass, $parentClasses, true);

            case '+':
                // Adjacent sibling: the immediately preceding sibling must have the first class
                if (count($parentSiblings) === 0) return false;
                $prevSibling = end($parentSiblings);
                $prevClasses = explode(' ', $prevSibling);
                return in_array($firstClass, $prevClasses, true);

            case '~':
                // General sibling: any preceding sibling must have the first class
                foreach ($parentSiblings as $sibling) {
                    $siblingClasses = explode(' ', $sibling);
                    if (in_array($firstClass, $siblingClasses, true)) {
                        return true;
                    }
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * Get pseudo-class styles for a CSS class name.
     * Returns the parsed :hover/:focus/:active variant properties, or empty array if not defined.
     *
     * @param array  $classStyles  Result of parseStyleBlock()
     * @param string $className    CSS class name (without pseudo-class suffix)
     * @param string $pseudo       Pseudo-class name ('hover', 'focus', 'active')
     * @return array  Pseudo-class properties, or [] if no variant defined
     */
    public static function getPseudoStyle(array $classStyles, string $className, string $pseudo = 'hover'): array
    {
        return $classStyles[$className . '__' . $pseudo] ?? [];
    }

    /**
     * Get hover styles for a CSS class name (convenience wrapper).
     */
    public static function getHoverStyle(array $classStyles, string $className): array
    {
        return self::getPseudoStyle($classStyles, $className, 'hover');
    }

    /**
     * Extract CSS custom property definitions from :root { ... } blocks.
     *
     * CSS Custom Properties for Cascading Variables Module Level 1 §3:
     * Custom properties are --* names defined in :root or element-level style.
     * This method extracts them from :root in a <style> block for var() resolution.
     *
     * @param string $styleCss Raw <style> block content
     * @return array  Map of --name => raw value
     */
    public static function extractCustomProperties(string $styleCss): array
    {
        $variables = [];
        if (preg_match('/:root\s*\{([^}]*)\}/s', $styleCss, $m)) {
            $body = $m[1];
            if (preg_match_all('/\s*(--[a-zA-Z0-9_-]+)\s*:\s*([^;]+);?/', $body, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $varName = trim($match[1]);
                    $varValue = trim($match[2]);
                    $variables[$varName] = $varValue;
                }
            }
        }
        return $variables;
    }

    /**
     * Apply class styles to a layout element, merging with inline overrides.
     * 
     * @param array $classStyles  Result of parseStyleBlock()
     * @param string $className   CSS class name
     * @param array $overrides    Inline property overrides (e.g., from template attrs)
     * @return array  Merged style properties
     */
    public static function resolveStyle(array $classStyles, string $className, array $overrides = []): array
    {
        $style = $classStyles[$className] ?? [];
        return array_merge($style, $overrides);
    }

    /**
     * RGB �?BGR 格式转换�?
     *
     * GDI COLORREF 使用 BGR 字节序，而主题系统（ColorScheme）存�?RGB 格式�?
     * 例如�?xRRGGBB �?0xBBGGRR�?
     *
     * @param int $rgb RGB 格式颜色�?
     * @return int BGR 格式颜色�?
     */
    public static function rgbToBgr(int $rgb): int
    {
        return CssValueParser::rgbToBgr($rgb);
    }

    /**
     * �?CSS 样式字符串解析为键值对数组�?
     * 输入: "background:#2C2C2E;color:#FFF;left:10px"
     * 输出: ['background' => '#2C2C2E', 'color' => '#FFF', 'left' => '10px']
     *
     * AOT 安全：仅使用字符串操作和数组遍历�?
     */
    public static function parseStyleStringToArray(string $style): array
    {
        $result = [];
        $pairs = explode(';', $style);
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            $colonPos = strpos($pair, ':');
            if ($colonPos === false) continue;
            $prop = trim(substr($pair, 0, $colonPos));
            $value = trim(substr($pair, $colonPos + 1));
            if ($prop !== '') {
                $result[$prop] = $value;
            }
        }
        return $result;
    }

    /**
     * 将键值对数组序列化为 CSS 样式字符串�?
     * 输入: ['background' => '#2C2C2E', 'left' => '11px']
     * 输出: "background:#2C2C2E;left:11px;"
     * 输入: []
     * 输出: ""
     *
     * AOT 安全：仅使用字符串操作和数组遍历�?
     */
    public static function buildStyleStringFromArray(array $style): string
    {
        if (empty($style)) {
            return '';
        }
        $parts = [];
        foreach ($style as $prop => $value) {
            $parts[] = "{$prop}:{$value}";
        }
        return implode(';', $parts) . ';';
    }

    // ============================================================
    // Animation & Transition parsing
    // ============================================================

    /**
     * 解析 CSS transition 属性�?
     *
     * 格式: property duration timing-function delay
     * 示例: "all 300ms ease-in-out"
     *       "background-color 200ms linear, transform 300ms ease"
     *
     * @param string $value CSS transition �?
     * @return array 每个属性的解析结果
     *   [
     *     ['property' => 'all', 'duration' => 300, 'timing' => 'ease', 'delay' => 0],
     *     ...
     *   ]
     */
    public static function parseTransition(string $value): array
    {
        return CssValueParser::parseTransition($value);
    }

    /**
     * 解析 CSS animation 属性�?
     *
     * 格式: name duration timing-function delay count direction fill-mode play-state
     * 示例: "fadeIn 300ms ease-in-out"
     *
     * @param string $value CSS animation �?
     * @return array 解析结果
     */
    public static function parseAnimation(string $value): array
    {
        return CssValueParser::parseAnimation($value);
    }

    /**
     * 解析 CSS transform 属性�?
     *
     * 格式: translateX(X) translateY(Y)
     * 示例: "translateX(10px) translateY(-20px)"
     *       "translate(10px, -20px)"
     *
     * @param string $value CSS transform �?
     * @return array ['translateX' => int, 'translateY' => int]
     */
    public static function parseTransform(string $value): array
    {
        return CssValueParser::parseTransform($value);
    }

    /**
     * 序列�?transform 数组�?CSS 字符串�?
     *
     * @param array $transform ['translateX' => int, 'translateY' => int]
     * @return string CSS transform �?
     */
    public static function buildTransformString(array $transform): string
    {
        return CssValueParser::buildTransformString($transform);
    }
}
