<?php
/**
 * Template Parser & CSS Parser Robustness Tests
 *
 * 测试最近修复和增强的解析边界场景:
 *   1. Template Parser: < 合法性校验、自闭合空白、\r 处理、嵌套、boolean attr、v-if
 *   2. CSS Parser: vendor prefix、!important、repeat(fr)、grid template
 *
 * Usage: php tests/parser-robustness-test.php
 */

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/../framework/Compiler/TemplateParser.php';
require_once __DIR__ . '/../framework/Compiler/ComponentRegistry.php';

// Namespaced classes
use Px\Rendering\CssMappings;
use Px\Rendering\StyleResolver;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  PASS: $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL: $name — {$e->getMessage()}\n";
    } catch (AssertionError $e) {
        $failed++;
        echo "  FAIL: $name — {$e->getMessage()}\n";
    }
}

echo "=== Parser Robustness Tests ===\n\n";

// ============================================================
// 1. Template Parser: < 合法性校验
// ============================================================
echo "--- 1. < 合法性校验 (tag starter validation) ---\n";

test('<− (backspace symbol) treated as text, not tag', function () {
    $tpl = '<div><button><-</button></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
    assert($root->childCount() === 1, "Expected 1 child div");
    $div = $root->children[0];
    $btn = $div->children[0];
    assert($btn->type === 'button', "Expected button, got {$btn->type}");
    assert(strpos($btn->children, '<-') !== false, "Button text should contain '<-'");
});

test('<= (less-than-or-equal) treated as text', function () {
    $tpl = '<div><span>a <= b</span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
    $div = $root->children[0];
    $span = $div->children[0];
    assert(strpos($span->children, '<=') !== false, "Text should contain '<='");
});

test('<3 treated as text, not tag', function () {
    $tpl = '<div><span>I <3 PUI</span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
    $div = $root->children[0];
    $span = $div->children[0];
    assert(strpos($span->children, '<3') !== false, "Text should contain '<3'");
});

test('< followed by space treated as text', function () {
    $tpl = '<div><span>5 < 10</span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors");
    $div = $root->children[0];
    $span = $div->children[0];
    assert(strpos($span->children, '< 10') !== false, "Text should contain '< 10'");
});

test('< followed by digit treated as text', function () {
    $tpl = '<div><span>#<42></span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors");
});

test('valid <div> still parsed as tag', function () {
    $tpl = '<div class="ok"><span>hello</span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors");
    assert($root->childCount() === 1, "Expected 1 child div");
    assert($root->children[0]->type === 'div', "Expected div");
});

test('valid closing tag </div> still works', function () {
    $tpl = '<div><span>x</span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
});

// ============================================================
// 2. Template Parser: 自闭合标签空白
// ============================================================
echo "\n--- 2. 自闭合标签空白 (self-closing with whitespace) ---\n";

test('<br /> with space before />', function () {
    $tpl = '<div><br /></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
});

test('<br/> without space still works', function () {
    $tpl = '<div><br/></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors");
});

test('<input type="text" /> self-closing with space', function () {
    $tpl = '<div><input type="text" /></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
});

test('component tag <about-dialog /> self-closing with attributes', function () {
    $tpl = '<div><about-dialog style="left:0px;top:0px" overlay v-if="showDialog" /></div>';
    $registry = new ComponentRegistry();
    $registry->register('about-dialog', __DIR__ . '/../apps/calculator/components/AboutDialog.vue', 'user');
    $parser = new TemplateParser($registry);
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
});

// ============================================================
// 3. Template Parser: Boolean 属性
// ============================================================
echo "\n--- 3. Boolean 属性 ---\n";

test('boolean attribute overlay parsed without value', function () {
    $tpl = '<div overlay class="panel"></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
    $div = $root->children[0];
    assert($div->props['overlay'] !== null, "overlay should be present as a prop");
});

test('boolean attribute disabled on button', function () {
    $tpl = '<div><button disabled>Click</button></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors");
    $div = $root->children[0];
    $btn = $div->children[0];
    assert($btn->type === 'button', "Expected button");
    assert(isset($btn->props['disabled']), "disabled should be present");
});

// ============================================================
// 4. Template Parser: 嵌套同名标签
// ============================================================
echo "\n--- 4. 嵌套同名标签 (nested same-name tags) ---\n";

test('nested <div><div><div></div></div></div> 3 levels', function () {
    $tpl = '<div id="outer"><div id="middle"><div id="inner">deep</div></div></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
    $outer = $root->children[0];
    assert($outer->type === 'div', "outer should be div");
    $middle = $outer->children[0];
    assert($middle->type === 'div', "middle should be div");
    $inner = $middle->children[0];
    assert($inner->type === 'div', "inner should be div");
    assert(strpos($inner->children, 'deep') !== false, "innermost should contain 'deep'");
});

test('nested <div> with siblings at each level', function () {
    $tpl = '<div id="a"><span>A1</span><div id="b"><span>B1</span><div id="c">C</div><span>B2</span></div><span>A2</span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
    $a = $root->children[0];
    assert($a->childCount() === 3, "Level A should have 3 children, got " . $a->childCount());
    $b = $a->children[1];
    assert($b->type === 'div', "middle should be div");
    assert($b->childCount() === 3, "Level B should have 3 children, got " . $b->childCount());
});

// ============================================================
// 5. Template Parser: v-if 语义
// ============================================================
echo "\n--- 5. v-if 语义 ---\n";

test('v-if on component tag propagates to inlined wrapper', function () {
    $tpl = '<div id="app"><about-dialog style="left:0px;top:0px" v-if="showDialog" /></div>';
    $registry = new ComponentRegistry();
    $registry->register('about-dialog', __DIR__ . '/../apps/calculator/components/AboutDialog.vue', 'user');
    $parser = new TemplateParser($registry);
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors, got: " . implode('; ', array_map('strval', $errors)));
});

test('v-if on div with children having no v-if', function () {
    $tpl = '<div v-if="showDialog"><span class="title">Hello</span><button>Close</button></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors");
    $div = $root->children[0];
    assert(isset($div->props['v-if']), "div should have v-if");
    assert($div->props['v-if'] === 'showDialog', "v-if should be showDialog");
    // Children should NOT have v-if
    if ($div->childCount() >= 1) {
        $span = $div->children[0];
        assert(!isset($span->props['v-if']), "span child should NOT have v-if");
    }
});

test('nested v-if: outer false → inner never evaluated', function () {
    // This tests the structural correctness; the runtime short-circuit
    // is tested at the framework level, but the parser should produce
    // valid VNode trees for nested v-if scenarios
    $tpl = '<div v-if="outer"><div v-if="inner"><span>deep</span></div></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    assert(count($errors) === 0, "Expected 0 errors");
    $outer = $root->children[0];
    assert($outer->props['v-if'] === 'outer', "outer v-if should be 'outer'");
    $inner = $outer->children[0];
    assert($inner->props['v-if'] === 'inner', "inner v-if should be 'inner'");
});

// ============================================================
// 6. CSS Parser: Vendor Prefix & !important
// ============================================================
echo "\n--- 6. CSS: Vendor Prefix & !important ---\n";

test('parseInlineStyle: strips !important from value', function () {
    $result = StyleResolver::parseInlineStyle('width: 100px !important; height: 50px;');
    // width should be parsed as 100px without "!important"
    assert(isset($result['width']), "width should be set");
    assert(strpos((string)$result['width'], 'important') === false, "width value should not contain 'important'");
});

test('parseInlineStyle: !important on known property', function () {
    $result = StyleResolver::parseInlineStyle('left: 20px !important;');
    assert(isset($result['left']), "left should be set");
});

test('parseInlineStyle: vendor prefix -webkit- stored as raw', function () {
    $result = StyleResolver::parseInlineStyle('-webkit-appearance: none; width: 200px;');
    // -webkit-appearance is unknown, stored as raw string key
    assert(isset($result['-webkit-appearance']), "-webkit-appearance should be stored as raw");
    assert($result['-webkit-appearance'] === 'none', "should be 'none'");
    assert(isset($result['width']), "width should still be parsed");
});

test('parseInlineStyle: vendor prefix -moz- stored as raw', function () {
    $result = StyleResolver::parseInlineStyle('-moz-appearance: button; height: 100px;');
    assert(isset($result['-moz-appearance']), "-moz-appearance should be stored as raw");
    assert(isset($result['height']), "height should still be parsed");
});

test('parseInlineStyle: no trailing semicolon', function () {
    $result = StyleResolver::parseInlineStyle('width: 100px');
    assert(isset($result['width']), "width should be set without trailing ;");
});

test('parseInlineStyle: multiple !important declarations', function () {
    $result = StyleResolver::parseInlineStyle('width: 200px !important; height: 100px !important; left: 0px;');
    assert(isset($result['width']), "width should be set");
    assert(isset($result['height']), "height should be set");
    assert(isset($result['left']), "left should be set");
});

// ============================================================
// 7. CSS Parser: Grid Template repeat() with fr
// ============================================================
echo "\n--- 7. CSS: Grid Template repeat() ---\n";

test('parseGridTemplateValue: repeat(N, px)', function () {
    $result = CssMappings::parseGridTemplateValue('repeat(4, 80px)');
    assert($result['repeat'] === true, "should be repeat");
    assert($result['count'] === 4, "count should be 4");
    assert($result['size'] === 80, "size should be 80");
});

test('parseGridTemplateValue: repeat(N, fr)', function () {
    $result = CssMappings::parseGridTemplateValue('repeat(4, 1fr)');
    assert($result['repeat'] === true, "should be repeat");
    assert($result['count'] === 4, "count should be 4");
    assert($result['size'] === 1.0, "size should be 1.0 for 1fr");
    assert($result['unit'] === 'fr', "unit should be 'fr'");
});

test('parseGridTemplateValue: repeat(N, %)', function () {
    $result = CssMappings::parseGridTemplateValue('repeat(2, 25%)');
    assert($result['repeat'] === true, "should be repeat");
    assert($result['count'] === 2, "count should be 2");
});

test('parseGridTemplateValue: repeat(N, bare number)', function () {
    $result = CssMappings::parseGridTemplateValue('repeat(4, 80)');
    assert($result['repeat'] === true, "should be repeat");
    assert($result['count'] === 4, "count should be 4");
});

test('parseGridTemplateValue: complex repeat with minmax', function () {
    $result = CssMappings::parseGridTemplateValue('repeat(2, minmax(100px, 1fr))');
    assert($result['repeat'] === true, "should be repeat");
    assert($result['count'] === 2, "count should be 2");
    assert(isset($result['track']), "complex track should be stored");
    assert(strpos($result['track'], 'minmax') !== false, "track should contain minmax");
});

test('parseGridTemplateValue: auto', function () {
    $result = CssMappings::parseGridTemplateValue('auto');
    assert($result['type'] === 'auto', "should be auto type");
});

test('parseGridTemplateValue: explicit sizes with fr', function () {
    $result = CssMappings::parseGridTemplateValue('1fr 1fr 1fr 1fr');
    assert($result['type'] === 'explicit', "should be explicit");
    assert(count($result['sizes']) === 4, "should have 4 sizes");
    assert($result['sizes'][0] === '1fr', "first should be 1fr");
});

test('parseGridTemplateValue: mixed px and fr', function () {
    $result = CssMappings::parseGridTemplateValue('100px 1fr auto');
    assert($result['type'] === 'explicit', "should be explicit");
    assert(count($result['sizes']) === 3, "should have 3 sizes");
});

// ============================================================
// 8. CSS Parser: parseStyleBlock 边界
// ============================================================
echo "\n--- 8. CSS: parseStyleBlock 边界 ---\n";

test('parseStyleBlock: class without warning props gets warning', function () {
    $warnings = [];
    $styles = ".no-bg-no-fg { font-size: 14px; }";
    $result = CssMappings::parseStyleBlock($styles, $warnings);
    assert(count($warnings) >= 1, "Should warn about no bg/fg, got " . count($warnings));
});

test('parseStyleBlock: class with background gets no warning', function () {
    $warnings = [];
    $styles = ".has-bg { background: #1e1e1e; }";
    $result = CssMappings::parseStyleBlock($styles, $warnings);
    $hasBgWarn = false;
    foreach ($warnings as $w) {
        if (strpos($w, 'has-bg') !== false) $hasBgWarn = true;
    }
    assert(!$hasBgWarn, "class with bg should not warn about missing bg/fg");
});

test('parseStyleBlock: class with color gets no warning', function () {
    $warnings = [];
    $styles = ".has-fg { color: #ffffff; }";
    $result = CssMappings::parseStyleBlock($styles, $warnings);
    $hasFgWarn = false;
    foreach ($warnings as $w) {
        if (strpos($w, 'has-fg') !== false) $hasFgWarn = true;
    }
    assert(!$hasFgWarn, "class with fg should not warn about missing bg/fg");
});

test('parseStyleBlock: handles Kebab-case class names', function () {
    $styles = ".dialog-overlay { background: #0a0a0a; } .dialog-box { background: #2d2d2d; }";
    $result = CssMappings::parseStyleBlock($styles);
    assert(count($result) === 2, "Expected 2 classes, got " . count($result));
    assert(isset($result['dialog-overlay']), "dialog-overlay should be parsed");
    assert(isset($result['dialog-box']), "dialog-box should be parsed");
});

// ============================================================
// Summary
// ============================================================
echo "\n=== Results: $passed passed, $failed failed ===\n";

if ($failed > 0) {
    exit(1);
}
echo "All tests passed!\n";
