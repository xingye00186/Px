<?php
$f = 'f:/work/Px/framework/Rendering/Layout/OOFLayoutAlgorithm.php';
$c = file_get_contents($f);

// Replace the old calculateOOFPosition with inlined logic
$oldPos = strpos($c, 'private function calculateOOFPosition');
if ($oldPos === false) { echo "ERROR: calculateOOFPosition not found\n"; exit(1); }

// Find the closing brace of the method
// The method starts with "private function calculateOOFPosition" and ends with "}" at the class level
$braceDepth = 0;
$start = $oldPos;
$inMethod = false;
$closingPos = 0;
for ($i = $oldPos; $i < strlen($c); $i++) {
    if ($c[$i] === '{') { $braceDepth++; $inMethod = true; }
    if ($c[$i] === '}') { $braceDepth--; }
    if ($inMethod && $braceDepth === 0) { $closingPos = $i; break; }
}

$newMethod = "    private function calculateOOFPosition(
        PhysicalFragment \$frag,
        RenderNode \$sourceRN,
        int \$ancestorX,
        int \$ancestorY,
        int \$ancestorW,
        int \$ancestorH,
        int \$ancestorBorderLeft,
        int \$ancestorBorderTop,
        int \$ancestorPaddingLeft,
        int \$ancestorPaddingTop,
        int \$viewportW,
        int \$viewportH,
    ): PhysicalFragment {
        \$cs = \$frag->style;
        if (\$cs === null) return \$frag;

        // ── Absolute positioning (inlined from AbsolutePositioning::absoluteLayout) ──
        \$leftVal = (int)(\$cs->left?->toPx() ?? 0);
        \$topVal = (int)(\$cs->top?->toPx() ?? 0);
        \$rightVal = (int)(\$cs->right?->toPx() ?? 0);
        \$bottomVal = (int)(\$cs->bottom?->toPx() ?? 0);

        \$ancW = \$ancestorW;
        \$ancH = \$ancestorH;
        \$ancX = \$ancestorX;
        \$ancY = \$ancestorY;
        \$bL = \$ancestorBorderLeft;
        \$bT = \$ancestorBorderTop;

        \$width = (int)(\$cs->width?->toPx() ?? 0);
        \$height = (int)(\$cs->height?->toPx() ?? 0);
        if (\$cs->width !== null && \$cs->width->isPercent()) \$width = \$cs->width->resolveInContext(\$ancW);
        if (\$cs->height !== null && \$cs->height->isPercent()) \$height = \$cs->height->resolveInContext(\$ancH);

        \$marginLeft = (int)(\$cs->margin?->left->toPx() ?? 0);
        \$marginTop = (int)(\$cs->margin?->top->toPx() ?? 0);
        \$marginRight = (int)(\$cs->margin?->right->toPx() ?? 0);
        \$marginBottom = (int)(\$cs->margin?->bottom->toPx() ?? 0);

        if (\$leftVal !== 0 && \$rightVal !== 0 && \$width <= 0) {
            \$width = max(0, \$ancW - \$leftVal - \$rightVal - \$marginLeft - \$marginRight);
        }
        if (\$topVal !== 0 && \$bottomVal !== 0 && \$height <= 0) {
            \$height = max(0, \$ancH - \$topVal - \$bottomVal - \$marginTop - \$marginBottom);
        }

        \$textContent = is_string(\$sourceRN->content) ? \$sourceRN->content : '';
        if ((\$width <= 0 || \$height <= 0) && strlen(\$textContent) > 0) {
            \$fs = (int)(\$cs->fontSize ?? 14);
            \$bd = (int)(\$cs->bold ?? 0);
            \$measured = (function_exists('sk_measure_text_width') ? (int)\sk_measure_text_width(\$textContent, \$fs, \$bd) : 0);
            if (\$measured > 0 && \$width <= 0) {
                \$width = max(0, \$measured + (int)(\$cs->padding?->left->toPx() ?? 0) + (int)(\$cs->padding?->right->toPx() ?? 0) + (int)(\$cs->borderLeftWidth ?? 0) + (int)(\$cs->borderRightWidth ?? 0));
            }
            if (\$height <= 0) {
                \$height = max((int)(\$cs->lineHeight ?? (int)(\$fs * 1.2)), \$height);
            }
        }

        \$hasLeft = (\$cs->getRaw('left') !== null);
        \$hasRight = (\$cs->getRaw('right') !== null);
        \$hasTop = (\$cs->getRaw('top') !== null);
        \$hasBottom = (\$cs->getRaw('bottom') !== null);

        \$cbOriginX = \$ancX + \$bL;
        \$cbOriginY = \$ancY + \$bT;

        \$calcX = \$cbOriginX + \$leftVal + \$marginLeft;
        if (\$hasRight && !\$hasLeft) {
            \$calcX = \$cbOriginX + \$ancW - \$rightVal - (\$width > 0 ? \$width : 0) - \$marginRight;
        }

        \$calcY = \$cbOriginY + \$topVal + \$marginTop;
        if (\$hasBottom && !\$hasTop) {
            \$calcY = \$cbOriginY + \$ancH - \$bottomVal - (\$height > 0 ? \$height : 0) - \$marginBottom;
        }

        \$rawTX = \$cs->getRaw('translateX');
        \$rawTY = \$cs->getRaw('translateY');
        \$calcX += \$rawTX instanceof CssLength ? \$rawTX->toPx() : (int)(\$rawTX ?? 0);
        \$calcY += \$rawTY instanceof CssLength ? \$rawTY->toPx() : (int)(\$rawTY ?? 0);

        // Margin auto (simplified: only X-axis)
        \$autoOffsetX = 0;
        if (\$cs->margin !== null) {
            \$mLAuto = \$cs->margin->left->isAuto();
            \$mRAuto = \$cs->margin->right->isAuto();
            if (\$mLAuto && \$mRAuto) {
                \$autoOffsetX = (int)((\$ancW - \$width) / 2);
            } elseif (\$mRAuto) {
                \$autoOffsetX = \$ancW - \$calcX - \$width + \$ancX;
            }
        }
        \$calcX += \$autoOffsetX;

        return new PhysicalFragment(
            (int)\$calcX, (int)\$calcY, (int)max(0, \$width), (int)max(0, \$height),
            (int)\$cs->visualWidth(\$width), (int)\$cs->visualHeight(\$height),
            1, 0, 0, \$cs, \$frag->children, \$sourceRN
        );
    }";

// Replace old method body
$oldMethodBody = substr($c, $oldPos, $closingPos - $oldPos + 1);
if (strlen($oldMethodBody) < 50) { echo "ERROR: old method too short\n"; exit(1); }

$c = str_replace($oldMethodBody, $newMethod, $c);

// Remove unused imports
$c = str_replace("use Px\Rendering\Layout\AbsolutePositioning;\n", "", $c);
$c = str_replace("use Px\Rendering\ComputedStyle;\nuse Px\Rendering\RenderNode;", "use Px\Rendering\ComputedStyle;\nuse Px\Rendering\CssLength;\nuse Px\Rendering\RenderNode;", $c);

file_put_contents($f, $c);
echo "Done\n";
