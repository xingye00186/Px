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

    // ── toExportArray() 惰性缓存（对象已 frozen，输出稳定，首次计算后复用）──
    private array $exportCache = [];
    private bool $exportCached = false;

    /** 继承属性列表（仅真 CSS 继承属性；激活通道前已剔除非继承键
     * opacity/verticalAlign/backgroundAttachment/outlineOffset/tableLayout——
     * 旧表是死代码时期的误收，激活后会造成 va 双重位移等错继承） */
    private const INHERITED_KEYS = [
        'fg', 'fontFamily', 'fontSize', 'fontWeight', 'bold', 'fontStyle',
        'lineHeight', 'textAlign', 'textIndent', 'whiteSpace', 'wordBreak',
        'visibility', 'cursor', 'direction', 'textShadow',
        'letterSpacing', 'wordSpacing', 'fontVariant',
        'fontStretch',
        'borderCollapse', 'borderSpacing', 'captionSide',
    ];

    // ── 默认字体大小 ──
    private const DEFAULT_FONT_SIZE = 16;

    // ── 内联元素类型（UA 默认 display:inline，对标 Blink UA stylesheet html.css）──
    // 权威单源（public）：BlockAlgorithm 等布局侧引用此常量。此前三处各自
    // 维护且本表为短版（缺 q/kbd/mark 等）→ q 默认 display 误判 block
    //（case-050 E 716×25 vs Blink inline 48×24）。
    public const INLINE_TYPES = [
        'span', '#text', 'text', 'b', 'strong', 'em', 'i', 'code', 'a', 'label', 'br',
        'abbr', 'cite', 'dfn', 'kbd', 'mark', 'q', 'samp', 'small', 'sub',
        'sup', 'time', 'var', 'u', 's',
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
        // ── DIAG: 检测 rawDeclarations 中是否有 CssRect 残留（仅调试模式）──
        if (\Px\Core\Config::get('debug_diag_enabled', false)) {
            foreach ($declarations as $dk => $dv) {
                if ($dv instanceof CssRect) {
                    error_log('[DIAG_RAW] CssRect in rawDeclarations key=' . $dk);
                }
            }
        }

        // 合并默认值 + 显式声明 + 继承：确保每个属性只赋值一次（readonly）
        $merged = self::getDefaultsArray($elementType);
        foreach ($declarations as $k => $v) {
            $merged[$k] = $v;
        }
        // 父元素继承：仅当子元素未显式声明时（CSS 级联：声明 > 继承 > UA
        // 默认）。判据必须是 **$declarations**（显式声明）而非 $merged——
        // defaults 已先行占位 merged，旧判 !isset($merged) 恒假 → 继承通道
        // 整体死路（007 flex-item 内 wrapper text-align:center 断链实锤；
        // 此前继承全靠上游烘焙声明，未烘焙链路一律回落 defaults）。
        foreach (self::INHERITED_KEYS as $key) {
            if (!isset($declarations[$key]) && isset($parentDeclarations[$key])) {
                $merged[$key] = $parentDeclarations[$key];
            }
        }
        // Chrome UA 表格系：tbody/thead/tfoot/tr/td/th { border-color: inherit }
        //（非真继承属性，不入 INHERITED_KEYS）：仅色分量从父提取，
        // 宽/样式不继承（tbody 无自身 border 仍报父色，048 B tbody
        // blc=#a5d6a7/#ffcc80 vs E 恒黑实锤；父色由 toExportArray 出口
        // 闸门补真值道供给）。
        if (in_array($elementType, ['tbody', 'thead', 'tfoot', 'tr', 'td', 'th'], true)
            && !isset($declarations['borderColor']) && !isset($declarations['border'])
            && !isset($declarations['borderTopColor']) && !isset($declarations['borderLeftColor'])
            && isset($parentDeclarations['borderColor'])) {
            $pbc = self::safeInt($parentDeclarations['borderColor']);
            if ($pbc !== 0) {
                $merged['borderColor'] = $pbc;
            }
        }

        $this->applyDeclarations($merged);
        $this->frozen = true;
    }

    // ── 表格族 UA display 映射（对标 Blink UA stylesheet html.css §15.3.2）──
    // 此前表格族全部默认 block → TableAlgorithm 永不触发（td 块级垂直
    // 堆叠，case-048 x 偏移 361 族）。
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

    private static function getDefaultsArray(string $elementType): array
    {
        // 表单控件 UA 特例（对标 Blink UA html.css）：替换/控件类元素
        // 均为 inline-block 盒（input/textarea/button/progress/meter/select）；
        // <option>/<optgroup> 子树不产生常规布局盒——零盒化在布局层
        //（LayoutOrchestrator）处理而非 display:none
        //（none 会被导出层丢弃破坏元素集合同构：浏览器导出 0 盒）。
        if ($elementType === 'select' || $elementType === 'input' || $elementType === 'textarea'
            || $elementType === 'button' || $elementType === 'progress' || $elementType === 'meter') {
            $defaultDisplay = 'inline-block';
        } else {
            $defaultDisplay = self::TABLE_DISPLAY_MAP[$elementType]
                ?? (in_array($elementType, self::INLINE_TYPES, true) ? 'inline' : 'block');
        }
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
            // caption UA 居中（Blink html.css caption{text-align:center}，
            // case-048 B 真值 caption 内容居中实锤）；显式声明/继承链照常覆盖。
            'textAlign' => new CssKeyword($elementType === 'caption' ? 'center' : 'start'),
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
            // lineHeight 故意不设默认：normal 哨兵 -1 只存在于 typed 属性（else 分支），
            // 若放 defaults 会随 merged 进 rawDeclarations → 导出/round-trip/归一化链
            // 把 -1 当 number 声明（×fontSize=-16px）且 AOT Variant 分支分叉（双模式破裂）。
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
            // UA border-spacing:2px 仅 <table> 元素（Blink html.css 元素选择器，
            // div[display:table] 不适用——L24 实锤）；typed 通道携带，显式
            // 声明覆盖；非 table 元素空串 = 未声明（旧语义保持）。
            'borderSpacing' => $elementType === 'table' ? '2' : '', 'tableLayout' => '', 'captionSide' => '',
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
        // CSS-Overflow-3 §3.3：当 overflow-x 与 overflow-y 一方为 visible 而另一方不是 visible 时，
        // 那个 visible 列的 used value 变为 auto。仅当两方都为 visible 时才保留 visible。
        // → 先解析原始 value，再应用混合规则。
        $rawOX = $this->resolveKeyword('overflowX', $d, 'visible');
        $rawOY = $this->resolveKeyword('overflowY', $d, 'visible');
        $oxVal = $rawOX->value;
        $oyVal = $rawOY->value;
        if ($oxVal === 'visible' && $oyVal !== 'visible') {
            $this->overflowX = new CssKeyword('auto');
            $this->overflowY = $rawOY;
        } else if ($oyVal === 'visible' && $oxVal !== 'visible') {
            $this->overflowX = $rawOX;
            $this->overflowY = new CssKeyword('auto');
        } else {
            $this->overflowX = $rawOX;
            $this->overflowY = $rawOY;
        }
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
        // fontSize 分支必须穷尽（readonly 静默跳过 = 半初始化对象，后续 getFontSize()
        // 致命——与下方 lineHeight 历史缺陷同型）：字符串形态（'18px'，未经
        // PROPERTY_MAP 预解析的声明）走 CssLength::fromString 解析而非丢弃。
        if (isset($d['fontSize'])) {
            $fs = $d['fontSize'];
            if ($fs instanceof CssLength) {
                $this->fontSize = $fs->toPx();
            } elseif (is_numeric($fs)) {
                $this->fontSize = (int)$fs;
            } elseif (is_string($fs) && $fs !== '') {
                $this->fontSize = (int)CssLength::fromString($fs)->toPx();
            } else {
                $this->fontSize = self::DEFAULT_FONT_SIZE;
            }
        } else {
            $this->fontSize = self::DEFAULT_FONT_SIZE;
        }
        // lineHeight: used value 解析（对标 Blink ComputedLineHeight）：
        //   '24px' → 24；'1.5'（number，×fontSize）→ round(1.5*fs)；'' (normal) → -1 哨兵。
        // 哨兵必须 -1 非 0：显式 line-height:0 与 normal 必须可区分（Blink 三态
        // normal|number|length），且继承链只传 int used 值——raw 声明不随继承传播，
        // 0 哨兵会使继承的显式 0 被误判 normal（css-test 全局 *{line-height:0}）。
        // 此前 safeInt('1.5')→1px、'24px'/'24' 形态不分 —— 倍数行高全部塔陷。
        if (isset($d['lineHeight'])) {
            $lhRaw = $d['lineHeight'];
            if (is_string($lhRaw) && $lhRaw !== '') {
                if (str_ends_with($lhRaw, 'px')) {
                    $this->lineHeight = (int)$lhRaw;
                } elseif (is_numeric($lhRaw)) {
                    // 无单位倍数（含 em/% 已归一为倍数）：used = number × font-size。
                    // 整数确定性算术（milli 定点）：不依赖 round() 库语义——
                    // PHP/AOT Variant 的 round 半数行为分叉曾造成双模式行盒残差。
                    $lhMilli = (int)((float)$lhRaw * 1000 + 0.5);
                    $this->lineHeight = intdiv($lhMilli * $this->fontSize + 500, 1000);
                } else {
                    $this->lineHeight = self::safeInt($lhRaw, -1);
                }
            } elseif (is_int($lhRaw) || is_float($lhRaw)) {
                // 继承/round-trip 已解析值（含 -1 normal 哨兵）直接保留
                $this->lineHeight = (int)$lhRaw;
            } else {
                // '' (normal 声明) → 哨兵
                $this->lineHeight = -1;
            }
        } else {
            $this->lineHeight = -1;
        }
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
            // VNode 内联 style 烘焙为原始串（'16/9'）直达此处，不经
            // StyleResolver parser：(float)'16/9'=16 → h=w/16（051 E h=12 vs
            // B 112.5 实锤）。字符串一律走 a/b 语法解析（CSS-Sizing-4 §5）。
            if (is_string($v)) {
                $this->aspectRatio = CssValueParser::parseAspectRatio($v);
            } else {
                $this->aspectRatio = $v instanceof CssLength ? $v->toPx() : (float)$v;
            }
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
                        'textDecorationLine' => $this->textDecorationLine = is_object($rv) ? (string)($rv->value ?? 'none') : (string)$rv,
                        'textDecorationColor' => $this->textDecorationColor = is_object($rv) ? (string)($rv->value ?? '') : (string)$rv,
                        'textDecorationStyle' => $this->textDecorationStyle = is_object($rv) ? (string)($rv->value ?? 'solid') : (string)$rv,
                        'textDecorationThickness' => $this->textDecorationThickness = is_object($rv) ? (int)($rv->toPx() ?? 0) : (int)$rv,
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
        // 编译期烘焙声明（tag/class 规则）不走 parseInlineStyle 展开链，
        // 简写可能以字符串（'2px 6px'）/数值形态抵达——CSS §8.4 1-4 值展开
        //（与 border 简写族同源：A 类陷阱的字符串变体，此前非 CssRect 直接丢弃）。
        $padRaw = $d['padding'] ?? null;
        $padRect = $padRaw instanceof \Px\Css\CssRect ? $padRaw : self::rectFromShorthand($padRaw);
        $defaultPx = CssLength::px(0);
        $this->padding = new CssRect(
            $this->cssLengthFromDecl($d, 'paddingTop', $padRect?->top ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingRight', $padRect?->right ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingBottom', $padRect?->bottom ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'paddingLeft', $padRect?->left ?? $defaultPx),
        );

        // margin — same fallback from shorthand `margin` CssRect（含字符串/数值展开，CSS §8.3）
        $marginRaw = $d['margin'] ?? null;
        $marginRect = $marginRaw instanceof \Px\Css\CssRect ? $marginRaw : self::rectFromShorthand($marginRaw);
        $this->margin = new CssRect(
            $this->cssLengthFromDecl($d, 'marginTop', $marginRect?->top ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'marginRight', $marginRect?->right ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'marginBottom', $marginRect?->bottom ?? $defaultPx),
            $this->cssLengthFromDecl($d, 'marginLeft', $marginRect?->left ?? $defaultPx),
        );

        // border-width
        // A 类默认值陷阱：defaults 的 borderWidth=CssRect(0,0,0,0) 使 $bwRaw 非 0（对象），
        // 短路 border 简写 fallback——编译期烘焙数组不走 parseInlineStyle 展开链，
        // border:1px solid 不会产出 borderWidth 显式键，导致 mc-container 等 auto 宽
        // 未扣 border（E 712 vs Blink 740，case-016 实测）。仅当 borderWidth 是
        // 非零显式值时才阻断 fallback。
        $bwRaw = $d['borderWidth'] ?? 0;
        $bwRawIsZeroDefault = ($bwRaw instanceof CssRect)
            && (float)$bwRaw->top->toPx() === 0.0 && (float)$bwRaw->right->toPx() === 0.0
            && (float)$bwRaw->bottom->toPx() === 0.0 && (float)$bwRaw->left->toPx() === 0.0;
        // CSS border/border-bottom/border-top/border-left/border-right 简写未展开时提取宽度
        $borderFallback = function(string $key, string $camelKey = '') use ($d): int {
            $v = $d[$key] ?? ($camelKey !== '' ? ($d[$camelKey] ?? null) : null);
            if (is_string($v) && preg_match('/^(\d+(\.\d+)?(px|pt|em|rem|%)?)\b/', trim($v), $m)) {
                $r = (int)$m[1];
                return $r;
            }
            return 0;
        };
        if (($bwRaw === 0 || $bwRawIsZeroDefault) && $borderFallback('border')) {
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

        // border per-side widths (int storage for layout)
        // CSS §8.5: 支持 border-top/bottom/left/right 简写宽度提取
        // 不直接用 ?? 因为 defaults 中 borderXxxWidth=0 会短路 fallback
        $btw = $d['borderTopWidth'] ?? null; $btwV = $btw !== null ? self::safeInt($btw) : 0; $this->borderTopWidth = $btwV !== 0 ? $btwV : self::safeInt($borderFallback('border-top', 'borderTop') ?: $bw);
        $brw = $d['borderRightWidth'] ?? null; $brwV = $brw !== null ? self::safeInt($brw) : 0; $this->borderRightWidth = $brwV !== 0 ? $brwV : self::safeInt($borderFallback('border-right', 'borderRight') ?: $bw);
        $bbw = $d['borderBottomWidth'] ?? null; $bbwV = $bbw !== null ? self::safeInt($bbw) : 0; $this->borderBottomWidth = $bbwV !== 0 ? $bbwV : self::safeInt($borderFallback('border-bottom', 'borderBottom') ?: $bw);
        $blw = $d['borderLeftWidth'] ?? null; $blwV = $blw !== null ? self::safeInt($blw) : 0; $this->borderLeftWidth = $blwV !== 0 ? $blwV : self::safeInt($borderFallback('border-left', 'borderLeft') ?: $bw);

        // borderWidth Rect 从 int 四边派生（单源化：int 四边是唯一权威）。
        // 此前 Rect 用 cssLengthFromDecl 独立取值，defaults 的 borderXxxWidth=0 int
        // 短路 $bwCl 兜底 → 简写场景 Rect 恒 0 而 int 四边正确（双源分叉），
        // Paint 层 4 处 + RTM 1 处读 Rect → 边框宽度对但不绘制。
        $this->borderWidth = new CssRect(
            CssLength::px($this->borderTopWidth),
            CssLength::px($this->borderRightWidth),
            CssLength::px($this->borderBottomWidth),
            CssLength::px($this->borderLeftWidth),
        );

        // border color（同样支持从简写提取：defaults borderColor=0 短路同族陷阱）
        $bc = self::safeInt($d['borderColor'] ?? 0);
        if ($bc === 0 && isset($d['border']) && is_string($d['border'])) {
            $bShortC = $d['border'];
            if (preg_match('/^\d+\|(\d+)\|\w+$/', $bShortC, $bcm)) {
                $bc = (int)$bcm[1]; // 管道编码 width|color|style
            } elseif (preg_match('/#([0-9a-fA-F]{3,8})\b/', $bShortC, $bcm2)) {
                $cl = CssValueParser::parseHexColor('#' . $bcm2[1]);
                $bc = is_numeric($cl) ? (int)$cl : $bc;
            }
        }
        $this->borderColor = $bc;
        // per-side color：defaults 含 borderTopColor=0 四边键使 `?? $bc` 永不触发
        //（A 类默认值陷阱第三例，与 borderWidth int/CssRect 同族）——简写提取的
        // 颜色从不到达 per-side，导出/paint 恒黑。CSS §8.5.4：per-side 未显式
        // 声明时 = 简写展开值（Blink parse 期 longhand 展开）。非 0 显式值用之，
        // 否则从 per-side 简写（border-top: 1px solid #x）提取，再回落 $bc。
        // 已知权衡（与 width 修复一致）：显式声明纯黑 per-side 会被回落覆盖，
        // 因 merged 后无法区分默认 0 与显式 black（哨兵冲突已录台账）。
        $sideColorFromShort = function(string $key, string $camelKey) use ($d): int {
            $v = $d[$key] ?? ($d[$camelKey] ?? null);
            if (is_string($v)) {
                if (preg_match('/^\d+\|(\d+)\|\w+$/', $v, $m)) {
                    return (int)$m[1]; // 管道编码 width|color|style
                }
                if (preg_match('/#([0-9a-fA-F]{3,8})\b/', $v, $m)) {
                    $cl = CssValueParser::parseHexColor('#' . $m[1]);
                    return is_numeric($cl) ? (int)$cl : 0;
                }
            }
            return 0;
        };
        $btc = self::safeInt($d['borderTopColor'] ?? 0);
        $this->borderTopColor = $btc !== 0 ? $btc : (self::safeInt($sideColorFromShort('border-top', 'borderTop') ?: $bc));
        $brc = self::safeInt($d['borderRightColor'] ?? 0);
        $this->borderRightColor = $brc !== 0 ? $brc : (self::safeInt($sideColorFromShort('border-right', 'borderRight') ?: $bc));
        $bbc = self::safeInt($d['borderBottomColor'] ?? 0);
        $this->borderBottomColor = $bbc !== 0 ? $bbc : (self::safeInt($sideColorFromShort('border-bottom', 'borderBottom') ?: $bc));
        $blc = self::safeInt($d['borderLeftColor'] ?? 0);
        $this->borderLeftColor = $blc !== 0 ? $blc : (self::safeInt($sideColorFromShort('border-left', 'borderLeft') ?: $bc));

        // border style (handle CssKeyword objects from parseIdent)
        // CSS §8.5.4：border 简写设置 width/style/color 三分量——未展开时从简写
        // 提取 style（'1px solid #xxx' 或管道编码 '1|色|solid'），否则 borderStyle
        // 恒 'none' 与宽度提取不自洽（导出/paint 层分歧）。
        $bsRaw = $d['borderStyle'] ?? '';
        $bs = is_object($bsRaw) ? ($bsRaw->value ?? 'none') : (string)$bsRaw;
        if (($bs === '' || $bs === 'none') && isset($d['border']) && is_string($d['border'])) {
            $bShort = $d['border'];
            if (preg_match('/\b(solid|dashed|dotted|double|groove|ridge|inset|outset)\b/i', $bShort, $bsm)) {
                $bs = strtolower($bsm[1]);
            } elseif (preg_match('/^\d+\|\d+\|(\w+)$/', $bShort, $bsm2)) {
                $bs = strtolower($bsm2[1]); // 管道编码 width|color|style
            }
        }
        $this->borderStyle = $bs;
        // per-side style：同族陷阱第四例——defaults 含 borderTopStyle='none' 四边键，
        // `?? $bs` 永不触发，简写场景 per-side 恒 'none' 与全局 $bs=solid 分叉。
        // 显式非 none 用之，否则从 per-side 简写提取，再回落 $bs。
        $sideStyleFromShort = function(string $key, string $camelKey) use ($d): string {
            $v = $d[$key] ?? ($d[$camelKey] ?? null);
            if (is_string($v)) {
                if (preg_match('/\b(solid|dashed|dotted|double|groove|ridge|inset|outset)\b/i', $v, $m)) {
                    return strtolower($m[1]);
                }
                if (preg_match('/^\d+\|\d+\|(\w+)$/', $v, $m)) {
                    return strtolower($m[1]); // 管道编码 width|color|style
                }
            }
            return '';
        };
        $btsRaw = $d['borderTopStyle'] ?? '';
        $bts = is_object($btsRaw) ? ($btsRaw->value ?? '') : (string)$btsRaw;
        $this->borderTopStyle = ($bts !== '' && $bts !== 'none') ? $bts : (string)($sideStyleFromShort('border-top', 'borderTop') ?: $bs);
        $brsRaw = $d['borderRightStyle'] ?? '';
        $brs = is_object($brsRaw) ? ($brsRaw->value ?? '') : (string)$brsRaw;
        $this->borderRightStyle = ($brs !== '' && $brs !== 'none') ? $brs : (string)($sideStyleFromShort('border-right', 'borderRight') ?: $bs);
        $bbsRaw = $d['borderBottomStyle'] ?? '';
        $bbs = is_object($bbsRaw) ? ($bbsRaw->value ?? '') : (string)$bbsRaw;
        $this->borderBottomStyle = ($bbs !== '' && $bbs !== 'none') ? $bbs : (string)($sideStyleFromShort('border-bottom', 'borderBottom') ?: $bs);
        $blsRaw = $d['borderLeftStyle'] ?? '';
        $bls = is_object($blsRaw) ? ($blsRaw->value ?? '') : (string)$blsRaw;
        $this->borderLeftStyle = ($bls !== '' && $bls !== 'none') ? $bls : (string)($sideStyleFromShort('border-left', 'borderLeft') ?: $bs);
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
     * 字符串/数值简写 → CssRect（CSS §8.3/8.4：1 值全边；2 值 [上下,左右]；
     * 3 值 [上,左右,下]；4 值 [上,右,下,左]）。非简写形态返 null。
     */
    private static function rectFromShorthand(mixed $v): ?CssRect
    {
        if (is_numeric($v)) {
            $l = CssLength::px((float)$v);
            return new CssRect($l, $l, $l, $l);
        }
        if (!is_string($v) || trim($v) === '') return null;
        $parts = preg_split('/\s+/', trim($v));
        if ($parts === false || count($parts) < 1 || count($parts) > 4) return null;
        $ls = [];
        foreach ($parts as $p) {
            $ls[] = CssLength::fromString($p);
        }
        $n = count($ls);
        if ($n === 1) return new CssRect($ls[0], $ls[0], $ls[0], $ls[0]);
        if ($n === 2) return new CssRect($ls[0], $ls[1], $ls[0], $ls[1]);
        if ($n === 3) return new CssRect($ls[0], $ls[1], $ls[2], $ls[1]);
        return new CssRect($ls[0], $ls[1], $ls[2], $ls[3]);
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
     * 与 resolveKeyword/resolveCssLength/resolveColor 一致：camelCase key 未命中时
     * fallback 到 kebab-case（兼容运行时 style 字符串解析产物的 kebab key，
     * 如 grid-template-columns）。修复 Grid 等算法读驼峰 key 得 NULL 的数据要素错乱。
     */
    public function getRaw(string $key): mixed
    {
        return $this->rawDeclarations[$key] ?? $this->rawDeclarations[self::camelToKebab($key)] ?? null;
    }

    /**
     * 尺寸属性是否为“显式确定长度”（对标 Blink Length::IsFixed + 声明检查）。
     *
     * 背景：Px 默认 width/height = CssLength::px(0)（非 Blink 的 auto），单用
     * isAuto()/toPx() 无法区分“显式声明 :0”与“未声明（默认 px0）”——该陷阱已
     * 在 height/width/margin/auto-height 等 5+ 处复发。本 API 为唯一判定入口：
     *   显式 = getRaw 有声明 && 非 auto && 非百分比 && 非 intrinsic（min/max-content 等）。
     * 百分比/intrinsic 需调用方自行按包含块/内容解析，不属“确定长度”。
     */
    public function hasExplicitLength(string $prop): bool
    {
        if ($this->getRaw($prop) === null) return false;
        $len = match ($prop) {
            'width' => $this->width,
            'height' => $this->height,
            'minWidth' => $this->minWidth,
            'minHeight' => $this->minHeight,
            'maxWidth' => $this->maxWidth,
            'maxHeight' => $this->maxHeight,
            'flexBasis' => $this->flexBasis,
            default => null,
        };
        if ($len === null) return false;
        return !$len->isAuto() && !$len->isPercent() && !$len->isIntrinsic();
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
     * 惰性缓存：对象已 frozen，输出稳定，首次构建后复用。
     * 取消 O(120) key 遍历 + 类型转换，非首次调用 O(1)。
     */
    public function toExportArray(): array
    {
        if ($this->exportCached) return $this->exportCache;
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
        $this->exportCache = $result;
        // 出口闸门补真值：border 简写场景 raw 无 borderColor 键（管道串在
        // 'border'，不在 EXPORT_KEYS）→ 再构造链（patch 降级路径 toExportArray
        // 回灌 new ComputedStyle）永久丢色，UA 表格系 border-color:inherit
        // 无源可继（048 tbody 恒黑 backtrace 实锤）。用已解析属性补。
        if (!isset($result['borderColor']) && $this->borderColor !== 0) {
            $result['borderColor'] = $this->borderColor;
            $this->exportCache = $result;
        }
        $this->exportCached = true;
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
