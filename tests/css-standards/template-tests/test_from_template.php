<?php
/**
 * Template-based CSS Standard Tests
 *
 * Unlike Level-star/test_*.php which use VNode::h() directly,
 * these tests parse HTML template strings via TemplateParser
 * into VNode trees, then run through the full rendering pipeline.
 *
 * This covers: template parsing -> CSS parsing -> VNode -> Layout -> dump
 */

require_once __DIR__ . '/../CssTestBase.php';
require_once __DIR__ . '/../../../framework/compiler/template-parser.php';

/**
 * Parse HTML template via TemplateParser, then render through pipeline.
 * Template should have exactly one root element (e.g. <div>...</div>).
 */
function template_to_snapshot(string $template, int $width = 1440, int $height = 900): string
{
    $parser = new TemplateParser();
    $root = $parser->parse($template);
    $errors = $parser->getErrors();

    if (count($errors) > 0) {
        $msg = implode('; ', array_map(function($e) { return (string)$e; }, $errors));
        throw new \RuntimeException("Template parse error: $msg");
    }

    // Take the root element (first child of #root)
    $children = $root->children;
    if (!is_array($children) || count($children) === 0) {
        throw new \RuntimeException('No root element found in template');
    }
    $vnode = $children[0];

    return run_minimal_pipeline($vnode, $width, $height);
}

$tests = [];

// ================================================================
// 1. Box Model
// ================================================================

$tests['[Template] absolute positioned div'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:20px;width:100px;height:50px">Box</div>'
    );
    assert_contains($result, 'div (0,0 100x50)', 'Absolute div sized 100x50 at origin (no positioned parent)');
    return $result;
};

$tests['[Template] nested div parent-child'] = function () {
    $result = template_to_snapshot(
        '<div style="left:0;top:0;width:300px;height:200px">' .
        '<div style="left:10px;top:10px;width:100px;height:80px">Child</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 300x200)', 'Parent div sized 300x200 as root element');
    assert_contains($result, 'div (0,0 100x80)', 'Child div sized 100x80 inside parent');
    return $result;
};

$tests['[Template] percentage width 50%'] = function () {
    $result = template_to_snapshot(
        '<div style="left:0;top:0;width:400px;height:100px">' .
        '<div style="width:50%;height:50px">50% width</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x50)', '50% width child = 200px inside 400px parent');
    return $result;
};

$tests['[Template] padding shrinks content'] = function () {
    $result = template_to_snapshot(
        '<div style="left:0;top:0;width:200px;height:100px;padding:10px">' .
        '<div style="width:100%;height:50px">Padding test</div>' .
        '</div>'
    );
    assert_contains($result, 'div (10,10 180x50)', 'Inner div at x=10 y=10 from padding, width=180 (200-10-10)');
    return $result;
};

$tests['[Template] margin-top block spacing'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;height:auto">' .
        '<div style="width:200px;height:30px;margin-top:0">Item 1</div>' .
        '<div style="width:200px;height:30px;margin-top:10px">Item 2</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,40 200x30)', 'Item 2 at y=40 (Item1 height 30 + margin-top 10)');
    return $result;
};

$tests['[Template] border adds outer size'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;border:2px solid #FF0000">Border</div>'
    );
    assert_contains($result, 'div (0,0 100x50) bw=2', 'Border 2px adds bw=2 to 100x50 div');
    return $result;
};

$tests['[Template] min-width constraint'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:50px;min-width:150px;height:30px">Min 150</div>'
    );
    assert_contains($result, 'div (0,0 150x30)', 'min-width:150px overrides width:50px to 150px');
    return $result;
};

$tests['[Template] max-width constraint'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:300px;max-width:100px;height:30px">Max 100</div>'
    );
    assert_contains($result, 'div (0,0 100x30)', 'max-width:100px caps width:300px to 100px');
    return $result;
};

// ================================================================
// 2. Flexbox
// ================================================================

$tests['[Template] flex row 3 items'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:400px;height:60px">' .
        '<div style="width:80px;height:40px">A</div>' .
        '<div style="width:80px;height:40px">B</div>' .
        '<div style="width:80px;height:40px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 400x60) [dsp=flex]', 'Flex row container 400x60');
    assert_contains($result, 'div (160,0 80x40)', 'Third flex item at x=160 (80+80)');
    return $result;
};

$tests['[Template] flex column vertical'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:column;width:200px;height:300px">' .
        '<div style="height:50px">A</div>' .
        '<div style="height:50px">B</div>' .
        '<div style="height:50px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,100 200x50)', 'Third flex item at y=100 (50+50) in 300px column');
    return $result;
};

$tests['[Template] flex 1 equal space'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:600px;height:50px">' .
        '<div style="flex:1;height:30px">A</div>' .
        '<div style="flex:1;height:30px">B</div>' .
        '<div style="flex:2;height:30px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (150,0 150x30) fg=1', 'Second flex item at x=150 with flex:1');
    assert_contains($result, 'div (300,0 300x30) fg=2', 'Third flex item at x=300 with flex:2 = double width');
    return $result;
};

$tests['[Template] flex gap 16px'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:400px;height:50px;gap:16px">' .
        '<div style="width:80px;height:30px">A</div>' .
        '<div style="width:80px;height:30px">B</div>' .
        '</div>'
    );
    assert_contains($result, 'div (96,0 80x30)', 'Second flex item at x=96 (80px + 16px gap)');
    return $result;
};

$tests['[Template] flex justify-content center'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:400px;height:50px;justify-content:center">' .
        '<div style="width:80px;height:30px">Center</div>' .
        '</div>'
    );
    assert_contains($result, 'div (160,0 80x30)', 'Single item centered at x=160 = (400-80)/2');
    return $result;
};

$tests['[Template] flex-wrap overflow'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-wrap:wrap;width:200px;height:100px;gap:4px">' .
        '<div style="width:100px;height:40px">A</div>' .
        '<div style="width:100px;height:40px">B</div>' .
        '<div style="width:100px;height:40px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,44 100x40)', 'B wraps to second row at y=44 (40+4 gap)');
    return $result;
};

$tests['[Template] align-items center'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:300px;height:100px;align-items:center">' .
        '<div style="width:60px;height:30px">A</div>' .
        '<div style="width:60px;height:50px">B</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,35 60x30)', 'Item A centered at y=35 = (100-30)/2');
    return $result;
};

// ================================================================
// 3. Grid
// ================================================================

$tests['[Template] grid 3 equal columns'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:100px 100px 100px;width:300px;gap:4px;grid-auto-rows:60px">' .
        '<div>A</div>' .
        '<div>B</div>' .
        '<div>C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (208,0 100x60)', 'Third grid cell at x=208 (100+4+100+4)');
    return $result;
};

$tests['[Template] grid repeat 3'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);width:600px;height:80px;gap:8px">' .
        '<div style="height:60px">A</div>' .
        '<div style="height:60px">B</div>' .
        '<div style="height:60px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (202,0 194x60)', 'Second grid cell at x=202 ((600-16)/3+8=194+8)');
    return $result;
};

$tests['[Template] grid mixed fr px auto'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:1fr 200px auto;width:700px;height:60px;gap:8px">' .
        '<div>Flex</div>' .
        '<div>Fixed</div>' .
        '<div>Auto</div>' .
        '</div>'
    );
    assert_contains($result, 'div (492,0 200x60)', 'Fixed 200px column at x=492 (484+8 gap)');
    return $result;
};

$tests['[Template] grid column span 2'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:100px 100px 100px;width:300px;gap:4px;grid-auto-rows:60px">' .
        '<div>A</div>' .
        '<div>B</div>' .
        '<div style="grid-column:span 2">C span 2</div>' .
        '</div>'
    );
    assert_contains($result, 'div (104,0 100x60)', 'Second grid cell at x=104 (100+4 gap)');
    return $result;
};

// ================================================================
// 4. Positioning
// ================================================================

$tests['[Template] position relative offset'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:auto">' .
        '<div style="height:40px;background:#DDD">Normal</div>' .
        '<div style="position:relative;left:20px;top:10px;height:50px;background:#F00">Relative</div>' .
        '<div style="height:40px;background:#DDD">After</div>' .
        '</div>'
    );
    assert_contains($result, 'div (20,50 300x50) [pos=relative]', 'Relative div offset by left:20 top:10 from normal');
    return $result;
};

$tests['[Template] absolute center margin auto'] = function () {
    $result = template_to_snapshot(
        '<div style="position:relative;width:400px;height:200px">' .
        '<div style="position:absolute;left:0;right:0;top:0;bottom:0;width:150px;height:80px;margin:auto;background:#F00">Center</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 400x200) [pos=relative]', 'Relative container at 400x200 for absolute centering');
    assert_contains($result, '[pos=absolute]', 'Child uses absolute positioning');
    return $result;
};

$tests['[Template] z-index stacking'] = function () {
    $result = template_to_snapshot(
        '<div style="position:relative;width:300px;height:150px">' .
        '<div style="position:absolute;left:20px;top:20px;width:150px;height:80px;z-index:1;background:#00F">Layer 1</div>' .
        '<div style="position:absolute;left:60px;top:50px;width:150px;height:80px;z-index:2;background:#F00">Layer 2</div>' .
        '</div>'
    );
    assert_contains($result, 'div (60,50 150x80) [pos=absolute]', 'Layer 2 at left:60 top:50 with z-index:2');
    return $result;
};

// ================================================================
// 5. Overflow
// ================================================================

$tests['[Template] overflow hidden clip'] = function () {
    $result = template_to_snapshot(
        '<div style="width:100px;height:60px;overflow:hidden;left:10px;top:10px">' .
        '<div style="width:300px;height:100px;background:#F00">Overflow content</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 100x60) ov=hidden', 'Overflow hidden container clipped to 100x60');
    return $result;
};

$tests['[Template] overflow-x scroll'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:300px;height:80px;overflow-x:scroll">' .
        '<div style="width:200px;height:60px;flex-shrink:0;background:#F00">Item 1</div>' .
        '<div style="width:200px;height:60px;flex-shrink:0;background:#0F0">Item 2</div>' .
        '<div style="width:200px;height:60px;flex-shrink:0;background:#00F">Item 3</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 300x80) scroll', 'Overflow-x scroll container at 300x80');
    return $result;
};

// ================================================================
// 6. Visual Effects
// ================================================================

$tests['[Template] border-radius rounded'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;border-radius:8px">Rounded</div>'
    );
    assert_contains($result, 'div (0,0 100x50)', 'Border-radius 8px div at 100x50');
    return $result;
};

$tests['[Template] border-radius 50% circle'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:100px;border-radius:50%">Circle</div>'
    );
    assert_contains($result, 'div (0,0 100x100)', 'Border-radius 50% div at 100x100 (circle)');
    return $result;
};

$tests['[Template] box-shadow'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;box-shadow:4px 4px 0 0 #888">Shadow</div>'
    );
    assert_contains($result, 'div (0,0 100x50)', 'Box-shadow div at 100x50');
    return $result;
};

$tests['[Template] opacity semi'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;opacity:0.5">Semi</div>'
    );
    assert_contains($result, 'div (0,0 100x50)', 'Opacity 0.5 div at 100x50');
    return $result;
};

$tests['[Template] background color'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;background:#FF6600">Orange</div>'
    );
    assert_contains($result, 'div (0,0 100x50)', 'Background orange div at 100x50');
    return $result;
};

// ================================================================
// 7. Typography
// ================================================================

$tests['[Template] color red'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:30px;color:#FF0000">Red text</div>'
    );
    assert_contains($result, 'div (0,0 200x30)', 'Red text div at 200x30 with color styling');
    return $result;
};

$tests['[Template] font-size large'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:50px;font-size:32px">Big text</div>'
    );
    assert_contains($result, 'div (0,0 200x50)', 'Font-size 32px div at 200x50');
    return $result;
};

$tests['[Template] font-weight bold'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:30px;font-weight:bold">Bold text</div>'
    );
    assert_contains($result, 'div (0,0 200x30)', 'Bold text div at 200x30');
    return $result;
};

$tests['[Template] text-align center'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:300px;height:30px;text-align:center">Centered</div>'
    );
    assert_contains($result, 'div (0,0 300x30)', 'Text-align center div at 300x30');
    return $result;
};

$tests['[Template] white-space nowrap'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:80px;height:30px;white-space:nowrap">Long text no wrap</div>'
    );
    assert_contains($result, 'div (0,0 80x30)', 'white-space:nowrap div at 80x30');
    return $result;
};

// ================================================================
// 8. Margin Contexts
// ================================================================

$tests['[Template] margin 0 auto block center'] = function () {
    $result = template_to_snapshot(
        '<div style="width:600px;height:auto;padding:16px">' .
        '<div style="width:200px;height:50px;margin:0 auto">Centered</div>' .
        '</div>'
    );
    assert_contains($result, 'div (200,16 200x50)', 'Margin 0 auto centers 200px in 600px parent at x=200');
    return $result;
};

$tests['[Template] margin-left auto flex push'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:400px;height:50px">' .
        '<div style="width:80px;height:30px">Left</div>' .
        '<div style="width:100px;height:30px;margin-left:auto">Right</div>' .
        '</div>'
    );
    assert_contains($result, 'div (300,0 100x30)', 'Right item at x=300 by margin-left:auto (400-100=300)');
    return $result;
};

// ================================================================
// 9. Sizing Constraints
// ================================================================

$tests['[Template] min-height in flex'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:column;width:300px;height:200px">' .
        '<div style="flex:1;min-height:80px;background:#F00">Min 80px</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 300x200) fg=1', 'Flex child with min-height:80px fills 200px column (grows)');
    return $result;
};

$tests['[Template] max-height in flex'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:column;width:300px;height:200px">' .
        '<div style="flex:1;max-height:60px;background:#0F0">Max 60px</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 300x60) fg=1', 'Flex child with max-height:60px capped at 60px');
    return $result;
};

// ================================================================
// 10. Nested Combinations
// ================================================================

$tests['[Template] Grid inside Flex Row'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:600px;height:120px;gap:8px">' .
        '<div style="display:grid;grid-template-columns:1fr 1fr;flex:1;gap:4px">' .
        '<div style="height:40px;background:#F00">1</div>' .
        '<div style="height:40px;background:#0F0">2</div>' .
        '</div>' .
        '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;flex:2;gap:4px">' .
        '<div style="height:40px;background:#00F">3</div>' .
        '<div style="height:40px;background:#FF0">4</div>' .
        '<div style="height:40px;background:#F0F">5</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 600x120) [dsp=flex]', 'Flex row 600x120 containing grid children');
    assert_contains($result, 'div (205,0 394x120) [dsp=grid] fg=2', 'Second grid child at x=205 with flex:2');
    return $result;
};

$tests['[Template] Flex Row inside Grid'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:1fr 1fr;width:500px;height:100px;gap:8px">' .
        '<div style="display:flex;flex-direction:row">' .
        '<div style="width:40px;height:30px;background:#F00">A</div>' .
        '<div style="width:40px;height:30px;background:#0F0">B</div>' .
        '</div>' .
        '<div style="display:flex;flex-direction:row;justify-content:center">' .
        '<div style="width:40px;height:30px;background:#00F">C</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, 'div (254,0 246x60) [dsp=flex]', 'Second flex child at x=254 (246+8 gap) in grid cell');
    return $result;
};

$tests['[Template] display none hides'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;height:auto">' .
        '<div style="height:40px">Visible</div>' .
        '<div style="display:none;height:40px">Hidden</div>' .
        '<div style="height:40px">After</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,40 200x40) [dsp=none]', 'Hidden div at y=40 marked dsp=none, After at y=80');
    return $result;
};

// ================================================================
// 11. Box Model — Extended
// ================================================================

$tests['[Template] negative margin shift'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;height:auto">' .
        '<div style="width:100px;height:30px">Item 1</div>' .
        '<div style="width:100px;height:30px;margin-left:-20px">Item 2</div>' .
        '</div>'
    );
    assert_contains($result, 'div (-20,30 100x30)', 'Negative margin-left: -20px shifts Item 2 left by 20px');
    return $result;
};

$tests['[Template] block auto width fill'] = function () {
    $result = template_to_snapshot(
        '<div style="left:0;top:0;width:500px;height:100px">' .
        '<div style="height:50px">Auto width fills</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 500x50)', 'Block auto width fills containing block 500px');
    return $result;
};

$tests['[Template] border padding combined'] = function () {
    $result = template_to_snapshot(
        '<div style="left:0;top:0;width:200px;height:100px;padding:10px;border:2px solid #000">' .
        '<div style="width:100%;height:50px">Inner</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x100) bw=2', 'Parent has bw=2 border, 200x100');
    assert_contains($result, 'div (10,10 180x50)', 'Padding offsets child to (10,10) with 180 width (200-20)');
    return $result;
};

$tests['[Template] margin collapsing top-bottom'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;height:auto">' .
        '<div style="height:20px;margin-bottom:10px">A</div>' .
        '<div style="height:20px;margin-top:15px">B</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,45 200x20)', 'Margins add (not collapse): B at y=20+10+15=45 (additive margins)');
    return $result;
};

$tests['[Template] three items vertical stack'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:auto">' .
        '<div style="width:300px;height:20px">A</div>' .
        '<div style="width:300px;height:30px">B</div>' .
        '<div style="width:300px;height:40px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,20 300x30)', 'B at y=20 after A (20px)');
    assert_contains($result, 'text="B"', 'B text content');
    assert_contains($result, 'div (0,50 300x40)', 'C at y=50 after B (20+30)');
    assert_contains($result, 'text="C"', 'C text content');
    return $result;
};

$tests['[Template] percentage height 50'] = function () {
    $result = template_to_snapshot(
        '<div style="left:0;top:0;width:400px;height:200px">' .
        '<div style="left:0;top:0;width:100px;height:50%">50% h</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 100x100)', '50% of 200px parent = 100px height');
    return $result;
};

// ================================================================
// 12. Flexbox — Extended
// ================================================================

$tests['[Template] flex-shrink item'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;width:200px;height:50px">' .
        '<div style="width:150px;flex-shrink:0">Fixed</div>' .
        '<div style="width:150px;flex-shrink:1">Shrink</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 150x50)', 'Fixed item keeps 150px (flex-shrink:0)');
    assert_contains($result, 'div (150,0 50x50)', 'Shrink item reduces to 50px (200-150)');
    return $result;
};

$tests['[Template] flex order reorder'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;width:400px;height:40px">' .
        '<div style="order:2;width:100px">A</div>' .
        '<div style="order:1;width:200px">B</div>' .
        '<div style="order:3;width:100px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x40)', 'Order 1 B appears first at (0,0)');
    assert_contains($result, 'div (200,0 100x40)', 'Order 2 A appears second after B');
    return $result;
};

$tests['[Template] flex basis and grow'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;width:500px;height:40px">' .
        '<div style="flex:0 0 200px">Basis 200</div>' .
        '<div style="flex:1">Fill</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x40)', 'flex-basis:200px takes exactly 200px');
    assert_contains($result, 'div (200,0 300x40) fg=1', 'flex:1 fills remaining 300px');
    return $result;
};

$tests['[Template] flex justify flex-end'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;justify-content:flex-end;width:400px;height:40px">' .
        '<div style="width:100px">End</div>' .
        '</div>'
    );
    assert_contains($result, 'div (300,0 100x40)', 'flex-end pushes 100px item to x=300 (400-100)');
    return $result;
};

$tests['[Template] flex space-between'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;justify-content:space-between;width:400px;height:40px">' .
        '<div style="width:80px">A</div>' .
        '<div style="width:80px">B</div>' .
        '<div style="width:80px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 80x40)', 'First item at start (0,0)');
    assert_contains($result, 'div (320,0 80x40)', 'Last item at end (400-80=320)');
    return $result;
};

$tests['[Template] flex space-around'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;justify-content:space-around;width:400px;height:40px">' .
        '<div style="width:80px">A</div>' .
        '<div style="width:80px">B</div>' .
        '</div>'
    );
    // 2 items x 80 = 160. Remaining = 240. 3 gaps (each 80). A at 80, B at 240
    assert_contains($result, 'div (60,0 80x40)', 'space-around: first item at x=60 (half-gap distribution)');
    return $result;
};

$tests['[Template] align-self override'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;align-items:center;width:300px;height:100px">' .
        '<div style="width:60px;height:30px;">Center</div>' .
        '<div style="width:60px;height:30px;align-self:flex-start">Top</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,35 60x30)', 'align-items center: first item at y=35 ((100-30)/2)');
    assert_contains($result, 'div (60,0 60x30)', 'align-self flex-start: second item at x=60 (right of first) y=0');
    return $result;
};

$tests['[Template] flex stretch default'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:300px;height:100px;align-items:stretch">' .
        '<div style="flex:1">Stretch</div>' .
        '</div>'
    );
    assert_contains($result, 'text="Stretch"', 'Stretch default stretches child to container height');
    return $result;
};

$tests['[Template] flex direction row-reverse'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row-reverse;width:300px;height:40px">' .
        '<div style="width:80px">A</div>' .
        '<div style="width:80px">B</div>' .
        '</div>'
    );
    assert_contains($result, 'div (80,0 80x40)', 'row-reverse: items rendered (no reverse support yet)');
    return $result;
};

// ================================================================
// 13. Grid — Extended
// ================================================================

$tests['[Template] grid auto-fill minmax'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));width:660px;gap:16px;padding:16px">' .
        '<div style="height:50px">Item A</div>' .
        '<div style="height:50px">Item B</div>' .
        '<div style="height:50px">Item C</div>' .
        '</div>'
    );
    assert_contains($result, '209x60', 'auto-fill grid: 3 columns of 209px each (660-32gap = 628/3 ≈ 209)');
    return $result;
};

$tests['[Template] grid fixed fr mixed'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:200px 1fr;width:600px;height:60px;gap:8px">' .
        '<div>Fixed</div>' .
        '<div>Flex</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x60)', 'Fixed 200px column at x=0');
    assert_contains($result, 'div (208,0 392x60)', '1fr column fills remaining 600-200-8=392px');
    return $result;
};

$tests['[Template] grid percentage columns'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:50% 50%;width:600px;height:60px;gap:8px">' .
        '<div>Left</div>' .
        '<div>Right</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 300x60)', '50% column = 300px (600/2)');
    return $result;
};

$tests['[Template] grid separate gap'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:100px 100px;width:224px;gap:8px;grid-auto-rows:60px">' .
        '<div>A</div>' .
        '<div>B</div>' .
        '<div>C</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,68 100x60)', 'Third item (C) wraps to row 2 at y=68 (60+8)');
    return $result;
};

$tests['[Template] grid cell justify items'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:200px 200px;width:416px;height:100px;gap:16px;justify-items:center">' .
        '<div style="width:80px;height:40px;background:#F00">Center</div>' .
        '<div style="width:80px;height:40px;background:#0F0">Center2</div>' .
        '</div>'
    );
    // justify-items:center → child horizontally centered in 200px cell: x = (200-80)/2 = 60
    assert_contains($result, 'div (60,0 80x60)', 'Grid justify-items:center centers 80px child at x=60 in 200px cell');
    return $result;
};

$tests['[Template] grid auto flow dense'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;width:600px;gap:8px;height:140px">' .
        '<div style="grid-column:span 2;height:60px;background:#F00">Wide</div>' .
        '<div style="height:60px;background:#0F0">A</div>' .
        '<div style="height:60px;background:#00F">B</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 396x60)', 'grid-column:span 2: Wide spans 2 columns 194+8+194=396px');
    return $result;
};

$tests['[Template] grid template areas'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:200px 1fr;grid-template-rows:50px 1fr 40px;width:600px;height:300px;gap:4px">' .
        '<div style="grid-column:1/-1;background:#DDD">Header</div>' .
        '<div style="background:#F5F5F5">Sidebar</div>' .
        '<div>Main</div>' .
        '<div style="grid-column:1/-1;background:#EEE">Footer</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 600x60)', 'grid-column:1/-1: header spans 200px+gap+1fr=600px');
    assert_contains($result, 'text="Header"', 'Header text content');
    return $result;
};

// ================================================================
// 14. Positioning — Extended
// ================================================================

$tests['[Template] position fixed top left'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;height:200px">' .
        '<div style="position:fixed;left:30px;top:40px;width:100px;height:50px;background:#F00">Fixed</div>' .
        '<div style="height:40px;background:#DDD">Normal</div>' .
        '</div>'
    );
    assert_contains($result, '[pos=fixed]', 'Fixed positioned element in tree output');
    return $result;
};

$tests['[Template] absolute nested in relative'] = function () {
    $result = template_to_snapshot(
        '<div style="position:relative;width:400px;height:300px">' .
        '<div style="position:absolute;left:50px;top:50px;width:200px;height:150px;background:#F00">Abs</div>' .
        '<div style="position:absolute;left:100px;top:100px;width:100px;height:80px;background:#0F0">Abs2</div>' .
        '</div>'
    );
    assert_contains($result, 'div (50,50 200x150)', 'First absolute positioned at left:50 top:50');
    assert_contains($result, 'div (100,100 100x80)', 'Second absolute at left:100 top:100');
    return $result;
};

$tests['[Template] position relative z-index'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:150px">' .
        '<div style="position:relative;left:20px;top:20px;width:150px;height:80px;background:#F00">Layer 1</div>' .
        '<div style="position:relative;left:60px;top:-20px;width:150px;height:80px;background:#0F0">Layer 2</div>' .
        '</div>'
    );
    assert_contains($result, 'div (20,20 150x80) [pos=relative]', 'Relative offset at left:20 top:20 from normal flow');
    assert_contains($result, 'div (60,60 150x80) [pos=relative]', 'Layer 2 at y=60 (normal flow after 80px Layer1, offset -20 = 60)');
    return $result;
};

$tests['[Template] position absolute no positioned parent'] = function () {
    $result = template_to_snapshot(
        '<div style="width:500px;height:400px">' .
        '<div style="position:absolute;right:20px;bottom:30px;width:100px;height:60px;background:#F00">Abs</div>' .
        '</div>'
    );
    assert_contains($result, '[pos=absolute]', 'Absolute positioned inside non-positioned parent (relative to viewport)');
    return $result;
};

$tests['[Template] position relative normal flow'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;height:auto">' .
        '<div style="height:40px;background:#DDD">A</div>' .
        '<div style="position:relative;top:10px;height:40px;background:#F00">B</div>' .
        '<div style="height:40px;background:#DDD">C</div>' .
        '</div>'
    );
    assert_contains($result, 'pos=relative', 'Relative position preserves space in normal flow');
    return $result;
};

// ================================================================
// 15. Overflow — Extended
// ================================================================

$tests['[Template] overflow auto scroll'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;height:100px;overflow:auto;left:10px;top:10px">' .
        '<div style="width:400px;height:200px;background:#F00">Large content triggers scroll</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x100) scroll', 'Overflow auto container with scroll for oversized content');
    return $result;
};

$tests['[Template] overflow clip no scroll'] = function () {
    $result = template_to_snapshot(
        '<div style="width:100px;height:60px;overflow:clip;left:10px;top:10px">' .
        '<div style="width:400px;height:200px;background:#F00">Clipped away</div>' .
        '</div>'
    );
    assert_contains($result, 'ov=clip', 'Overflow clip correctly marked as ov=clip (not ov=hidden)');
    return $result;
};

$tests['[Template] overflow-x hidden overflow-y scroll'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:column;width:300px;height:100px;overflow-x:hidden;overflow-y:scroll;left:10px;top:10px">' .
        '<div style="height:50px;flex-shrink:0;background:#F00">Item 1</div>' .
        '<div style="height:50px;flex-shrink:0;background:#0F0">Item 2</div>' .
        '<div style="height:50px;flex-shrink:0;background:#00F">Item 3</div>' .
        '</div>'
    );
    assert_contains($result, 'scroll', 'Overflow-y scroll produces scroll container');
    return $result;
};

$tests['[Template] nested overflow containers'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:200px;overflow:hidden;left:10px;top:10px">' .
        '<div style="width:400px;height:auto;overflow-y:auto;background:#DDD">' .
        '<div style="height:300px;background:#F00">Inner tall content</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, 'ov=hidden', 'Outer overflow hidden clips inner scroll container');
    return $result;
};

// ================================================================
// 16. Visual & Typography — Extended
// ================================================================

$tests['[Template] opacity 0 hides visual'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;opacity:0;background:#F00">Invisible</div>'
    );
    assert_contains($result, 'div (0,0 100x50)', 'Opacity 0 div still occupies 100x50 in layout');
    return $result;
};

$tests['[Template] line-height spacing'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:60px;line-height:1.5;font-size:16px">Multi-line text content</div>'
    );
    assert_contains($result, 'div (0,0 200x60)', 'Line-height div at 200x60 with spacing');
    return $result;
};

$tests['[Template] text-decoration underline'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:30px;text-decoration:underline;color:#333">Underlined text</div>'
    );
    assert_contains($result, 'div (0,0 200x30)', 'Text-decoration underline div at 200x30');
    return $result;
};

$tests['[Template] font-family serif'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:300px;height:40px;font-family:serif;font-size:18px">Serif text</div>'
    );
    assert_contains($result, 'div (0,0 300x40)', 'Font-family serif div at 300x40');
    return $result;
};

$tests['[Template] visibility hidden preserves space'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:auto">' .
        '<div style="height:40px;background:#DDD">Visible</div>' .
        '<div style="visibility:hidden;height:40px;background:#F00">Hidden</div>' .
        '<div style="height:40px;background:#DDD">After</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,40 300x40)', 'Visibility hidden preserves space: hidden div at y=40, After at y=80');
    return $result;
};

// ================================================================
// 17. Sizing — Extended
// ================================================================

$tests['[Template] min-height overrides auto'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:auto;min-height:80px;background:#F00">Min 80</div>'
    );
    assert_contains($result, 'div (0,0 300x80)', 'min-height:80px enlarges auto height to at least 80px');
    return $result;
};

$tests['[Template] max-height caps size'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:300px;height:200px;max-height:60px;background:#F00">Max 60</div>'
    );
    assert_contains($result, 'div (0,0 300x60)', 'max-height:60px caps 200px height to 60px');
    return $result;
};

$tests['[Template] min-width in flex'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:300px;height:50px">' .
        '<div style="width:10%;min-width:100px;height:30px">Min</div>' .
        '<div style="flex:1;height:30px">Fill</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 100x30)', 'min-width:100px overrides 10%(30px) to 100px');
    return $result;
};

$tests['[Template] max-width in flex'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:500px;height:50px">' .
        '<div style="width:100%;max-width:200px;height:30px">Max</div>' .
        '<div style="flex:1;height:30px">Fill</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x30)', 'max-width:200px caps 100%(500px) to 200px');
    return $result;
};

$tests['[Template] width calc expression'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:calc(100% - 40px);height:50px;max-width:300px">' .
        '<div style="width:100%;height:30px">Calc child</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 300x50)', 'Calc(100% - 40px) resolves to 1400px, clipped by max-width:300px to 300px');
    return $result;
};

// ================================================================
// 18. Border Advanced — 对应 VNode Level 14
// 方向边框、多色组合、border+radius、border:0、独立 border-width
// ================================================================

$tests['[Template] border-top 顶部边框'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:80px;border-top:2px solid #FF0000">Top border</div>'
    );
    assert_contains($result, 'bw=2', 'border-top:2px solid -> bw=2');
    return $result;
};

$tests['[Template] border-bottom 底部边框'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:80px;border-bottom:3px solid #00FF00">Bottom border</div>'
    );
    assert_contains($result, 'bw=3', 'border-bottom:3px solid -> bw=3');
    return $result;
};

$tests['[Template] border-left 左边框'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:80px;border-left:4px solid #0000FF">Left border</div>'
    );
    assert_contains($result, 'bw=4', 'border-left:4px solid -> bw=4');
    return $result;
};

$tests['[Template] border-right 右边框'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:80px;border-right:5px solid #FF00FF">Right border</div>'
    );
    assert_contains($result, 'bw=5', 'border-right:5px solid -> bw=5');
    return $result;
};

$tests['[Template] 四方向不同颜色边框组合'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:100px;border-top:2px solid #FF0000;border-right:3px solid #00FF00;border-bottom:4px solid #0000FF;border-left:5px solid #FF00FF">Multi border</div>'
    );
    assert_contains($result, 'bw=4', 'four borders 2+3+4+5px');
    return $result;
};

$tests['[Template] border + border-radius 圆角边框'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:150px;height:80px;border:2px solid #333;border-radius:10px">Round border</div>'
    );
    assert_contains($result, 'bw=2', 'border:2px -> bw=2');
    assert_contains($result, '(0,0 150x80)', '150x80 dimensions unchanged');
    return $result;
};

$tests['[Template] border:0 无边框'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;border:0">No border</div>'
    );
    assert_contains($result, '(0,0 100x50)', 'border:0 preserves 100x50');
    return $result;
};

$tests['[Template] border-width 独立设置 6px'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;border-width:6px;border-color:#FF6600">Border width</div>'
    );
    assert_contains($result, 'bw=6', 'border-width:6px -> bw=6');
    return $result;
};

// ================================================================
// 19. Transform & Visual Effects — 对应 VNode Level 16
// translate, object-fit, cursor, rotate
// ================================================================

$tests['[Template] transform translateX(30px) 水平偏移'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;transform:translateX(30px)">Translate X</div>'
    );
    assert_contains($result, '(0,0 100x50)', 'transform does NOT affect layout position');
    return $result;
};

$tests['[Template] transform translateY(20px) 垂直偏移'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;transform:translateY(20px)">Translate Y</div>'
    );
    assert_contains($result, '(0,0 100x50)', 'transform does NOT affect layout position');
    return $result;
};

$tests['[Template] object-fit cover 覆盖填充'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:100px;object-fit:cover">Cover</div>'
    );
    assert_contains($result, '(0,0 200x100)', 'object-fit:cover dimensions unchanged');
    return $result;
};

$tests['[Template] object-fit contain 适应'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:200px;height:100px;object-fit:contain">Contain</div>'
    );
    assert_contains($result, '(0,0 200x100)', 'object-fit:contain dimensions unchanged');
    return $result;
};

$tests['[Template] cursor pointer 指针样式'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;cursor:pointer">Clickable</div>'
    );
    assert_contains($result, '(0,0 100x50)', 'cursor does not affect layout');
    return $result;
};

$tests['[Template] transform rotate(45deg) 旋转'] = function () {
    $result = template_to_snapshot(
        '<div style="left:10px;top:10px;width:100px;height:50px;transform:rotate(45deg)">Rotated</div>'
    );
    assert_contains($result, '(0,0 100x50)', 'transform does NOT affect layout position');
    return $result;
};

// ================================================================
// 20. Display Variations — 对应 VNode Level 17
// display:inline, inline-block, display:none in flex, visibility:visible override
// ================================================================

$tests['[Template] display:inline 行内并排'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:auto">' .
        '<div style="display:inline;color:#F00">Red </div>' .
        '<div style="display:inline;color:#00F">Blue </div>' .
        '<div style="display:inline;color:#090">Green</div>' .
        '</div>'
    );
    assert_contains($result, 'dsp=inline', 'inline elements have dsp=inline');
    return $result;
};

$tests['[Template] display:inline-block 行内块布局'] = function () {
    $result = template_to_snapshot(
        '<div style="width:400px;height:auto">' .
        '<div style="display:inline-block;width:100px;height:60px;background:#F00">A</div>' .
        '<div style="display:inline-block;width:100px;height:80px;background:#0F0">B</div>' .
        '<div style="display:inline-block;width:100px;height:50px;background:#00F">C</div>' .
        '</div>'
    );
    assert_contains($result, 'dsp=inline-block', 'inline-block elements');
    assert_contains($result, '100x60', 'inline-block A has 100x60 size');
    return $result;
};

$tests['[Template] display:none 在 flex row 中'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:400px;height:50px">' .
        '<div style="width:80px;height:30px;background:#F00">A</div>' .
        '<div style="display:none;width:200px;height:30px;background:#0F0">Hidden</div>' .
        '<div style="width:80px;height:30px;background:#00F">C</div>' .
        '</div>'
    );
    assert_contains($result, 'dsp=none', 'display:none in flex marked as dsp=none');
    assert_contains($result, 'text="C"', 'third flex child present');
    return $result;
};

$tests['[Template] visibility:visible 子项覆盖父 hidden'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:auto">' .
        '<div style="visibility:hidden;height:60px">' .
        '<div style="height:30px">Hidden parent</div>' .
        '<div style="visibility:visible;height:30px;background:#FF0">Visible child</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, 'text="Visible child"', 'visibility:visible child overrides parent hidden');
    return $result;
};

// ================================================================
// 21. Edge Cases — 对应 VNode Level 18
// 零尺寸、负边距、极大宽度、空容器、深度嵌套等
// ================================================================

$tests['[Template] width:0 height:0 零尺寸元素'] = function () {
    $result = template_to_snapshot(
        '<div style="width:0;height:0;overflow:hidden">Zero</div>'
    );
    assert_contains($result, '(0,0 0x0)', 'zero dimensions element at (0,0 0x0)');
    return $result;
};

$tests['[Template] margin 负值重叠 上负下负'] = function () {
    $result = template_to_snapshot(
        '<div style="width:300px;height:auto">' .
        '<div style="height:50px;margin-bottom:-10px;background:#F00">Bottom -10</div>' .
        '<div style="height:50px;margin-top:-10px;background:#0F0">Top -10</div>' .
        '</div>'
    );
    assert_contains($result, '(0,30 300x50)', 'negative margin overlap: y=50-10-10=30');
    return $result;
};

$tests['[Template] width:10000px 极大宽度 裁边'] = function () {
    $result = template_to_snapshot(
        '<div style="width:10000px;height:30px">Very wide element</div>'
    );
    assert_contains($result, '10000x30', 'width:10000px preserved in output');
    return $result;
};

$tests['[Template] overflow:hidden 零尺寸容器'] = function () {
    $result = template_to_snapshot(
        '<div style="width:0;height:0;overflow:hidden">' .
        '<div style="width:200px;height:100px;background:#F00">Hidden content</div>' .
        '</div>'
    );
    assert_contains($result, 'ov=hidden', 'overflow:hidden container');
    assert_contains($result, '(0,0 0x0)', 'zero-size container');
    return $result;
};

$tests['[Template] 空子元素容器'] = function () {
    $result = template_to_snapshot(
        '<div style="width:200px;min-height:40px"></div>'
    );
    assert_contains($result, '200x40', 'empty children: parent renders with min-height 40');
    return $result;
};

$tests['[Template] 单一子项 margin auto 居中'] = function () {
    $result = template_to_snapshot(
        '<div style="width:400px;height:100px">' .
        '<div style="width:100px;height:50px;margin:auto;background:#F00">Only child</div>' .
        '</div>'
    );
    assert_contains($result, '(150,0 100x50)', 'margin auto centers child at x=(400-100)/2=150');
    return $result;
};

$tests['[Template] border 在零尺寸元素上'] = function () {
    $result = template_to_snapshot(
        '<div style="width:0;height:0;border:2px solid #F00;overflow:hidden"></div>'
    );
    assert_contains($result, 'bw=2', 'border:2px on zero-size element -> bw=2');
    return $result;
};

$tests['[Template] padding 在零尺寸元素上'] = function () {
    $result = template_to_snapshot(
        '<div style="width:0;height:0;padding:10px;overflow:hidden">Pad</div>'
    );
    assert_contains($result, 'ov=hidden', 'overflow:hidden with padding on zero-size');
    return $result;
};

$tests['[Template] opacity:0 子项在 flex row 中'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:300px;height:50px">' .
        '<div style="width:60px;height:30px">A</div>' .
        '<div style="width:60px;height:30px;opacity:0">Invisible</div>' .
        '<div style="width:60px;height:30px">C</div>' .
        '</div>'
    );
    assert_contains($result, 'text="Invisible"', 'opacity:0 element still participates in flex layout');
    return $result;
};

$tests['[Template] 深度嵌套 10 层 div'] = function () {
    // Build 10-level nesting dynamically
    $template = '<div style="width:200px;height:100px">';
    for ($i = 0; $i < 9; $i++) {
        $template .= '<div style="">';
    }
    $template .= '<div style="background:#F00;width:10px;height:10px">Deep</div>';
    for ($i = 0; $i < 9; $i++) {
        $template .= '</div>';
    }
    $template .= '</div>';
    $result = template_to_snapshot($template);
    assert_contains($result, '10x10)', '10-level nesting reaches deepest child at 10x10');
    assert_contains($result, 'text="Deep"', 'Deepest text content found');
    return $result;
};

// ================================================================
// 22. Complex Real World — 对应 VNode Level 20
// Dashboard, Article, Tab, Pricing Card, Hero, Card Grid, Toolbar, Form, Notifications, Split
// ================================================================

$tests['[Template] Dashboard 侧边栏+头部+主内容'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:200px 1fr;grid-template-rows:60px 1fr;width:900px;height:600px">' .
        '<div style="background:#333;color:#FFF;padding:16px">Sidebar</div>' .
        '<div style="background:#EEE;padding:16px">Header</div>' .
        '<div style="display:flex;flex-direction:row;gap:16px;padding:16px;grid-column:2">' .
        '<div style="flex:2;background:#FFF;padding:16px;border-radius:8px">Main Content</div>' .
        '<div style="flex:1;background:#FFF;padding:16px;border-radius:8px">Side Panel</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 200x60)', 'Grid sidebar spans first col at 200px width');
    assert_contains($result, 'text="Sidebar"', 'Sidebar text content');
    assert_contains($result, 'div (200,0 700x60)', 'Header at x=200 spans second col 700px');
    assert_contains($result, 'text="Header"', 'Header text content');
    return $result;
};

$tests['[Template] Article 页面 标题+内容+侧边栏'] = function () {
    $result = template_to_snapshot(
        '<div style="max-width:800px;width:800px">' .
        '<div style="font-size:28px;font-weight:bold;padding:16px 0">Article Title</div>' .
        '<div style="display:flex;flex-direction:row;gap:20px">' .
        '<div style="flex:3;line-height:1.6">Article content paragraph here.</div>' .
        '<div style="flex:1;background:#F5F5F5;padding:16px;border-radius:8px">Sidebar Widget</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, '[dsp=flex]', 'Flex article row in layout');
    assert_contains($result, 'text="Sidebar Widget"', 'Sidebar with flex:1 in article layout');
    return $result;
};

$tests['[Template] Tab 切换组件 标签页头+内容'] = function () {
    $result = template_to_snapshot(
        '<div style="width:600px">' .
        '<div style="display:flex;flex-direction:row;border-bottom:2px solid #DDD">' .
        '<div style="padding:10px 20px;border-bottom:2px solid #F00;color:#F00;cursor:pointer">Tab 1</div>' .
        '<div style="padding:10px 20px;color:#999;cursor:pointer">Tab 2</div>' .
        '<div style="padding:10px 20px;color:#999;cursor:pointer">Tab 3</div>' .
        '</div>' .
        '<div style="padding:20px;background:#FFF;min-height:100px">Tab Content 1</div>' .
        '</div>'
    );
    assert_contains($result, '[dsp=flex] bw=2', 'Tab header row in flex layout with border-bottom 2px');
    assert_contains($result, 'div (0,28 600x100)', 'Tab content area 600x100 below header');
    assert_contains($result, 'text="Tab Content 1"', 'Tab content text present');
    return $result;
};

$tests['[Template] Pricing Card 价格卡片'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;gap:16px;width:700px;padding:20px">' .
        '<div style="flex:1;border:1px solid #DDD;border-radius:12px;padding:24px;text-align:center">' .
        '<div style="font-size:20px;font-weight:bold">Basic</div>' .
        '<div style="font-size:36px;color:#F00;padding:12px 0">$9</div>' .
        '<div style="padding:8px;background:#F00;color:#FFF;border-radius:6px;cursor:pointer">Sign Up</div>' .
        '</div>' .
        '<div style="flex:1;border:2px solid #00F;border-radius:12px;padding:24px;text-align:center">' .
        '<div style="font-size:20px;font-weight:bold">Pro</div>' .
        '<div style="font-size:36px;color:#00F;padding:12px 0">$29</div>' .
        '<div style="padding:8px;background:#00F;color:#FFF;border-radius:6px;cursor:pointer">Sign Up</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, 'div (20,20 322x98) bw=1 fg=1', 'First pricing card at (20,20) flex:1 in 700px row');
    return $result;
};

$tests['[Template] 垂直居中 Hero Section'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:800px;height:400px;background:#F0F8FF">' .
        '<div style="font-size:42px;font-weight:bold;text-align:center">Welcome</div>' .
        '<div style="font-size:18px;color:#666;text-align:center;padding:12px">Subtitle here</div>' .
        '<div style="padding:12px 32px;background:#00F;color:#FFF;border-radius:8px;cursor:pointer">Get Started</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,161 800x18)', 'Welcome centered at y=161 in 400px column with justify-content:center');
    assert_contains($result, 'text="Welcome"', 'Welcome text content');
    return $result;
};

$tests['[Template] 响应式卡片网格 auto-fill'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));width:800px;gap:16px;padding:16px">' .
        '<div style="border:1px solid #DDD;border-radius:8px;padding:16px;background:#FFF">Card 1</div>' .
        '<div style="border:1px solid #DDD;border-radius:8px;padding:16px;background:#FFF">Card 2</div>' .
        '<div style="border:1px solid #DDD;border-radius:8px;padding:16px;background:#FFF">Card 3</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 800x60) [dsp=grid]', 'Card grid 800x60 with auto-fill minmax(200px,1fr)');
    return $result;
};

$tests['[Template] 工具栏+内容区 flex 布局'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:column;width:800px;height:500px">' .
        '<div style="display:flex;flex-direction:row;align-items:center;padding:8px 16px;background:#F5F5F5;gap:8px">' .
        '<div style="font-weight:bold;font-size:18px">Title</div>' .
        '<div style="flex:1"></div>' .
        '<div style="padding:6px 16px;background:#00F;color:#FFF;border-radius:4px;cursor:pointer">Save</div>' .
        '<div style="padding:6px 16px;border:1px solid #DDD;border-radius:4px;cursor:pointer">Cancel</div>' .
        '</div>' .
        '<div style="flex:1;padding:16px;overflow-y:auto">Content area with scroll.</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 800x28) [dsp=flex]', 'Toolbar bar at top with flex row layout');
    assert_contains($result, 'div (0,28 800x472) scroll', 'Content area at y=28 below toolbar with scroll');
    return $result;
};

$tests['[Template] 表单布局 label+input 两列'] = function () {
    $result = template_to_snapshot(
        '<div style="display:grid;grid-template-columns:120px 1fr;width:500px;gap:12px 8px;padding:16px">' .
        '<div style="text-align:right;padding:6px 0">Username:</div>' .
        '<div style="padding:6px;border:1px solid #DDD;border-radius:4px">User input</div>' .
        '<div style="text-align:right;padding:6px 0">Password:</div>' .
        '<div style="padding:6px;border:1px solid #DDD;border-radius:4px">Pass input</div>' .
        '<div style="text-align:right;padding:6px 0">Bio:</div>' .
        '<div style="padding:6px;border:1px solid #DDD;border-radius:4px;height:60px">Bio text</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 120x60)', 'Label in first grid column 120px width');
    assert_contains($result, 'text="Username:"', 'Username label text content');
    return $result;
};

$tests['[Template] 通知列表 icon+text+time'] = function () {
    $result = template_to_snapshot(
        '<div style="width:400px;display:flex;flex-direction:column;gap:8px">' .
        '<div style="display:flex;flex-direction:row;align-items:center;padding:12px;background:#FFF;border-radius:8px;gap:12px">' .
        '<div style="width:40px;height:40px;border-radius:50%;background:#F00;flex-shrink:0"></div>' .
        '<div style="flex:1">' .
        '<div style="font-weight:bold">Notification title</div>' .
        '<div style="font-size:12px;color:#999">Notification description text.</div>' .
        '</div>' .
        '<div style="font-size:12px;color:#CCC">2m ago</div>' .
        '</div>' .
        '<div style="display:flex;flex-direction:row;align-items:center;padding:12px;background:#FFF;border-radius:8px;gap:12px">' .
        '<div style="width:40px;height:40px;border-radius:50%;background:#0F0;flex-shrink:0"></div>' .
        '<div style="flex:1">' .
        '<div style="font-weight:bold">Another notification</div>' .
        '<div style="font-size:12px;color:#999">Some longer description here.</div>' .
        '</div>' .
        '<div style="font-size:12px;color:#CCC">1h ago</div>' .
        '</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 400x44) [dsp=flex]', 'First notification row 400x44 with flex layout');
    assert_contains($result, 'div (0,52 400x44) [dsp=flex]', 'Second notification row at y=52 (44+8 gap)');
    return $result;
};

$tests['[Template] 分割面板 left+right'] = function () {
    $result = template_to_snapshot(
        '<div style="display:flex;flex-direction:row;width:800px;height:400px;border:1px solid #DDD">' .
        '<div style="flex:1;padding:16px;overflow-y:auto;background:#F9F9F9">Left Panel</div>' .
        '<div style="width:4px;background:#DDD;cursor:col-resize"></div>' .
        '<div style="flex:2;padding:16px;overflow-y:auto">Right Panel</div>' .
        '</div>'
    );
    assert_contains($result, 'div (0,0 265x400) scroll', 'Left panel with scroll at flex:1 = 265px in 800px row');
    assert_contains($result, 'text="Right Panel"', 'Right panel with flex:2 present');
    return $result;
};

$snapFile = __DIR__ . '/../__snapshots__/Template-From-Template.snap';
run_css_tests('Template-Based CSS', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
