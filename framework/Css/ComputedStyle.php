<?php

namespace Px\Css;
use Px\Render\RenderNode;

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
    public readonly CssLength $width;
    public readonly CssLength $height;
    public readonly CssLength $minWidth;
    public readonly CssLength $maxWidth;
    public readonly CssLength $minHeight;
    public readonly CssLength $maxHeight;
    public readonly CssLength $flexBasis;
    public readonly CssLength $gap;
    public readonly CssLength $columnGap;
    public readonly CssLength $rowGap;

    // ── 颜色属性 ──
    public readonly CssColor $backgroundColor;
    public readonly CssColor $color;

    // ── 关键字/标识符属性 ──
    public readonly CssKeyword $display;
    public readonly CssKeyword $position;
    public readonly CssKeyword $overflow;
    public readonly CssKeyword $overflowX;
    public readonly CssKeyword $overflowY;
    public readonly CssKeyword $boxSizing;
    public readonly CssKeyword $flexDirection;
    public readonly CssKeyword $flexWrap;
    public readonly CssKeyword $alignItems;
    public readonly CssKeyword $alignContent;
    public readonly CssKeyword $alignSelf;
    public readonly CssKeyword $justifyContent;
    public readonly CssKeyword $justifyItems;
    public readonly CssKeyword $justifySelf;
    public readonly CssKeyword $whiteSpace;
    public readonly CssKeyword $wordBreak;
    public readonly CssKeyword $textAlign;
    public readonly CssKeyword $verticalAlign;
    public readonly CssKeyword $visibility;
    public readonly CssKeyword $cursor;
    public readonly CssKeyword $fontStyle;
    public readonly CssKeyword $borderCollapse;
    public readonly CssKeyword $pointerEvents;

    // ── 复合值 ──
    public readonly CssFlex $flex;
    public readonly CssRect $padding;
    public readonly CssRect $margin;
    public readonly CssRect $borderWidth;

    // ── 数值属性 ──
    public readonly int $fontSize;
    public readonly int $fontWeight;
    public readonly bool $bold;
    public readonly int $zIndex;
    public readonly int $borderRadius;
    public readonly int $columnCount;
    public readonly int $columnWidth;
    public readonly float $opacity;

    // ── 宽高比 ──
    public readonly float $aspectRatio;

    // ── 定位偏移（CssLength 用于动态解析百分比） ──
    public readonly CssLength $left;
    public readonly CssLength $top;
    public readonly CssLength $right;
    public readonly CssLength $bottom;

    // ── 文本渲染 ──
    public readonly string $fontFamily;
    public readonly int $lineHeight;
    public readonly int $textIndent;

    // ── 边框 ──
    public readonly int $borderTopWidth;
    public readonly int $borderRightWidth;
    public readonly int $borderBottomWidth;
    public readonly int $borderLeftWidth;
    public readonly int $borderColor;
    public readonly string $borderStyle;
    public readonly int $borderTopColor;
    public readonly int $borderRightColor;
    public readonly int $borderBottomColor;
    public readonly int $borderLeftColor;
    public readonly string $borderTopStyle;
    public readonly string $borderRightStyle;
    public readonly string $borderBottomStyle;
    public readonly string $borderLeftStyle;

    // ── 其他 ──
    public readonly string $textDecorationLine;
    public readonly string $textDecorationColor;
    public readonly string $textDecorationStyle;
    public readonly int $textDecorationThickness;
    public readonly string $backgroundImage;
    public readonly string $backgroundRepeat;
    public readonly string $backgroundSize;
    public readonly string $backgroundPosition;
    public readonly string $backgroundClip;
    public readonly string $backgroundOrigin;
    public readonly string $backgroundAttachment;
    public readonly string $boxShadow;
    public readonly string $transform;
    public readonly string $outlineWidth;
    public readonly string $outlineStyle;
    public readonly string $outlineColor;
    public readonly int $outlineOffset;
    public readonly string $listStyleType;
    public readonly string $listStylePosition;
    public readonly string $gridTemplateColumns;
    public readonly string $gridTemplateRows;
    public readonly string $gridAutoRows;
    public readonly string $gridColumn;
    public readonly string $gridRow;
    public readonly string $gridTemplateAreas;
    public readonly string $gridAutoFlow;
    public readonly string $objectFit;
    public readonly string $objectPosition;
    public readonly string $appearance;
    public readonly string $borderSpacing;
    public readonly string $tableLayout;
    public readonly string $captionSide;
    public readonly string $fontStretch;
    public readonly string $fontVariant;
    public readonly string $textShadow;
    public readonly string $letterSpacing;
    public readonly string $wordSpacing;
    public readonly string $overflowWrap;
    public readonly string $textTransform;
    public readonly string $wordWrap;

    // ── int/bool/float getter（AOT 跨类 readonly 保护）──
    public function getFontSize(): int { return $this->fontSize; }
    public function getFontWeight(): int { return $this->fontWeight; }
    public function getBold(): bool { return $this->bold; }
    public function getZIndex(): int { return $this->zIndex; }
    public function getBorderRadius(): int { return $this->borderRadius; }
    public function getColumnCount(): int { return $this->columnCount; }
    public function getColumnWidth(): int { return $this->columnWidth; }
    public function getOpacity(): float { return $this->opacity; }
    public function getAspectRatio(): float { return $this->aspectRatio; }
    public function getLineHeight(): int { return $this->lineHeight; }
    public function getTextIndent(): int { return $this->textIndent; }
    public function getBorderTopWidth(): int { return $this->borderTopWidth; }
    public function getBorderRightWidth(): int { return $this->borderRightWidth; }
    public function getBorderBottomWidth(): int { return $this->borderBottomWidth; }
    public function getBorderLeftWidth(): int { return $this->borderLeftWidth; }
    public function getBorderColor(): int { return $this->borderColor; }
    public function getBorderTopColor(): int { return $this->borderTopColor; }
    public function getBorderRightColor(): int { return $this->borderRightColor; }
    public function getBorderBottomColor(): int { return $this->borderBottomColor; }
    public function getBorderLeftColor(): int { return $this->borderLeftColor; }
    public function getTextDecorationThickness(): int { return $this->textDecorationThickness; }
    public function getOutlineOffset(): int { return $this->outlineOffset; }

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
        // ── DIAG: 检测 rawDeclarations 中是否有 CssRect 残留 ──
        foreach ($declarations as $dk => $dv) {
            if ($dv instanceof CssRect) {
                error_log('[DIAG_RAW] CssRect in rawDeclarations key=' . $dk);
            }
        }

        // 合并默认值 + 显式声明 + 继承：确保每个属性只赋值一次（readonly）
        $merged = self::getDefaultsArray($elementType);
        foreach ($declarations as $k => $v) {
            $merged[$k] = $v;
        }
        // 父元素继承：仅当子元素未显式设置时
        foreach (self::INHERITED_KEYS as $key) {
            if (!isset($merged[$key]) && isset($parentDeclarations[$key])) {
                $merged[$key] = $parentDeclarations[$key];
            }
        }

        $this->applyDeclarations($merged);
        $this->frozen = true;
    }

    private static function getDefaultsArray(string $elementType): array
    {
        $defaultDisplay = in_array($elementType, self::INLINE_TYPES, true) ? 'inline' : 'block';
        return [
            'width' => CssLength::px(0),
            'height' => CssLength::px(0),
            'minWidth' => CssLength::px(0),
            'maxWidth' => CssLength::px(0),
            'minHeight' => CssLength::px(0),
            'maxHeight' => CssLength::px(0),
            'flexBasis' => CssLength::auto(),
            'gap' => CssLength::px(0),
            'columnGap' => CssLength::px(0),
            'rowGap' => CssLength::px(0),
            'bg' => CssColor::transparent(),
            'fg' => CssColor::transparent(),
            'display' => new CssKeyword($defaultDisplay),
            'position' => new CssKeyword('static'),
            'overflow' => new CssKeyword('visible'),
            'overflowX' => new CssKeyword('visible'),
            'overflowY' => new CssKeyword('visible'),
            'boxSizing' => new CssKeyword('border-box'),
            'flexDirection' => new CssKeyword('row'),
            'flexWrap' => new CssKeyword('nowrap'),
            'alignItems' => new CssKeyword('stretch'),
            'alignContent' => new CssKeyword('stretch'),
            'alignSelf' => new CssKeyword('auto'),
            'justifyContent' => new CssKeyword('flex-start'),
            'justifyItems' => new CssKeyword('stretch'),
            'justifySelf' => new CssKeyword('auto'),
            'whiteSpace' => new CssKeyword('normal'),
            'wordBreak' => new CssKeyword('normal'),
            'textAlign' => new CssKeyword('start'),
            'verticalAlign' => new CssKeyword('baseline'),
            'visibility' => new CssKeyword('visible'),
            'cursor' => new CssKeyword('auto'),
            'fontStyle' => new CssKeyword('normal'),
            'borderCollapse' => new CssKeyword('separate'),
            'pointerEvents' => new CssKeyword('auto'),
            'flex' => CssFlex::initial(),
            'padding' => new CssRect(CssLength::px(0), CssLength::px(0), CssLength::px(0), CssLength::px(0)),
            'margin' => new CssRect(CssLength::px(0), CssLength::px(0), CssLength::px(0), CssLength::px(0)),
            'borderWidth' => new CssRect(CssLength::px(0), CssLength::px(0), CssLength::px(0), CssLength::px(0)),
            'fontSize' => self::DEFAULT_FONT_SIZE,
            'fontWeight' => 400,
            'bold' => false,
            'zIndex' => 0,
            'borderRadius' => 0,
            'columnCount' => 0,
            'columnWidth' => 0,
            'opacity' => 1.0,
            'aspectRatio' => 0.0,
            'left' => CssLength::px(0), 'top' => CssLength::px(0), 'right' => CssLength::px(0), 'bottom' => CssLength::px(0),
            'fontFamily' => 'Segoe UI',
            'lineHeight' => 0,
            'textIndent' => 0,
            'borderTopWidth' => 0, 'borderRightWidth' => 0, 'borderBottomWidth' => 0, 'borderLeftWidth' => 0,
            'borderColor' => 0, 'borderStyle' => 'none',
            'borderTopColor' => 0, 'borderRightColor' => 0, 'borderBottomColor' => 0, 'borderLeftColor' => 0,
            'borderTopStyle' => 'none', 'borderRightStyle' => 'none', 'borderBottomStyle' => 'none', 'borderLeftStyle' => 'none',
            'textDecorationLine' => '', 'textDecorationColor' => '', 'textDecorationStyle' => '', 'textDecorationThickness' => 0,
            'backgroundImage' => '', 'backgroundRepeat' => 'repeat', 'backgroundSize' => '', 'backgroundPosition' => '',
            'backgroundClip' => 'border-box', 'backgroundOrigin' => 'padding-box', 'backgroundAttachment' => 'scroll',
            'boxShadow' => '', 'transform' => '',
            'outlineWidth' => '', 'outlineStyle' => '', 'outlineColor' => '', 'outlineOffset' => 0,
            'listStyleType' => '', 'listStylePosition' => '',
            'gridTemplateColumns' => '', 'gridTemplateRows' => '', 'gridAutoRows' => '',
            'gridColumn' => '', 'gridRow' => '', 'gridTemplateAreas' => '',
            'gridAutoFlow' => 'row',
            'objectFit' => '', 'objectPosition' => '', 'appearance' => '',
            'borderSpacing' => '', 'tableLayout' => '', 'captionSide' => '',
            'fontStretch' => '', 'fontVariant' => '',
            'textShadow' => '', 'letterSpacing' => '', 'wordSpacing' => '',
            'overflowWrap' => '', 'textTransform' => '', 'wordWrap' => '',
        ];
    }

    /**
     * 从声明数组应用值。
     */
    private function applyDeclarations(array $d): void
    {
        // ── CssLength 属性 ──
        $this->width = $this->resolveCssLength('width', $d) ?? $this->width;
        $this->height = $this->resolveCssLength('height', $d) ?? $this->height;
        $this->minWidth = $this->resolveCssLength('minWidth', $d) ?? $this->minWidth;
        $this->maxWidth = $this->resolveCssLength('maxWidth', $d) ?? $this->maxWidth;
        $this->minHeight = $this->resolveCssLength('minHeight', $d) ?? $this->minHeight;
        $this->maxHeight = $this->resolveCssLength('maxHeight', $d) ?? $this->maxHeight;
        $this->gap = $this->resolveCssLength('gap', $d) ?? $this->gap;
        $this->columnGap = $this->resolveCssLength('columnGap', $d) ?? $this->columnGap;
        $this->rowGap = $this->resolveCssLength('rowGap', $d) ?? $this->rowGap;
        $this->flexBasis = $this->resolveCssLength('flexBasis', $d) ?? $this->flexBasis;

        // ── 颜色 ──
        $bgVal = $this->resolveColor('bg', $d); if ($bgVal !== null) $this->backgroundColor = $bgVal;
        $fgVal = $this->resolveColor('fg', $d); if ($fgVal !== null) $this->color = $fgVal;

        // ── 关键字 ──
        $this->display = $this->resolveKeyword('display', $d, 'block');
        $this->position = $this->resolveKeyword('position', $d, 'static');
        $this->overflow = $this->resolveKeyword('overflow', $d, 'visible');
        $this->overflowX = $this->resolveKeyword('overflowX', $d, 'visible');
        $this->overflowY = $this->resolveKeyword('overflowY', $d, 'visible');
        $this->boxSizing = $this->resolveKeyword('boxSizing', $d, 'content-box');
        $this->flexDirection = $this->resolveKeyword('flexDirection', $d, 'row');
        $this->flexWrap = $this->resolveKeyword('flexWrap', $d, 'nowrap');
        $this->alignItems = $this->resolveKeyword('alignItems', $d, 'stretch');
        $this->alignContent = $this->resolveKeyword('alignContent', $d, 'stretch');
        $this->justifyContent = $this->resolveKeyword('justifyContent', $d, 'flex-start');
        $this->justifyItems = $this->resolveKeyword('justifyItems', $d, 'stretch');
        $this->justifySelf = $this->resolveKeyword('justifySelf', $d, 'auto');
        $this->alignSelf = $this->resolveKeyword('alignSelf', $d, 'auto');
        $this->whiteSpace = $this->resolveKeyword('whiteSpace', $d, 'normal');
        $this->wordBreak = $this->resolveKeyword('wordBreak', $d, 'normal');
        $this->textAlign = $this->resolveKeyword('textAlign', $d, 'start');
        $this->verticalAlign = $this->resolveKeyword('verticalAlign', $d, 'baseline');
        $this->visibility = $this->resolveKeyword('visibility', $d, 'visible');
        $this->cursor = $this->resolveKeyword('cursor', $d, 'auto');
        $this->borderCollapse = $this->resolveKeyword('borderCollapse', $d, 'separate');
        $this->pointerEvents = $this->resolveKeyword('pointerEvents', $d, 'auto');

        // ── flex（readonly 属性，必须恰好赋值一次）
        if (isset($d['flex'])) {
            $fv = $d['flex'];
            if ($fv instanceof CssFlex) {
                $this->flex = $fv;
            } elseif ($fv instanceof CssKeyword) {
                $this->flex = CssFlex::fromString($fv->value);
            } elseif (is_string($fv)) {
                $this->flex = CssFlex::fromString($fv);
            } else {
                $this->flex = CssFlex::initial();
            }
        } else {
            $this->flex = CssFlex::initial();
        }
        // flexBasis 已在上方通过 resolveCssLength('flexBasis', $d) 设置
        // 此处仅设置 flex 对象，不再重复赋值 flexBasis（readonly 不可二次赋值）

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
        // lineHeight: always assign (was never assigned before, causing typed property error)
        $this->lineHeight = isset($d['lineHeight']) ? self::safeInt($d['lineHeight']) : 0;
        if (isset($d['fontWeight'])) {
            $this->fontWeight = self::safeInt($d['fontWeight'], 400);
            $this->bold = $this->fontWeight >= 600;
        } elseif (isset($d['bold'])) {
            $this->bold = (bool)$d['bold'];
        }
        if (isset($d['zIndex'])) {
            $this->zIndex = self::safeInt($d['zIndex']);
        }
        if (isset($d['borderRadius'])) {
            $v = $d['borderRadius'];
            $this->borderRadius = $v instanceof CssLength ? $v->toPx() : self::safeInt($v);
        }
        if (isset($d['columnCount'])) {
            $v = $d['columnCount'];
            $this->columnCount = $v instanceof CssLength ? max(0, (int)$v->toPx()) : (int)$v;
        }
        if (isset($d['columnWidth'])) {
            $v = $d['columnWidth'];
            $this->columnWidth = $v instanceof CssLength ? max(0, (int)$v->toPx()) : (int)$v;
        }
        if (isset($d['opacity'])) {
            $this->opacity = (float)$d['opacity'];
        }
        if (isset($d['aspectRatio'])) {
            $v = $d['aspectRatio'];
            $this->aspectRatio = $v instanceof CssLength ? $v->toPx() : (float)$v;
        }

        // ── 定位（CssLength，支持百分比解析） ──
        $this->left = $this->resolveCssLength('left', $d) ?? CssLength::px(0);
        $this->top = $this->resolveCssLength('top', $d) ?? CssLength::px(0);
        $this->right = $this->resolveCssLength('right', $d) ?? CssLength::px(0);
        $this->bottom = $this->resolveCssLength('bottom', $d) ?? CssLength::px(0);

        // ── 其他字符串属性（AOT 兼容：显式逐一赋值，不用变量属性名）─
        $keys = [
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
        ];
        foreach ($keys as $k) {
            if (isset($d[$k])) {
                $v = $d[$k];
                // CssValue → raw 转换（parseIdent/parseHexColor 返回对象）
                $rv = $v;
                if ($v instanceof CssKeyword) {
                    $rv = $v->value;
                } elseif ($v instanceof CssColor) {
                    $rv = $v->toBgr();
                } elseif ($v instanceof CssLength) {
                    $rv = $v->toPx();
                }
                if (is_string($rv) || is_int($rv)) {
                    // AOT 兼容：match 显式分支，每支用字面量属性名
                    match ($k) {
                        'fontFamily' => $this->fontFamily = (string)$rv,
                        'backgroundImage' => $this->backgroundImage = (string)$rv,
                        'backgroundRepeat' => $this->backgroundRepeat = (string)$rv,
                        'backgroundSize' => $this->backgroundSize = (string)$rv,
                        'backgroundPosition' => $this->backgroundPosition = (string)$rv,
                        'backgroundClip' => $this->backgroundClip = (string)$rv,
                        'backgroundOrigin' => $this->backgroundOrigin = (string)$rv,
                        'backgroundAttachment' => $this->backgroundAttachment = (string)$rv,
                        'boxShadow' => $this->boxShadow = (string)$rv,
                        'transform' => $this->transform = (string)$rv,
                        'outlineWidth' => $this->outlineWidth = (string)$rv,
                        'outlineStyle' => $this->outlineStyle = (string)$rv,
                        'outlineColor' => $this->outlineColor = (string)$rv,
                        'outlineOffset' => $this->outlineOffset = (int)$rv,
                        'listStyleType' => $this->listStyleType = (string)$rv,
                        'listStylePosition' => $this->listStylePosition = (string)$rv,
                        'gridTemplateColumns' => $this->gridTemplateColumns = (string)$rv,
                        'gridTemplateRows' => $this->gridTemplateRows = (string)$rv,
                        'gridAutoRows' => $this->gridAutoRows = (string)$rv,
                        'gridColumn' => $this->gridColumn = (string)$rv,
                        'gridRow' => $this->gridRow = (string)$rv,
                        'gridTemplateAreas' => $this->gridTemplateAreas = (string)$rv,
                        'gridAutoFlow' => $this->gridAutoFlow = (string)$rv,
                        'objectFit' => $this->objectFit = (string)$rv,
                        'objectPosition' => $this->objectPosition = (string)$rv,
                        'appearance' => $this->appearance = (string)$rv,
                        'borderSpacing' => $this->borderSpacing = (string)$rv,
                        'tableLayout' => $this->tableLayout = (string)$rv,
                        'captionSide' => $this->captionSide = (string)$rv,
                        'fontStretch' => $this->fontStretch = (string)$rv,
                        'fontVariant' => $this->fontVariant = (string)$rv,
                        'textShadow' => $this->textShadow = (string)$rv,
                        'letterSpacing' => $this->letterSpacing = (string)$rv,
                        'wordSpacing' => $this->wordSpacing = (string)$rv,
                        'overflowWrap' => $this->overflowWrap = (string)$rv,
                        'textTransform' => $this->textTransform = (string)$rv,
                        'wordWrap' => $this->wordWrap = (string)$rv,
                        'textDecorationLine' => $this->textDecorationLine = (string)$rv,
                        'textDecorationColor' => $this->textDecorationColor = (string)$rv,
                        'textDecorationStyle' => $this->textDecorationStyle = (string)$rv,
                        'textDecorationThickness' => $this->textDecorationThickness = (int)$rv,
                        default => null,
                    };
                }
            }
        }
    }

    /** 将 camelCase 键转为 kebab-case（如 'minWidth' → 'min-width'）*/
    private static function camelToKebab(string $key): string {
        return strtolower(preg_replace('/([A-Z])/', '-$1', $key));
    }

    private function resolveCssLength(string $key, array $d): ?CssLength
    {
        // 优先 camelCase，fallback kebab-case
        $v = $d[$key] ?? $d[self::camelToKebab($key)] ?? null;
        if ($v === null) return null;
        if ($v instanceof CssLength) return $v;
        if ($v instanceof CssKeyword) return CssLength::fromString($v->value);
        if (is_numeric($v)) return CssLength::px((float)$v);
        if (is_string($v) && $v !== '') return CssLength::fromString($v);
        return null;
    }

    private function resolveColor(string $key, array $d): ?CssColor
    {
        $v = $d[$key] ?? $d[self::camelToKebab($key)] ?? null;
        if ($v === null) return null;
        if ($v instanceof CssColor) return $v;
        if (is_int($v)) return CssColor::fromArgb($v);
        if (is_string($v) && $v !== '') return CssColor::fromString($v);
        return null;
    }

    private function resolveKeyword(string $key, array $d, string $default): CssKeyword
    {
        $v = $d[$key] ?? $d[self::camelToKebab($key)] ?? null;
        if ($v === null) return new CssKeyword($default);
        if ($v instanceof CssKeyword) return $v;
        if (is_string($v) && $v !== '') return new CssKeyword($v);
        return new CssKeyword($default);
    }

    /**
     * 安全将任意值转为 int（防止 CssValue 对象强转崩溃）
     */
    private static function safeInt(mixed $v, int $default = 0): int
    {
        if ($v instanceof CssLength) return $v->toPx();
        if ($v instanceof CssRect) return $v->top->toPx();
        if ($v instanceof CssKeyword) return 0;
        if ($v instanceof CssColor) return $v->toBgr();
        return is_numeric($v) ? (int)$v : $default;
    }

    private function applyPaddingMarginBorder(array $d): void
    {
        // padding — fallback from shorthand `padding` CssRect when individual keys missing
        $padRect = ($d['padding'] ?? null) instanceof \Px\Css\CssRect ? $d['padding'] : null;
        $defaultPx = CssLength::px(0);
        $this->padding = new CssRect(
            $this->cssLengthFromDecl($d, 'paddingTop', $padRect?->top ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingRight', $padRect?->right ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingBottom', $padRect?->bottom ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingLeft', $padRect?->left ?? $defaultPx),
        );

        // margin — same fallback from shorthand `margin` CssRect
        $marginRect = ($d['margin'] ?? null) instanceof \Px\Css\CssRect ? $d['margin'] : null;
        $this->margin = new CssRect(
            $this->cssLengthFromDecl($d, 'marginTop', $marginRect?->top ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'marginRight', $marginRect?->right ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'marginBottom', $marginRect?->bottom ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'marginLeft', $marginRect?->left ?? $defaultPx),
        );

        // border-width
        $bwRaw = $d['borderWidth'] ?? 0;
        // CSS border/border-bottom/border-top/border-left/border-right 简写未展开时提取宽度
        $borderFallback = function(string $key, string $camelKey = '') use ($d): int {
            $v = $d[$key] ?? ($camelKey !== '' ? ($d[$camelKey] ?? null) : null);
            if (is_string($v) && preg_match('/^(\d+(\.\d+)?(px|pt|em|rem|%)?)\b/', trim($v), $m)) {
                $r = (int)$m[1];
                return $r;
            }
            return 0;
        };
        if ($bwRaw === 0 && $borderFallback('border')) {
            $bwRaw = $borderFallback('border');
        }
        if ($bwRaw instanceof CssRect) {
            $bw = $bwRaw->top->toPx();
        } elseif ($bwRaw instanceof CssLength) {
            $bw = $bwRaw->toPx();
        } else {
            $bw = (int)$bwRaw;
        }
        $bw = $bw < 0 ? 0 : $bw;
        $bwCl = CssLength::px($bw);
        $this->borderWidth = new CssRect(
            $this->cssLengthFromDecl($d, 'borderTopWidth', $bwCl),
            $this->cssLengthFromDecl($d, 'borderRightWidth', $bwCl),
            $this->cssLengthFromDecl($d, 'borderBottomWidth', $bwCl),
            $this->cssLengthFromDecl($d, 'borderLeftWidth', $bwCl),
        );

        // border per-side widths (int storage for layout)
        // CSS §8.5: 支持 border-top/bottom/left/right 简写宽度提取
        // 不直接用 ?? 因为 defaults 中 borderXxxWidth=0 会短路 fallback
        $btw = $d['borderTopWidth'] ?? null; $this->borderTopWidth = self::safeInt(($btw !== null && $btw != 0) ? $btw : ($borderFallback('border-top', 'borderTop') ?: $bw));
        $brw = $d['borderRightWidth'] ?? null; $this->borderRightWidth = self::safeInt(($brw !== null && $brw != 0) ? $brw : ($borderFallback('border-right', 'borderRight') ?: $bw));
        $bbw = $d['borderBottomWidth'] ?? null; $this->borderBottomWidth = self::safeInt(($bbw !== null && $bbw != 0) ? $bbw : ($borderFallback('border-bottom', 'borderBottom') ?: $bw));
        $blw = $d['borderLeftWidth'] ?? null; $this->borderLeftWidth = self::safeInt(($blw !== null && $blw != 0) ? $blw : ($borderFallback('border-left', 'borderLeft') ?: $bw));

        // border color
        $bc = self::safeInt($d['borderColor'] ?? 0);
        $this->borderColor = $bc;
        $this->borderTopColor = self::safeInt($d['borderTopColor'] ?? $bc);
        $this->borderRightColor = self::safeInt($d['borderRightColor'] ?? $bc);
        $this->borderBottomColor = self::safeInt($d['borderBottomColor'] ?? $bc);
        $this->borderLeftColor = self::safeInt($d['borderLeftColor'] ?? $bc);

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
        // Percentage flags (generated by StyleResolver, consumed by flex/grid internals)
        'widthPercent', 'heightPercent',
        'minWidthPercent', 'maxWidthPercent', 'minHeightPercent', 'maxHeightPercent',
        'marginTopPercent', 'marginRightPercent', 'marginBottomPercent', 'marginLeftPercent',
        'paddingTopPercent', 'paddingRightPercent', 'paddingBottomPercent', 'paddingLeftPercent',
        'leftPercent', 'topPercent', 'rightPercent', 'bottomPercent', 'borderRadiusPercent',
        // Relative unit flags
        'fontSizeUnit', 'widthUnit', 'heightUnit',
        'minWidthUnit', 'maxWidthUnit', 'minHeightUnit', 'maxHeightUnit',
        'marginTopUnit', 'marginRightUnit', 'marginBottomUnit', 'marginLeftUnit',
        'paddingTopUnit', 'paddingRightUnit', 'paddingBottomUnit', 'paddingLeftUnit',
        'gapUnit', 'topUnit', 'leftUnit', 'rightUnit', 'bottomUnit',
    ];

    /**
     * 获取原始声明中的指定 key 值。
     */
    public function getRaw(string $key): mixed
    {
        return $this->rawDeclarations[$key] ?? null;
    }

    /**
     * 获取 CSSValue 类型的属性值。
     */
    public function get(string $key): ?CssValue
    {
        // AOT 兼容：match 显式分支，每支用字面量属性名
        $val = match ($key) {
            'width' => $this->width,
            'height' => $this->height,
            'minWidth' => $this->minWidth,
            'maxWidth' => $this->maxWidth,
            'minHeight' => $this->minHeight,
            'maxHeight' => $this->maxHeight,
            'flexBasis' => $this->flexBasis,
            'gap' => $this->gap,
            'columnGap' => $this->columnGap,
            'rowGap' => $this->rowGap,
            'backgroundColor' => $this->backgroundColor,
            'color' => $this->color,
            'display' => $this->display,
            'position' => $this->position,
            'overflow' => $this->overflow,
            'overflowX' => $this->overflowX,
            'overflowY' => $this->overflowY,
            'boxSizing' => $this->boxSizing,
            'flexDirection' => $this->flexDirection,
            'flexWrap' => $this->flexWrap,
            'alignItems' => $this->alignItems,
            'alignContent' => $this->alignContent,
            'justifyContent' => $this->justifyContent,
            'justifyItems' => $this->justifyItems,
            'whiteSpace' => $this->whiteSpace,
            'wordBreak' => $this->wordBreak,
            'textAlign' => $this->textAlign,
            'verticalAlign' => $this->verticalAlign,
            'visibility' => $this->visibility,
            'cursor' => $this->cursor,
            'fontStyle' => $this->fontStyle,
            'borderCollapse' => $this->borderCollapse,
            'pointerEvents' => $this->pointerEvents,
            'flex' => $this->flex,
            'padding' => $this->padding,
            'margin' => $this->margin,
            'borderWidth' => $this->borderWidth,
            default => null,
        };
        if ($val instanceof CssValue) return $val;
        $raw = $this->getRaw($key);
        return $raw instanceof CssValue ? $raw : null;
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
                } elseif ($v instanceof CssRect) {
                    // CssRect (borderWidth/padding/margin) 转 top 值像素
                    $result[$k] = $v->top->toPx();
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
        return max(0, $contentW
            + $this->padding->left->toPx() + $this->padding->right->toPx()
            + $this->borderLeftWidth + $this->borderRightWidth);
    }

    /**
     * 计算视觉总高度（border-box height）。
     */
    public function visualHeight(int $contentH): int
    {
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
