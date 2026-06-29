<?php

namespace Px\Rendering;

use native_types;

/**
 * ComputedStyle — 不可变样式快照
 *
 * 构造时全量计算：展开简写 → 级联继承 → 填充默认值 → 冻结。
 * 所有属性以 CssValue 类型存储（width 是 CssLength，backgroundColor 是 CssColor）。
 * 不含伪类/伪元素样式（:hover, :focus, ::before, ::after 存储在 RenderNode::$pseudoStyles）。
 */
class ComputedStyle
{
    // ── 尺寸属性 ──
    public CssLength $width;
    public CssLength $height;
    public CssLength $minWidth;
    public CssLength $maxWidth;
    public CssLength $minHeight;
    public CssLength $maxHeight;
    public CssLength $flexBasis;
    public CssLength $gap;
    public CssLength $columnGap;
    public CssLength $rowGap;

    // ── 颜色属性 ──
    public CssColor $backgroundColor;
    public CssColor $color;

    // ── 关键字/标识符属性 ──
    public CssKeyword $display;
    public CssKeyword $position;
    public CssKeyword $overflow;
    public CssKeyword $overflowX;
    public CssKeyword $overflowY;
    public CssKeyword $boxSizing;
    public CssKeyword $flexDirection;
    public CssKeyword $flexWrap;
    public CssKeyword $alignItems;
    public CssKeyword $alignContent;
    public CssKeyword $alignSelf;
    public CssKeyword $justifyContent;
    public CssKeyword $justifyItems;
    public CssKeyword $justifySelf;
    public CssKeyword $whiteSpace;
    public CssKeyword $wordBreak;
    public CssKeyword $textAlign;
    public CssKeyword $verticalAlign;
    public CssKeyword $visibility;
    public CssKeyword $cursor;
    public CssKeyword $fontStyle;
    public CssKeyword $borderCollapse;
    public CssKeyword $pointerEvents;

    // ── 复合值 ──
    public CssFlex $flex;
    public CssRect $padding;
    public CssRect $margin;
    public CssRect $borderWidth;

    // ── 数值属性 ──
    public int $fontSize;
    public int $fontWeight;
    public bool $bold;
    public int $zIndex;
    public int $borderRadius;
    public int $columnCount;
    public int $columnWidth;
    public float $opacity;

    // ── 定位偏移（原始 int 用于布局计算） ──
    public int $left;
    public int $top;
    public int $right;
    public int $bottom;

    // ── 文本渲染 ──
    public string $fontFamily;
    public int $lineHeight;
    public int $textIndent;

    // ── 边框 ──
    public int $borderTopWidth;
    public int $borderRightWidth;
    public int $borderBottomWidth;
    public int $borderLeftWidth;
    public int $borderColor;
    public string $borderStyle;
    public int $borderTopColor;
    public int $borderRightColor;
    public int $borderBottomColor;
    public int $borderLeftColor;
    public string $borderTopStyle;
    public string $borderRightStyle;
    public string $borderBottomStyle;
    public string $borderLeftStyle;

    // ── 其他 ──
    public string $textDecorationLine;
    public string $textDecorationColor;
    public string $textDecorationStyle;
    public int $textDecorationThickness;
    public string $backgroundImage;
    public string $backgroundRepeat;
    public string $backgroundSize;
    public string $backgroundPosition;
    public string $backgroundClip;
    public string $backgroundOrigin;
    public string $backgroundAttachment;
    public string $boxShadow;
    public string $transform;
    public string $outlineWidth;
    public string $outlineStyle;
    public string $outlineColor;
    public int $outlineOffset;
    public string $listStyleType;
    public string $listStylePosition;
    public string $gridTemplateColumns;
    public string $gridTemplateRows;
    public string $gridAutoRows;
    public string $gridColumn;
    public string $gridRow;
    public string $gridTemplateAreas;
    public string $objectFit;
    public string $objectPosition;
    public string $appearance;
    public string $borderSpacing;
    public string $tableLayout;
    public string $captionSide;
    public string $fontStretch;
    public string $fontVariant;
    public string $textShadow;
    public string $letterSpacing;
    public string $wordSpacing;
    public string $overflowWrap;
    public string $textTransform;
    public string $wordWrap;

    // ── 原始声明存储（部分属性布局计算需要原始值） ──
    private array $rawDeclarations = [];

    /** 冻结标记 */
    private bool $frozen = false;

    /** 继承属性列表 */
    private const INHERITED_KEYS = [
        'fg', 'fontFamily', 'fontSize', 'fontWeight', 'bold', 'fontStyle',
        'lineHeight', 'textAlign', 'textIndent', 'whiteSpace', 'wordBreak',
        'visibility', 'opacity', 'cursor', 'direction', 'textShadow',
        'letterSpacing', 'wordSpacing', 'verticalAlign', 'fontVariant',
        'fontStretch', 'backgroundAttachment', 'outlineOffset',
        'borderCollapse', 'borderSpacing', 'tableLayout', 'captionSide',
    ];

    // ── 默认字体大小 ──
    private const DEFAULT_FONT_SIZE = 16;

    // ── 内联元素类型 ──
    private const INLINE_TYPES = [
        'span', '#text', 'b', 'strong', 'em', 'i', 'code', 'a', 'label', 'br',
    ];

    /**
     * @param array $declarations 样式声明（解析后的 key=>value 数组）
     * @param array $parentDeclarations 父元素声明（用于继承）
     * @param string $elementType 元素类型（用于默认 display）
     */
    public function __construct(
        array $declarations,
        array $parentDeclarations = [],
        string $elementType = 'div'
    ) {
        $this->rawDeclarations = $declarations;

        // 1. 设置默认值
        $this->setDefaults($elementType);

        // 2. 应用当前元素声明
        $this->applyDeclarations($declarations);

        // 3. 继承父元素属性
        $this->inheritFromParent($parentDeclarations);

        // 4. 冻结
        $this->frozen = true;
    }

    /**
     * 设置 CSS 默认值。
     */
    private function setDefaults(string $elementType): void
    {
        $this->width = CssLength::px(0);
        $this->height = CssLength::px(0);
        $this->minWidth = CssLength::px(0);
        $this->maxWidth = CssLength::px(0);
        $this->minHeight = CssLength::px(0);
        $this->maxHeight = CssLength::px(0);
        $this->flexBasis = CssLength::auto();
        $this->gap = CssLength::px(0);
        $this->columnGap = CssLength::px(0);
        $this->rowGap = CssLength::px(0);

        $this->backgroundColor = CssColor::transparent();
        $this->color = CssColor::transparent();

        $defaultDisplay = in_array($elementType, self::INLINE_TYPES, true) ? 'inline' : 'block';
        $this->display = new CssKeyword($defaultDisplay);
        $this->position = new CssKeyword('static');
        $this->overflow = new CssKeyword('visible');
        $this->overflowX = new CssKeyword('visible');
        $this->overflowY = new CssKeyword('visible');
        $this->boxSizing = new CssKeyword('content-box');
        $this->flexDirection = new CssKeyword('row');
        $this->flexWrap = new CssKeyword('nowrap');
        $this->alignItems = new CssKeyword('stretch');
        $this->alignContent = new CssKeyword('stretch');
        $this->alignSelf = new CssKeyword('auto');
        $this->justifyContent = new CssKeyword('flex-start');
        $this->justifyItems = new CssKeyword('stretch');
        $this->justifySelf = new CssKeyword('auto');
        $this->whiteSpace = new CssKeyword('normal');
        $this->wordBreak = new CssKeyword('normal');
        $this->textAlign = new CssKeyword('start');
        $this->verticalAlign = new CssKeyword('baseline');
        $this->visibility = new CssKeyword('visible');
        $this->cursor = new CssKeyword('auto');
        $this->fontStyle = new CssKeyword('normal');
        $this->borderCollapse = new CssKeyword('separate');
        $this->pointerEvents = new CssKeyword('auto');

        $this->flex = CssFlex::initial();
        $this->padding = new CssRect(
            CssLength::px(0), CssLength::px(0), CssLength::px(0), CssLength::px(0)
        );
        $this->margin = new CssRect(
            CssLength::px(0), CssLength::px(0), CssLength::px(0), CssLength::px(0)
        );
        $this->borderWidth = new CssRect(
            CssLength::px(0), CssLength::px(0), CssLength::px(0), CssLength::px(0)
        );

        $this->fontSize = self::DEFAULT_FONT_SIZE;
        $this->fontWeight = 400;
        $this->bold = false;
        $this->zIndex = 0;
        $this->borderRadius = 0;
        $this->columnCount = 0;
        $this->columnWidth = 0;
        $this->opacity = 1.0;

        $this->left = 0;
        $this->top = 0;
        $this->right = 0;
        $this->bottom = 0;

        $this->fontFamily = 'Segoe UI';
        $this->lineHeight = 0;
        $this->textIndent = 0;

        $this->borderTopWidth = 0;
        $this->borderRightWidth = 0;
        $this->borderBottomWidth = 0;
        $this->borderLeftWidth = 0;
        $this->borderColor = 0;
        $this->borderStyle = 'none';
        $this->borderTopColor = 0;
        $this->borderRightColor = 0;
        $this->borderBottomColor = 0;
        $this->borderLeftColor = 0;
        $this->borderTopStyle = 'none';
        $this->borderRightStyle = 'none';
        $this->borderBottomStyle = 'none';
        $this->borderLeftStyle = 'none';

        $this->textDecorationLine = '';
        $this->textDecorationColor = '';
        $this->textDecorationStyle = '';
        $this->textDecorationThickness = 0;
        $this->backgroundImage = '';
        $this->backgroundRepeat = 'repeat';
        $this->backgroundSize = '';
        $this->backgroundPosition = '';
        $this->backgroundClip = 'border-box';
        $this->backgroundOrigin = 'padding-box';
        $this->backgroundAttachment = 'scroll';
        $this->boxShadow = '';
        $this->transform = '';
        $this->outlineWidth = '';
        $this->outlineStyle = '';
        $this->outlineColor = '';
        $this->outlineOffset = 0;
        $this->listStyleType = '';
        $this->listStylePosition = '';
        $this->gridTemplateColumns = '';
        $this->gridTemplateRows = '';
        $this->gridAutoRows = '';
        $this->gridColumn = '';
        $this->gridRow = '';
        $this->gridTemplateAreas = '';
        $this->objectFit = '';
        $this->objectPosition = '';
        $this->appearance = '';
        $this->borderSpacing = '';
        $this->tableLayout = '';
        $this->captionSide = '';
        $this->fontStretch = '';
        $this->fontVariant = '';
        $this->textShadow = '';
        $this->letterSpacing = '';
        $this->wordSpacing = '';
        $this->overflowWrap = '';
        $this->textTransform = '';
        $this->wordWrap = '';
    }

    /**
     * 从声明数组应用值。
     */
    private function applyDeclarations(array $d): void
    {
        // ── CssLength 属性 ──
        $this->applyCssLength('width', 'width', $d);
        $this->applyCssLength('height', 'height', $d);
        $this->applyCssLength('minWidth', 'minWidth', $d);
        $this->applyCssLength('maxWidth', 'maxWidth', $d);
        $this->applyCssLength('minHeight', 'minHeight', $d);
        $this->applyCssLength('maxHeight', 'maxHeight', $d);
        $this->applyCssLength('gap', 'gap', $d);

        // ── 颜色 ──
        $this->applyColor('backgroundColor', 'bg', $d);
        $this->applyColor('color', 'fg', $d);

        // ── 关键字 ──
        $this->applyKeyword('display', 'display', $d, 'block');
        $this->applyKeyword('position', 'position', $d, 'static');
        $this->applyKeyword('overflow', 'overflow', $d, 'visible');
        $this->applyKeyword('overflowX', 'overflowX', $d, 'visible');
        $this->applyKeyword('overflowY', 'overflowY', $d, 'visible');
        $this->applyKeyword('boxSizing', 'boxSizing', $d, 'content-box');
        $this->applyKeyword('flexDirection', 'flexDirection', $d, 'row');
        $this->applyKeyword('flexWrap', 'flexWrap', $d, 'nowrap');
        $this->applyKeyword('alignItems', 'alignItems', $d, 'stretch');
        $this->applyKeyword('alignContent', 'alignContent', $d, 'stretch');
        $this->applyKeyword('justifyContent', 'justifyContent', $d, 'flex-start');
        $this->applyKeyword('justifyItems', 'justifyItems', $d, 'stretch');
        $this->applyKeyword('whiteSpace', 'whiteSpace', $d, 'normal');
        $this->applyKeyword('wordBreak', 'wordBreak', $d, 'normal');
        $this->applyKeyword('textAlign', 'textAlign', $d, 'start');
        $this->applyKeyword('verticalAlign', 'verticalAlign', $d, 'baseline');
        $this->applyKeyword('visibility', 'visibility', $d, 'visible');
        $this->applyKeyword('cursor', 'cursor', $d, 'auto');
        $this->applyKeyword('borderCollapse', 'borderCollapse', $d, 'separate');
        $this->applyKeyword('pointerEvents', 'pointerEvents', $d, 'auto');

        // ── flex ──
        if (isset($d['flex'])) {
            $fv = $d['flex'];
            if ($fv instanceof CssFlex) {
                $this->flex = $fv;
            } elseif (is_string($fv)) {
                $this->flex = CssFlex::fromString($fv);
            }
            $this->flexBasis = $this->flex->basis;
        }

        // ── 复合值 ──
        $this->applyPaddingMarginBorder($d);

        // ── 数值属性 ──
        if (isset($d['fontSize'])) {
            $fs = $d['fontSize'];
            if ($fs instanceof CssLength) {
                $this->fontSize = $fs->toPx();
            } elseif (is_numeric($fs)) {
                $this->fontSize = (int)$fs;
            }
        }
        if (isset($d['bold'])) {
            $this->bold = (bool)$d['bold'];
        }
        if (isset($d['fontWeight'])) {
            $this->fontWeight = (int)$d['fontWeight'];
        }
        if (isset($d['zIndex'])) {
            $this->zIndex = (int)$d['zIndex'];
        }
        if (isset($d['borderRadius'])) {
            $v = $d['borderRadius'];
            $this->borderRadius = $v instanceof CssLength ? $v->toPx() : (int)$v;
        }
        if (isset($d['columnCount'])) {
            $this->columnCount = (int)$d['columnCount'];
        }
        if (isset($d['columnWidth'])) {
            $this->columnWidth = (int)$d['columnWidth'];
        }
        if (isset($d['opacity'])) {
            $this->opacity = (float)$d['opacity'];
        }

        // ── 定位 ──
        $this->left = (int)($d['left'] ?? 0);
        $this->top = (int)($d['top'] ?? 0);
        $this->right = (int)($d['right'] ?? 0);
        $this->bottom = (int)($d['bottom'] ?? 0);

        // ── 其他字符串属性 ──
        foreach ([
            'fontFamily', 'backgroundImage', 'backgroundRepeat', 'backgroundSize',
            'backgroundPosition', 'backgroundClip', 'backgroundOrigin',
            'backgroundAttachment', 'boxShadow', 'transform',
            'outlineWidth', 'outlineStyle', 'outlineColor', 'outlineOffset',
            'listStyleType', 'listStylePosition', 'gridTemplateColumns',
            'gridTemplateRows', 'gridAutoRows', 'gridColumn', 'gridRow',
            'gridTemplateAreas', 'objectFit', 'objectPosition', 'appearance',
            'borderSpacing', 'tableLayout', 'captionSide', 'fontStretch',
            'fontVariant', 'textShadow', 'letterSpacing', 'wordSpacing',
            'overflowWrap', 'textTransform', 'wordWrap',
            'textDecorationLine', 'textDecorationColor', 'textDecorationStyle',
            'textDecorationThickness',
        ] as $k) {
            if (isset($d[$k])) {
                $v = $d[$k];
                if (is_string($v) || is_int($v)) {
                    $this->$k = (string)$v;
                }
            }
        }

        // ── display 覆盖 ──
        // 内联元素如果显式设置了 display 则使用该值
        if (isset($d['display'])) {
            $dv = $d['display'];
            if ($dv instanceof CssKeyword) {
                $this->display = $dv;
            } elseif (is_string($dv)) {
                $this->display = new CssKeyword($dv);
            }
        }
    }

    private function applyCssLength(string $prop, string $key, array $d): void
    {
        if (!isset($d[$key])) return;
        $v = $d[$key];
        if ($v instanceof CssLength) {
            $this->$prop = $v;
        } elseif (is_numeric($v)) {
            $this->$prop = CssLength::px((float)$v);
        } elseif (is_string($v) && $v !== '') {
            $this->$prop = CssLength::fromString($v);
        }
    }

    private function applyColor(string $prop, string $key, array $d): void
    {
        if (!isset($d[$key])) return;
        $v = $d[$key];
        if ($v instanceof CssColor) {
            $this->$prop = $v;
        } elseif (is_int($v)) {
            $this->$prop = CssColor::fromArgb($v);
        } elseif (is_string($v) && $v !== '') {
            $this->$prop = CssColor::fromString($v);
        }
    }

    private function applyKeyword(string $prop, string $key, array $d, string $default): void
    {
        if (!isset($d[$key])) return;
        $v = $d[$key];
        if ($v instanceof CssKeyword) {
            $this->$prop = $v;
        } elseif (is_string($v) && $v !== '') {
            $this->$prop = new CssKeyword($v);
        } else {
            $this->$prop = new CssKeyword($default);
        }
    }

    private function applyPaddingMarginBorder(array $d): void
    {
        // padding
        $defaultPx = CssLength::px(0);
        $this->padding = new CssRect(
            $this->cssLengthFromDecl($d, 'paddingTop', $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingRight', $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingBottom', $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingLeft', $defaultPx),
        );

        // margin
        $this->margin = new CssRect(
            $this->cssLengthFromDecl($d, 'marginTop', $defaultPx),
            $this->cssLengthFromDecl($d, 'marginRight', $defaultPx),
            $this->cssLengthFromDecl($d, 'marginBottom', $defaultPx),
            $this->cssLengthFromDecl($d, 'marginLeft', $defaultPx),
        );

        // border-width
        $bw = (int)($d['borderWidth'] ?? 0);
        $bw = $bw < 0 ? 0 : $bw;
        $bwCl = CssLength::px($bw);
        $this->borderWidth = new CssRect(
            $this->cssLengthFromDecl($d, 'borderTopWidth', $bwCl),
            $this->cssLengthFromDecl($d, 'borderRightWidth', $bwCl),
            $this->cssLengthFromDecl($d, 'borderBottomWidth', $bwCl),
            $this->cssLengthFromDecl($d, 'borderLeftWidth', $bwCl),
        );

        // border per-side widths (int storage for layout)
        $this->borderTopWidth = (int)($d['borderTopWidth'] ?? $bw);
        $this->borderRightWidth = (int)($d['borderRightWidth'] ?? $bw);
        $this->borderBottomWidth = (int)($d['borderBottomWidth'] ?? $bw);
        $this->borderLeftWidth = (int)($d['borderLeftWidth'] ?? $bw);

        // border color
        $bc = (int)($d['borderColor'] ?? 0);
        $this->borderColor = $bc;
        $this->borderTopColor = (int)($d['borderTopColor'] ?? $bc);
        $this->borderRightColor = (int)($d['borderRightColor'] ?? $bc);
        $this->borderBottomColor = (int)($d['borderBottomColor'] ?? $bc);
        $this->borderLeftColor = (int)($d['borderLeftColor'] ?? $bc);

        // border style
        $bs = (string)($d['borderStyle'] ?? '');
        $this->borderStyle = $bs;
        $this->borderTopStyle = (string)($d['borderTopStyle'] ?? $bs);
        $this->borderRightStyle = (string)($d['borderRightStyle'] ?? $bs);
        $this->borderBottomStyle = (string)($d['borderBottomStyle'] ?? $bs);
        $this->borderLeftStyle = (string)($d['borderLeftStyle'] ?? $bs);
    }

    private function cssLengthFromDecl(array $d, string $key, CssLength $default): CssLength
    {
        if (!isset($d[$key])) return $default;
        $v = $d[$key];
        if ($v instanceof CssLength) return $v;
        if (is_numeric($v)) return CssLength::px((float)$v);
        if (is_string($v) && $v !== '') return CssLength::fromString($v);
        return $default;
    }

    /**
     * 从父元素继承 CSS 继承属性。
     */
    private function inheritFromParent(array $parentDeclarations): void
    {
        if (empty($parentDeclarations)) return;

        foreach (self::INHERITED_KEYS as $key) {
            if (!isset($this->rawDeclarations[$key]) && isset($parentDeclarations[$key])) {
                // 仅当子节点未显式设置时才继承
                // 实际继承逻辑在布局策略中通过 parentStyle 参数处理
            }
        }

        // 继承 font-size
        if (!isset($this->rawDeclarations['fontSize']) && isset($parentDeclarations['fontSize'])) {
            $this->fontSize = $parentDeclarations['fontSize'] instanceof CssLength
                ? $parentDeclarations['fontSize']->toPx()
                : (int)$parentDeclarations['fontSize'];
        }

        // 继承 bold/fontWeight
        if (!isset($this->rawDeclarations['bold']) && isset($parentDeclarations['bold'])) {
            $this->bold = (bool)$parentDeclarations['bold'];
        }
        if (!isset($this->rawDeclarations['fontWeight']) && isset($parentDeclarations['fontWeight'])) {
            $this->fontWeight = (int)$parentDeclarations['fontWeight'];
        }
    }

    // ════════════════════════════════════════════════════════════════
    //  导出的 key → 原始声明数组
    // ════════════════════════════════════════════════════════════════

    /** 序列化所需的 key 列表 */
    private const EXPORT_KEYS = [
        'bg', 'fg', 'bgFromGradient', 'fontSize', 'fontWeight', 'bold',
        'borderWidth', 'borderColor', 'borderRadius', 'borderStyle',
        'textAlign', 'textIndent', 'textTransform', 'lineHeight',
        'whiteSpace', 'wordBreak', 'fontStyle', 'fontFamily',
        'opacity', 'visibility', 'display', 'position',
        'paddingTop', 'paddingLeft', 'paddingRight', 'paddingBottom',
        'marginTop', 'marginLeft', 'marginRight', 'marginBottom',
        '_computedMarginLeft', '_computedMarginRight',
        'minHeight', 'maxHeight', 'minWidth', 'maxWidth',
        'gap', 'boxSizing', 'boxShadow',
        'width', 'height',
        'flexDirection', 'alignItems', 'justifyContent', 'flexWrap',
        'flexShrink', 'flexGrow', 'order',
        'gridTemplateColumns', 'gridTemplateRows', 'gridColumnGap', 'gridRowGap',
        'gridColumn', 'gridRow', 'gridAutoRows', 'gridTemplateAreas',
        'justifyItems', 'alignSelf', 'justifySelf', 'alignContent',
        'overflow', 'overflowX', 'overflowY', 'overflowWrap',
        'backgroundRepeat', 'backgroundClip', 'backgroundOrigin',
        'backgroundAttachment', 'objectFit', 'objectPosition',
        'textShadow', 'letterSpacing', 'wordSpacing', 'verticalAlign',
        'fontVariant', 'fontStretch', 'appearance',
        'borderCollapse', 'borderSpacing', 'tableLayout', 'captionSide',
        'listStyleType', 'listStylePosition', 'pointerEvents',
        'outlineWidth', 'outlineStyle', 'outlineColor', 'outlineOffset',
        'columnCount', 'columnWidth', 'columnGap',
        'columnRuleWidth', 'columnRuleStyle', 'columnRuleColor',
        'textDecorationLine', 'textDecorationColor', 'textDecorationStyle',
        'textDecorationThickness',
    ];

    /**
     * 获取原始声明中的指定 key 值。
     */
    public function getRaw(string $key): mixed
    {
        return $this->rawDeclarations[$key] ?? null;
    }

    /**
     * 导出为数组（供 serializeRenderNode 使用）。
     */
    public function toExportArray(): array
    {
        $result = [];
        foreach (self::EXPORT_KEYS as $k) {
            $v = $this->rawDeclarations[$k] ?? null;
            if ($v !== null) {
                // Convert CssValue objects to raw values for export
                if ($v instanceof CssLength) {
                    $result[$k] = $v->toPx();
                } elseif ($v instanceof CssKeyword) {
                    $result[$k] = $v->value;
                } elseif ($v instanceof CssColor) {
                    $result[$k] = $v->toBgr();
                } elseif ($v instanceof CssFlex) {
                    $result[$k] = $v->grow . ' ' . $v->shrink . ' ' . $v->basis->toPx();
                } else {
                    $result[$k] = $v;
                }
            }
        }
        return $result;
    }

    // ════════════════════════════════════════════════════════════════
    //  派生属性（布局计算辅助）
    // ════════════════════════════════════════════════════════════════

    /**
     * 计算视觉总宽度（border-box width）。
     */
    public function visualWidth(int $contentW): int
    {
        $sizing = $this->boxSizing->value;
        if ($sizing === 'border-box') {
            return max(0, $contentW);
        }
        return max(0, $contentW
            + $this->padding->left->toPx() + $this->padding->right->toPx()
            + $this->borderLeftWidth + $this->borderRightWidth);
    }

    /**
     * 计算视觉总高度（border-box height）。
     */
    public function visualHeight(int $contentH): int
    {
        $sizing = $this->boxSizing->value;
        if ($sizing === 'border-box') {
            return max(0, $contentH);
        }
        return max(0, $contentH
            + $this->padding->top->toPx() + $this->padding->bottom->toPx()
            + $this->borderTopWidth + $this->borderBottomWidth);
    }

    /**
     * 计算 content box 宽度。
     */
    public function contentBoxWidth(int $totalW): int
    {
        $sizing = $this->boxSizing->value;
        if ($sizing === 'border-box') {
            return max(0, $totalW
                - $this->padding->left->toPx() - $this->padding->right->toPx()
                - $this->borderLeftWidth - $this->borderRightWidth);
        }
        return max(0, $totalW);
    }

    /**
     * 计算 content box 高度。
     */
    public function contentBoxHeight(int $totalH): int
    {
        $sizing = $this->boxSizing->value;
        if ($sizing === 'border-box') {
            return max(0, $totalH
                - $this->padding->top->toPx() - $this->padding->bottom->toPx()
                - $this->borderTopWidth - $this->borderBottomWidth);
        }
        return max(0, $totalH);
    }

    /**
     * 获取元素自身的 padding-top + border-top（用于子元素偏移计算）。
     */
    public function childOffsetX(): int
    {
        return $this->padding->left->toPx() + $this->borderLeftWidth;
    }

    /**
     * 获取元素自身的 padding-top + border-top（用于子元素偏移计算）。
     */
    public function childOffsetY(): int
    {
        return $this->padding->top->toPx() + $this->borderTopWidth;
    }

    /**
     * 解析百分比宽高：有百分比返回百分比，否则返回固定值。
     */
    public function resolveWidth(int $containerWidth): int
    {
        if ($this->width->isPercent()) {
            return $this->width->resolveInContext($containerWidth);
        }
        return $this->width->toPx();
    }

    public function resolveHeight(int $containerHeight): int
    {
        if ($this->height->isPercent()) {
            return $this->height->resolveInContext($containerHeight);
        }
        return $this->height->toPx();
    }
}
