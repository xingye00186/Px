<?php
/**
 * StyleEngine 运行时规则引擎测试（C2.5-full 第一增量）
 *
 * 对标 Blink StyleEngine：消费 gen 烘焙的 StyleSheetContents RuleData[]，
 * SelectorChecker 全 AST 匹配 + CascadeResolver 层叠排序 → 声明映射。
 * 定位：替代生产恒空 ThemeProvider 注册表（C2.9 前提卡点）；本批纯函数
 * 核心，烘焙通道保持权威，等价门控通过后再切换。
 *
 * Usage: php tests/unit/StyleEngineTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\StyleEngine;
use Px\Css\StyleSheetContents;

echo "========================================\n";
echo " StyleEngine 运行时规则引擎（C2.5）\n";
echo "========================================\n\n";

function elem(array $o = []): array {
    return array_merge([
        'tag' => 'div', 'id' => null, 'classes' => [], 'attrs' => [],
        'index' => 1, 'ancestors' => [], 'prevSiblings' => [],
    ], $o);
}

test('类规则匹配产出声明（经 StyleSheetContents 构建）', function () {
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.btn { width: 100px; background: #FF0000; }'));
    $d = StyleEngine::declarationsFor(elem(['classes' => ['btn']]));
    assert_true(isset($d['width']), 'width 键存在');
    assert_true(isset($d['bg']), 'bg 键存在');
    // 不匹配的元素为空
    $d2 = StyleEngine::declarationsFor(elem(['classes' => ['other']]));
    assert_eq(count($d2), 0, '不匹配 → 空声明');
});

test('specificity 层叠：类 > 通配（声明序无关）', function () {
    StyleEngine::reset();
    // 类规则先注册（order 0），* 后注册（order 1）——若按源序则 * 覆盖；
    // 正确层叠按 specificity 类胜。
    StyleEngine::register(StyleSheetContents::build('.x { width: 200px; } * { width: 50px; }'));
    $d = StyleEngine::declarationsFor(elem(['classes' => ['x']]));
    assert_true(isset($d['width']), 'width 存在');
    // width 值为 CssLength/int/字符串形态，统一转 px 判定
    $w = $d['width'];
    $px = $w instanceof \Px\Css\CssLength ? (int)$w->toPx() : (int)$w;
    assert_eq($px, 200, '类 (0,0,1,0) 胜通配 (0,0,0,0) → 200');
});

test('等特异性源序后写胜', function () {
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.a { width: 10px; } .a { width: 30px; }'));
    $d = StyleEngine::declarationsFor(elem(['classes' => ['a']]));
    $w = $d['width'];
    $px = $w instanceof \Px\Css\CssLength ? (int)$w->toPx() : (int)$w;
    assert_eq($px, 30, '同 (0,0,1,0) 后写 30 胜');
});

test('后代选择器经 ancestors 上下文匹配', function () {
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.gp .gc { width: 160px; }'));
    $gp = elem(['classes' => ['gp']]);
    $mid = elem(['classes' => ['gmid']]);
    $hit = StyleEngine::declarationsFor(elem(['classes' => ['gc'], 'ancestors' => [$gp, $mid]]));
    assert_true(isset($hit['width']), '.gp .gc 跨中间层命中');
    $miss = StyleEngine::declarationsFor(elem(['classes' => ['gc'], 'ancestors' => [$mid]]));
    assert_eq(count($miss), 0, '无 .gp 祖先不命中');
});

test('真实 gen RuleData 消费（css-test Case001）', function () {
    $genFile = dirname(__DIR__, 2) . '/apps/css-test/gen/Case001WrapperXComponent.php';
    if (!file_exists($genFile)) { echo "  [SKIP] gen 不存在\n"; return; }
    require_once $genFile;
    $rules = \Case001WrapperXComponent::styleSheetContents();
    assert_true(count($rules) > 0, 'gen 规则非空');
    StyleEngine::reset();
    StyleEngine::register($rules);
    // .test-header → margin-bottom:16px
    $d = StyleEngine::declarationsFor(elem(['classes' => ['test-header']]));
    assert_true(isset($d['marginBottom']) || isset($d['margin-bottom']) || isset($d['margin']), 'test-header 命中 margin 族声明');
});

test('端到端接线：StyleRecalcPass 消费 StyleEngine（非死代码）', function () {
    // 引擎空 → 零行为变化（生产态）
    StyleEngine::reset();
    $t1 = \Px\Dom\VNode::h('div', ['class' => 'eng-x', 'style' => ''], 'x');
    (new \Px\Css\StyleRecalcPass())->recalc($t1);
    $w0 = (int)$t1->computedStyle->width->toPx();

    // 引擎注册后 → 声明生效
    StyleEngine::register(StyleSheetContents::build('.eng-x { width: 123px; }'));
    $t2 = \Px\Dom\VNode::h('div', ['class' => 'eng-x', 'style' => ''], 'x');
    (new \Px\Css\StyleRecalcPass())->recalc($t2);
    assert_eq((int)$t2->computedStyle->width->toPx(), 123, '引擎规则经 StyleRecalcPass 生效 → 123');
    assert_true($w0 !== 123, '引擎空时不生效（零行为变化保证）');

    // 后代组合子经真实 VNode 树上下文
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.eng-p .eng-c { width: 77px; }'));
    $child = \Px\Dom\VNode::h('div', ['class' => 'eng-c', 'style' => ''], 'x');
    $tree = \Px\Dom\VNode::h('div', ['class' => 'eng-p'], [
        \Px\Dom\VNode::h('div', ['class' => 'eng-mid'], [$child]),
    ]);
    (new \Px\Css\StyleRecalcPass())->recalc($tree);
    assert_eq((int)$child->computedStyle->width->toPx(), 77, '.eng-p .eng-c 跨中间层经元素上下文匹配');
    StyleEngine::reset();
});

test('registerComponentRules：组件规则注册 + 幂等 + 无方法安全（C2.5 生产激活入口）', function () {
    $genFile = dirname(__DIR__, 2) . '/apps/css-test/gen/Case001WrapperXComponent.php';
    if (!file_exists($genFile)) { echo "  [SKIP] gen 不存在\n"; return; }
    require_once $genFile;
    StyleEngine::reset();
    assert_eq(StyleEngine::ruleCount(), 0, '初始空');
    $comp = new \Case001WrapperXComponent();
    StyleEngine::registerComponentRules($comp);
    $n = StyleEngine::ruleCount();
    assert_true($n > 0, '组件规则已注册（>0）');
    // 幂等：同类再注不膨胀（v-for 多实例保护）
    StyleEngine::registerComponentRules($comp);
    assert_eq(StyleEngine::ruleCount(), $n, '同类幂等，规则不重复膨胀');
    // 无 styleSheetContents 的组件不崩不变
    $plain = new class extends \Px\Component\ReactiveComponent {
        public function render(): \Px\Dom\VNode { return \Px\Dom\VNode::h('div', [], 'x'); }
        public function setBindValue(string $k, string $v): void {}
        public function getBindValue(string $k): string { return ''; }
        public function onMount(): void {}
        public function dispatchClick(string $h, ?string $a = null): void {}
    };
    StyleEngine::registerComponentRules($plain);
    assert_eq(StyleEngine::ruleCount(), $n, '无 styleSheetContents 组件安全无变');
    StyleEngine::reset();
});

$exitCode = print_summary();
exit($exitCode);
