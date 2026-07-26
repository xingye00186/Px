<?php

namespace Px\Css;

/**
 * CssShorthandExpander — CSS 简写属性统一展开（SFC 编译器 + StyleResolver 运行时共用）
 *
 * 对标 Blink: style resolution 阶段展开所有简写为 longhand。
 * SFC 编译器在构建期调用（零运行时开销），StyleResolver 在运行时对动态样式调用。
 *
 * 覆盖：
 *   - padding / margin → 4 方向
 *   - border-width / border-color / border-style → 4 方向
 *   - overflow → overflow-x + overflow-y（2 值场景）
 *   - gap → row-gap + column-gap（2 值场景）
 *   - place-items → align-items + justify-items
 *   - place-content → align-content + justify-content
 *   - inset → top + right + bottom + left
 *   - flex → flex-grow + flex-shrink + flex-basis（gated: 暂不启用，待 min-content）
 *
 * 规则：显式声明的 longhand 优先于简写展开（CSS Cascade 规则 §6.4.3）
 */
class CssShorthandExpander
{
    /**
     * 展开所有支持的 CSS 简写属性。
     *
     * @param array $raw kebab-case CSS 属性名 → 值 的声明数组
     * @param bool $expandFlex 是否展开 flex 简写（默认 true，@3f56927d 全量启用；
     *                         历史门控已经 Level-31 真值护栏 + basis=0% 规范保真治本）
     * @return array 展开后的声明数组（原始简写保留，longhand 追加）
     */
    public static function expandAll(array $raw, bool $expandFlex = true): array
    {
        // 1. padding / margin → 4 方向
        $raw = self::expandBoxModel($raw);

        // 2. border-width / border-color / border-style → 4 方向
        $raw = self::expandBorderBox($raw);

        // 3. overflow → overflow-x + overflow-y
        $raw = self::expandOverflow($raw);

        // 4. gap → row-gap + column-gap
        $raw = self::expandGap($raw);

        // 5. place-items / place-content / place-self
        $raw = self::expandPlace($raw);

        // 6. inset → top + right + bottom + left
        $raw = self::expandInset($raw);

        // 7. flex → flex-grow + flex-shrink + flex-basis (gated)
        if ($expandFlex) {
            $raw = self::expandFlex($raw);
        }

        // 8. background → color/image/position/size/repeat/attachment
        $raw = self::expandBackground($raw);

        // 9. text-decoration → line/style/color/thickness
        $raw = self::expandTextDecoration($raw);

        return $raw;
    }

    // ──────────────────────────────────────────────────────────────
    // padding / margin (1-4 value)
    // ──────────────────────────────────────────────────────────────
    private static function expandBoxModel(array $raw): array
    {
        foreach (['padding', 'margin'] as $prop) {
            if (!isset($raw[$prop]) || $raw[$prop] === '') continue;
            // margin 含 auto 不展开——margin:auto 有独立的特殊处理路径（StyleResolver marginAutoFlags）
            if ($prop === 'margin' && stripos($raw[$prop], 'auto') !== false) continue;
            $parts = preg_split('/\s+/', trim($raw[$prop]));
            $count = count($parts);
            if ($count === 0) continue;
            $t = $parts[0];
            $r = $parts[1] ?? $t;
            $b = $parts[2] ?? $t;
            $l = $parts[3] ?? $r;
            // 保留原始单位（不强制 px）
            if (!isset($raw[$prop . '-top']))    $raw[$prop . '-top'] = $t;
            if (!isset($raw[$prop . '-right']))  $raw[$prop . '-right'] = $r;
            if (!isset($raw[$prop . '-bottom'])) $raw[$prop . '-bottom'] = $b;
            if (!isset($raw[$prop . '-left']))   $raw[$prop . '-left'] = $l;
        }
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────
    // border-width / border-color / border-style (1-4 value)
    // ──────────────────────────────────────────────────────────────
    private static function expandBorderBox(array $raw): array
    {
        $map = [
            'border-width' => ['border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width'],
            'border-color' => ['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color'],
            'border-style' => ['border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style'],
        ];
        foreach ($map as $shorthand => $longhands) {
            if (!isset($raw[$shorthand]) || $raw[$shorthand] === '') continue;
            $parts = preg_split('/\s+/', trim($raw[$shorthand]));
            $count = count($parts);
            if ($count < 1) continue;
            $t = $parts[0];
            $r = $parts[1] ?? $t;
            $b = $parts[2] ?? $t;
            $l = $parts[3] ?? $r;
            $vals = [$t, $r, $b, $l];
            for ($i = 0; $i < 4; $i++) {
                if (!isset($raw[$longhands[$i]])) $raw[$longhands[$i]] = $vals[$i];
            }
        }
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────
    // overflow → overflow-x + overflow-y (CSS Overflow Module Level 3 §3)
    // ──────────────────────────────────────────────────────────────
    private static function expandOverflow(array $raw): array
    {
        if (!isset($raw['overflow']) || $raw['overflow'] === '') return $raw;
        $parts = preg_split('/\s+/', trim($raw['overflow']));
        // 仅 2 值时展开（单值由 ComputedStyle 内部 CSS-Overflow-3 §3.3 混合规则处理）
        if (count($parts) === 2) {
            if (!isset($raw['overflow-x'])) $raw['overflow-x'] = $parts[0];
            if (!isset($raw['overflow-y'])) $raw['overflow-y'] = $parts[1];
        }
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────
    // gap → row-gap + column-gap (CSS Box Alignment §8)
    // ──────────────────────────────────────────────────────────────
    private static function expandGap(array $raw): array
    {
        if (!isset($raw['gap']) || $raw['gap'] === '') return $raw;
        $parts = preg_split('/\s+/', trim($raw['gap']));
        if (count($parts) === 2) {
            if (!isset($raw['row-gap']))    $raw['row-gap'] = $parts[0];
            if (!isset($raw['column-gap'])) $raw['column-gap'] = $parts[1];
        }
        // 单值 gap 保留原样（row-gap = column-gap = gap 值，由 PROPERTY_MAP 直接映射）
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────
    // place-items / place-content / place-self (CSS Box Alignment §6)
    // ──────────────────────────────────────────────────────────────
    private static function expandPlace(array $raw): array
    {
        $placeMap = [
            'place-items'   => ['align-items', 'justify-items'],
            'place-content' => ['align-content', 'justify-content'],
            'place-self'    => ['align-self', 'justify-self'],
        ];
        foreach ($placeMap as $shorthand => $longhands) {
            if (!isset($raw[$shorthand]) || $raw[$shorthand] === '') continue;
            $parts = preg_split('/\s+/', trim($raw[$shorthand]));
            $block = $parts[0];
            $inline = $parts[1] ?? $block;
            if (!isset($raw[$longhands[0]])) $raw[$longhands[0]] = $block;
            if (!isset($raw[$longhands[1]])) $raw[$longhands[1]] = $inline;
        }
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────
    // inset → top + right + bottom + left (CSS Logical Properties)
    // ──────────────────────────────────────────────────────────────
    private static function expandInset(array $raw): array
    {
        if (!isset($raw['inset']) || $raw['inset'] === '') return $raw;
        $parts = preg_split('/\s+/', trim($raw['inset']));
        $count = count($parts);
        $t = $parts[0];
        $r = $parts[1] ?? $t;
        $b = $parts[2] ?? $t;
        $l = $parts[3] ?? $r;
        if (!isset($raw['top']))    $raw['top'] = $t;
        if (!isset($raw['right']))  $raw['right'] = $r;
        if (!isset($raw['bottom'])) $raw['bottom'] = $b;
        if (!isset($raw['left']))   $raw['left'] = $l;
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────
    // flex → flex-grow + flex-shrink + flex-basis (CSS Flexbox §7.1)
    // ⚠️ Gated: 仅在 expandAll($raw, expandFlex=true) 时激活
    // ──────────────────────────────────────────────────────────────
    private static function expandFlex(array $raw): array
    {
        if (!isset($raw['flex']) || $raw['flex'] === '') return $raw;
        $cf = CssFlex::fromString($raw['flex']);
        if (!isset($raw['flex-grow']))   $raw['flex-grow'] = (string)$cf->grow;
        if (!isset($raw['flex-shrink'])) $raw['flex-shrink'] = (string)$cf->shrink;
        if (!isset($raw['flex-basis'])) {
            // 序列化必须保真（CSS Flexbox §7.1.1）：auto/content/intrinsic 关键字不可塔陷为 0px，
            // percent（含 flex:1 的 0%）与长度在 indefinite 主轴下语义不同。
            if ($cf->basis->isAuto()) {
                $raw['flex-basis'] = 'auto';
            } else if ($cf->basis->isContent()) {
                $raw['flex-basis'] = 'content';
            } else if ($cf->basis->isIntrinsic()) {
                $raw['flex-basis'] = $cf->basis->unit;
            } else if ($cf->basis->isPercent()) {
                $raw['flex-basis'] = $cf->basis->value . '%';
            } else {
                $raw['flex-basis'] = ((int)$cf->basis->toPx()) . 'px';
            }
        }
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────────
    // background 简写展开（从 CssMappings::expandBackgroundShorthand 迁移）
    // ──────────────────────────────────────────────────────────────────
    private static function expandBackground(array $raw): array
    {
        if (!isset($raw['background']) || $raw['background'] === '') return $raw;
        $value = trim($raw['background']);
        // 单色值（hex/rgb/gradient/transparent/none）——不展开
        if (preg_match('/^#[\da-fA-F]{3,8}$/', $value) ||
            preg_match('/^rgba?\s*\([^)]*\)$/i', $value) ||
            preg_match('/^linear-gradient\s*\([^)]*\)$/i', $value) ||
            strtolower($value) === 'transparent' || strtolower($value) === 'none') {
            return $raw;
        }
        // 无 url() 且无 position/size 分隔符——非多值简写
        if (!preg_match('/url\s*\(/i', $value) && !str_contains($value, '/')) {
            return $raw;
        }
        $rest = $value;
        $bgColor = ''; $bgImage = ''; $bgPosition = ''; $bgSize = ''; $bgRepeat = '';
        // 提取颜色
        if (preg_match('/#[\da-fA-F]{3,8}\b/', $rest, $m)) { $bgColor = $m[0]; $rest = trim(str_replace($m[0], '', $rest)); }
        if (preg_match('/rgba?\s*\([^)]*\)/i', $rest, $m)) { $bgColor = $m[0]; $rest = trim(str_replace($m[0], '', $rest)); }
        if (preg_match('/linear-gradient\s*\([^)]*\)/i', $rest, $m)) { $bgColor = $m[0]; $rest = trim(str_replace($m[0], '', $rest)); }
        // 提取 url
        if (preg_match('/url\s*\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $rest, $m)) { $bgImage = trim($m[1]); $rest = trim(str_replace($m[0], '', $rest)); }
        // 提取 /size
        if (preg_match('#/\s*(\S+)#', $rest, $m)) { $bgSize = trim($m[1]); $rest = trim(str_replace($m[0], '', $rest)); }
        // 提取 repeat
        foreach (['repeat-x', 'repeat-y', 'no-repeat', 'repeat', 'space', 'round'] as $kw) {
            if (stripos($rest, $kw) !== false) { $bgRepeat = $kw; $rest = trim(str_ireplace($kw, '', $rest)); break; }
        }
        // 提取 attachment
        foreach (['scroll', 'fixed', 'local'] as $kw) {
            if (stripos($rest, $kw) !== false) { $rest = trim(str_ireplace($kw, '', $rest)); break; }
        }
        $rest = trim(preg_replace('/\s+/', ' ', $rest));
        if ($bgSize !== '' && $rest !== '') { $bgPosition = $rest; }
        // 写入子属性
        if ($bgImage !== '' && !isset($raw['background-image'])) $raw['background-image'] = 'url("' . $bgImage . '")';
        if ($bgPosition !== '' && !isset($raw['background-position'])) $raw['background-position'] = $bgPosition;
        if ($bgSize !== '' && !isset($raw['background-size'])) $raw['background-size'] = $bgSize;
        if ($bgRepeat !== '' && !isset($raw['background-repeat'])) $raw['background-repeat'] = $bgRepeat;
        if ($bgColor !== '') $raw['background'] = $bgColor;
        else $raw['background'] = 'transparent';
        return $raw;
    }

    // ──────────────────────────────────────────────────────────────────
    // text-decoration 简写展开（从 CssMappings::expandTextDecorationShorthand 迁移）
    // ──────────────────────────────────────────────────────────────────
    public static function expandTextDecoration(array $raw): array
    {
        $tdVal = $raw['text-decoration'] ?? $raw['textDecoration'] ?? null;
        if ($tdVal === null || $tdVal === '') return $raw;
        $parts = preg_split('/\s+/', trim((string)$tdVal));
        $lineParts = []; $hasLine = false;
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') continue;
            $lower = strtolower($part);
            if (in_array($lower, ['underline', 'overline', 'line-through', 'none', 'blink'], true)) { $lineParts[] = $lower; $hasLine = true; continue; }
            if (in_array($lower, ['solid', 'double', 'dotted', 'dashed', 'wavy'], true)) { $result['textDecorationStyle'] = $lower; continue; }
            if (str_starts_with($part, '#') || preg_match('/^rgba?\s*\(/i', $part)) { $result['textDecorationColor'] = $part; continue; }
            if (preg_match('/^\d+(\.\d+)?(px)?$/', $part)) { $result['textDecorationThickness'] = $part; continue; }
        }
        if ($hasLine) $result['textDecorationLine'] = implode(' ', $lineParts);
        foreach ($result as $key => $val) {
            $cssKey = strtolower(preg_replace('/([A-Z])/', '-$1', $key));
            // 仅写 kebab：运行时 PROPERTY_MAP dispatch 唯一入口（parseHexColor→BGR int 等）；
            // SFC 编译期由 canonicalStyleKey 统一转 camel。此前双写 camel 键会绕过
            // dispatch 以字符串覆盖解析产物（decorationColor 丢失 BGR 转换的根因）。
            if (!isset($raw[$cssKey])) $raw[$cssKey] = $val;
        }
        return $raw;
    }
}
