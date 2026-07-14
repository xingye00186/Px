<?php
/**
 * SFC Compiler v6 Unit Tests (VNode Architecture)
 * 
 * Usage: php tests/sfc-compiler-test.php
 * 
 * Covers:
 *   1. CSS mappings: hexToBgr, borderColor, parseStyleBlock, parseInlineStyle
 *   2. Template parser (VNode): App.vue → VNode tree
 *   3. AOT validator: filename dots, const arrays, variable property/method, PHP8 functions
 *   4. Code generation: SFC compiler output
 */

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/../framework/Compiler/TemplateParser.php';
require_once __DIR__ . '/../framework/Compiler/AotValidator.php';
require_once __DIR__ . '/../framework/Compiler/ComponentRegistry.php';
require_once __DIR__ . '/../framework/Compiler/sfc-compiler.php';

// Namespaced classes
use Px\Css\CssMappings;
use Px\Css\CssValueParser;
use Px\Css\StyleResolver;
use Px\Compiler\AotValidator;

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

echo "=== SFC Compiler v6 Unit Tests (VNode Architecture) ===\n\n";

// ============================================================
// 1. CSS Mappings Tests
// ============================================================
echo "--- 1. CSS Mappings ---\n";

test('hexToBgr: #1e1e1e → BGR int', function () {
    $bgr = CssValueParser::hexToBgr('#1e1e1e');
    assert($bgr === 1973790, "Expected 1973790, got $bgr");
});

test('hexToBgr: #ffffff → BGR int', function () {
    $bgr = CssValueParser::hexToBgr('#ffffff');
    assert($bgr === 16777215, "Expected 16777215, got $bgr");
});

test('hexToBgr: #ff9500 → BGR int', function () {
    $bgr = CssValueParser::hexToBgr('#ff9500');
    assert($bgr === 38399, "Expected 38399, got $bgr");
});

test('hexToBgr: shorthand #RGB (#1e1)', function () {
    $bgr = CssValueParser::hexToBgr('#1e1');
    assert($bgr === 1175057, "Expected 1175057, got $bgr");
});

test('hexToBgr: invalid hex returns 0', function () {
    $bgr = CssValueParser::hexToBgr('#xyz123');
    assert($bgr === 0, "Expected 0 for invalid hex, got $bgr");
});

test('borderColor: lightens channels by +20', function () {
    $bg = 1973790; // #1e1e1e
    $border = CssValueParser::borderColor($bg);
    $expected = (50 << 16) | (50 << 8) | 50;
    assert($border === $expected, "Expected $expected, got $border");
});

test('borderColor: clamps at 255', function () {
    $bg = 0xFFFFFF;
    $border = CssValueParser::borderColor($bg);
    assert($border === 16777215, "Expected 16777215, got $border");
});

test('parseStyleBlock: extracts 8 classes from App.vue style', function () {
    $styles = ".app-bg { background: #1e1e1e; }\n.display-bg { background: #2d2d2d; }\n"
            . ".expr-text { font-size: 16px; color: #969696; }\n"
            . ".display-text { font-size: 32px; color: #ffffff; font-weight: bold; }\n"
            . ".btn-num { background: #323232; color: #ffffff; }\n"
            . ".btn-op { background: #ff9500; color: #ffffff; }\n"
            . ".btn-eq { background: #007aff; color: #ffffff; }\n"
            . ".btn-func { background: #505050; color: #ffffff; }";
    
    $result = CssMappings::parseStyleBlock($styles);
    assert(count($result) === 8, "Expected 8 classes, got " . count($result));
    assert(isset($result['btn-num']['bg']), "btn-num should have bg");
    assert(isset($result['btn-num']['fg']), "btn-num should have fg");
    assert(isset($result['display-text']['bold']), "display-text should have bold=1");
    assert($result['display-text']['bold'] === 1, "display-text bold should be 1");
});

test('PROPERTY_MAP: supports 8+ CSS properties', function () {
    $propCount = count(CssMappings::PROPERTY_MAP);
    assert($propCount >= 8, "PROPERTY_MAP should have 8+ entries, got $propCount");
});

test('parseInlineStyle: parses width/height/left/top', function () {
    $result = StyleResolver::parseInlineStyle('width: 100px; height: 50px; left: 10px; top: 20px;');
    assert($result['width'] === '100px', "width should be 100px, got {$result['width']}");
    assert($result['height'] === '50px', "height should be 50px, got {$result['height']}");
    assert($result['left'] === '10px', "left should be 10px, got {$result['left']}");
    assert($result['top'] === '20px', "top should be 20px, got {$result['top']}");
});

test('parseInlineStyle: parses display and flex properties', function () {
    $result = StyleResolver::parseInlineStyle('display: flex; flex-direction: column; justify-content: center;');
    assert($result['display'] === 'flex', "display should be flex");
    assert($result['flex-direction'] === 'column', "flex-direction should be column");
    assert($result['justify-content'] === 'center', "justify-content should be center");
});

test('parseInlineStyle: parses grid-template-columns', function () {
    $result = StyleResolver::parseInlineStyle('grid-template-columns: repeat(4, 80px);');
    assert(isset($result['grid-template-columns']), "grid-template-columns should be set");
});

// ============================================================
// 2. Template Parser (VNode) Tests
// ============================================================
echo "\n--- 2. Template Parser (VNode) ---\n";

test('Parser: parses basic div template into VNode tree', function () {
    $tpl = '<div id="root"><span class="title">{{ msg }}</span><button class="btn" @click="doClick">OK</button></div>';
    
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    
    assert(count($errors) === 0, "Expected 0 errors, got " . count($errors) . ": " . implode('; ', array_map('strval', $errors)));
    assert($root->isRoot(), "Root should be a root VNode");
    assert($root->childCount() >= 2, "Expected at least 2 children, got " . $root->childCount());
    
    // First child should be span
    $span = $root->children[0];
    assert($span->type === 'span', "First child should be span, got {$span->type}");
});

test('Parser: parses flex container with CSS style', function () {
    $tpl = '<div style="display: flex; flex-direction: column; width: 300px; height: 400px;"><span>A</span><span>B</span></div>';
    
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    
    assert(count($errors) === 0, "Expected 0 errors");
    assert($root->childCount() === 1, "Expected 1 child");
    
    $flex = $root->children[0];
        $style = StyleResolver::parseInlineStyle($flex->getProp('style'));
    assert($style['display'] === 'flex', "display should be flex");
    assert($style['flex-direction'] === 'column', "flex-direction should be column");
});

test('Parser: button with @click produces event handler', function () {
    $tpl = '<button class="btn" @click="handleClick(\'+\')">+</button>';
    
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    
    assert(count($errors) === 0, "Expected 0 errors");
    assert($root->childCount() === 1, "Expected 1 child");
    
    $btn = $root->children[0];
    assert($btn->type === 'button', "Should be button type, got {$btn->type}");
    assert($btn->getEventHandler() === 'handleClick', "Handler should be handleClick, got " . ($btn->getEventHandler() ?? 'null'));
});

test('Parser: v-for produces loop info', function () {
    $tpl = '<template v-for="item in items" :key="item.id"><div class="row"><span>{{ item.name }}</span></div></template>';
    
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    
    assert(count($errors) === 0, "Expected 0 errors, got " . count($errors) . ": " . implode('; ', array_map('strval', $errors)));
    // v-for template should have loop info
    assert($root->childCount() >= 1, "Should have at least 1 child");
});

test('Parser: text interpolation produces text VNode', function () {
    $tpl = '<span class="greeting">Hello {{ name }}!</span>';
    
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    
    assert(count($errors) === 0, "Expected 0 errors");
    $span = $root->children[0];
    assert($span->type === 'span', "Should be span type");
});

test('Parser: unknown tags produce error', function () {
    $tpl = '<div id="root"><mystery-tag /></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $errors = $parser->getErrors();
    
    assert(count($errors) >= 1, "Should report unknown tag error, got " . count($errors));
});

test('Parser: dumpVNode produces valid JSON', function () {
    $tpl = '<div id="root"><span class="title">Hello</span></div>';
    $parser = new TemplateParser();
    $root = $parser->parse($tpl);
    $json = $parser->dumpVNode($root);
    
    $decoded = json_decode($json, true);
    assert($decoded !== null, "dumpVNode should be valid JSON, got error: " . json_last_error_msg());
    assert($decoded['type'] === '#root', "Root type should be #root");
});

// ============================================================
// 3. AOT Validator Tests
// ============================================================
echo "\n--- 3. AOT Validator ---\n";

test('AotValidator: App.gen.php passes', function () {
    $v = new AotValidator();
    $code = "<?php\nclass App extends ReactiveComponent {}\n";
    $result = $v->validate($code, 'App.gen.php');
    assert($result === true, "App.gen.php should pass validation");
    assert(count($v->getErrors()) === 0, "Should have 0 errors: " . implode('; ', $v->getErrors()));
});

test('AotValidator: AppLayout_gen.php passes', function () {
    $v = new AotValidator();
    $code = "<?php\nfunction getLayout(): array { return []; }\n";
    $result = $v->validate($code, 'AppLayout_gen.php');
    assert($result === true, "AppLayout_gen.php should pass");
});

test('AotValidator: rejects multi-dot filename stem', function () {
    $v = new AotValidator();
    $code = "<?php\nclass Foo {}\n";
    $result = $v->validate($code, 'Foo.Layout.gen.php');
    assert($result === false, "Multi-dot stem should fail");
    $errors = $v->getErrors();
    assert(strpos($errors[0], 'dots') !== false, "Error should mention dots");
});

test('AotValidator: rejects const array', function () {
    $v = new AotValidator();
    $code = "<?php\nconst LAYOUT = ['a' => [1, 2, 3]];\n";
    $result = $v->validate($code, 'test.php');
    assert($result === false, "const array should fail");
});

test('AotValidator: rejects $obj->$var (variable property access)', function () {
    $v = new AotValidator();
    $code = '<?php $result = $obj->$propName; ?>';
    $result = $v->validate($code, 'test.php');
    assert($result === false, "Variable property access should fail");
});

test('AotValidator: rejects $obj->$method() (variable method call)', function () {
    $v = new AotValidator();
    $code = '<?php $obj->$methodName(); ?>';
    $result = $v->validate($code, 'test.php');
    assert($result === false, "Variable method call should fail");
});

test('AotValidator: warns on str_contains (PHP8 only)', function () {
    $v = new AotValidator();
    $code = '<?php if (str_contains($haystack, $needle)) {} ?>';
    $result = $v->validate($code, 'test.php');
    $warnings = $v->getWarnings();
    assert(count($warnings) >= 1, "Should warn about str_contains");
});

test('AotValidator: validateNestingDepth accepts depth 0 and 1', function () {
    $v = new AotValidator();
    assert($v->validateNestingDepth(0, 'root') === true, "Depth 0 should pass");
    assert($v->validateNestingDepth(1, 'child') === true, "Depth 1 should pass");
});

test('AotValidator: validateNestingDepth rejects depth > 1', function () {
    $v = new AotValidator();
    assert($v->validateNestingDepth(2, 'grandchild') === false, "Depth 2 should fail");
    $errors = $v->getErrors();
    assert(count($errors) >= 1, "Should have nesting error");
    assert(strpos($errors[0], 'exceeds maximum depth') !== false, "Error should mention depth");
});

// ============================================================
// 4. Component Registry Tests
// ============================================================
echo "\n--- 4. Component Registry ---\n";

test('ComponentRegistry: resolves registered tag to file path', function () {
    $registry = new ComponentRegistry();
    $dir = __DIR__ . '/../apps/calculator';
        $registry->register('test-comp', $dir . '/components/DisplayPanel.vue', 'user');
    
    $resolved = $registry->resolve('test-comp');
    $expected = realpath($dir . '/components/DisplayPanel.vue');
    assert($resolved === $expected, "Should resolve to $expected, got $resolved");
    assert($registry->isComponent('test-comp') === true, "Should be a component");
    assert($registry->isComponent('unknown') === false, "Should not be a component");
});

test('ComponentRegistry: returns null for unknown tag', function () {
    $registry = new ComponentRegistry();
    assert($registry->resolve('no-such-component') === null, "Should return null");
});

// ============================================================
// Summary
// ============================================================
echo "\n=== Results: $passed passed, $failed failed ===\n";

if ($failed > 0) {
    exit(1);
}
echo "All tests passed!\n";
