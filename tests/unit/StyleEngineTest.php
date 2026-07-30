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

test('状态伪类不污染基态（:hover 拒绝而非忽略）', function () {
    // 根因：SelectorChecker 旧实现对 hover/focus/active 硬编码 return true，
    // 引擎生产激活后使 `.btn:hover{}` 声明无条件应用于基态。
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.btn { background:#000000; } .btn:hover { background:#FF0000; }'));
    $base = StyleEngine::declarationsFor(elem(['classes' => ['btn']]));
    assert_eq((int)($base['bg'] ?? -1), 0, '基态 bg = 黑 0（hover 声明不泄入基态）');
    // states 携带 hover 时才匹配（语义完备，不是一刀切）
    $hov = StyleEngine::declarationsFor(elem(['classes' => ['btn'], 'states' => ['hover']]));
    assert_true((int)($hov['bg'] ?? -1) !== 0, 'states=[hover] 时 hover 规则生效');
    // 其余状态伪类同理
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.f:focus { background:#00FF00; }'));
    assert_eq(count(StyleEngine::declarationsFor(elem(['classes' => ['f']]))), 0, ':focus 无状态不匹配');
    StyleEngine::reset();
});

test('结构伪类按真实 index 判定（与状态伪类区分）', function () {
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.it:first-child { background:#FF0000; }'));
    assert_true(isset(StyleEngine::declarationsFor(elem(['classes' => ['it'], 'index' => 1]))['bg']), 'index 1 命中 first-child');
    assert_true(!isset(StyleEngine::declarationsFor(elem(['classes' => ['it'], 'index' => 2]))['bg']), 'index 2 不命中');
    StyleEngine::reset();
});

test('pseudoStylesFor：状态伪类叠加声明（取代注册表 extractPseudoStyles，C2.9 前提）', function () {
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build(
        '.btn { background:#000000; } .btn:hover { background:#FF0000; } .btn:focus { color:#00FF00; }'
    ));
    $ps = StyleEngine::pseudoStylesFor(elem(['classes' => ['btn']]));
    assert_true(isset($ps['hover']), 'hover 叠加存在');
    assert_true(isset($ps['focus']), 'focus 叠加存在');
    assert_true(!isset($ps['active']), '未声明的 active 不产出');
    assert_true((int)($ps['hover']['bg'] ?? -1) !== 0, 'hover 叠加携带红背景');
    // 基态仍不被污染
    $base = StyleEngine::declarationsFor(elem(['classes' => ['btn']]));
    assert_eq((int)($base['bg'] ?? -1), 0, '基态 bg 仍为黑');
    // 其余条件仍须真实成立：类不符不产出
    $none = StyleEngine::pseudoStylesFor(elem(['classes' => ['other']]));
    assert_eq(count($none), 0, '类不符 → 无叠加');
    StyleEngine::reset();
});

test('pseudoStylesFor 尊重组合子与结构条件', function () {
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build('.p .b:hover { background:#FF0000; }'));
    $p = elem(['classes' => ['p']]);
    $hit = StyleEngine::pseudoStylesFor(elem(['classes' => ['b'], 'ancestors' => [$p]]));
    assert_true(isset($hit['hover']), '祖先成立 → hover 叠加产出');
    $miss = StyleEngine::pseudoStylesFor(elem(['classes' => ['b']]));
    assert_eq(count($miss), 0, '祖先不成立 → 无叠加（组合子仍真实判定）');
    StyleEngine::reset();
});

test('端到端：引擎 hover 叠加经 StyleRecalcPass 持久化到 VNode（非丢弃）', function () {
    // 此前 resolve() 的 by-ref pseudoStyles 在 StyleRecalcPass 内为局部变量而被
    // 丢弃，RTM 只能回落注册表重算（生产恒空）——即引擎叠加在正常
    // 路径不可达。修复：VNode::$pseudoStyles 持久化 + RTM 优先消费。
    StyleEngine::reset();
    StyleEngine::registerCss('.eb { background:#000000; } .eb:hover { background:#FF0000; }');
    $node = \Px\Dom\VNode::h('div', ['class' => 'eb', 'style' => ''], 'x');
    (new \Px\Css\StyleRecalcPass())->recalc($node);
    assert_true(!empty($node->pseudoStyles), 'VNode.pseudoStyles 已持久化（不再丢弃）');
    assert_true(isset($node->pseudoStyles['hover']), 'hover 叠加存在');
    assert_true((int)($node->pseudoStyles['hover']['bg'] ?? -1) !== 0, 'hover 叠加携带红背景');
    // 基态仍为黑
    assert_eq($node->computedStyle->backgroundColor->toBgr(), 0, '基态 bg 仍为黑');
    StyleEngine::reset();
});

test('规则特征门控 usesSiblingRules / usesAncestorRules（对标 RuleFeatureSet）', function () {
    StyleEngine::reset();
    assert_true(!StyleEngine::usesSiblingRules(), '空引擎无兄弟特征');
    assert_true(!StyleEngine::usesAncestorRules(), '空引擎无祖先特征');
    StyleEngine::registerCss('.a { width:10px; }');
    assert_true(!StyleEngine::usesSiblingRules(), '单类规则不置兄弟特征');
    StyleEngine::registerCss('.p .c { width:20px; }');
    assert_true(StyleEngine::usesAncestorRules(), '后代规则置祖先特征');
    assert_true(!StyleEngine::usesSiblingRules(), '后代规则不置兄弟特征');
    StyleEngine::registerCss('.x + .y { width:30px; }');
    assert_true(StyleEngine::usesSiblingRules(), '相邻兄弟规则置兄弟特征');
    StyleEngine::reset();
    assert_true(!StyleEngine::usesSiblingRules(), 'reset 清特征');
});

test('单 VNode 子的子树不再被样式重算静默跳过', function () {
    // VNode::h(t, p, $child) 的 children 是 object 非数组；旧
    // `is_array(...) ? ... : []` 守卫使整棵子树 computedStyle 恒 null。
    StyleEngine::reset();
    StyleEngine::registerCss('.deep { width:123px; }');
    $leaf = \Px\Dom\VNode::h('div', ['class' => 'deep', 'style' => ''], 'x');
    $wrap = \Px\Dom\VNode::h('#root', [], $leaf);   // 单 VNode 子
    (new \Px\Css\StyleRecalcPass())->recalc($wrap);
    assert_true($leaf->computedStyle !== null, '单子形态下子树已被遇到');
    assert_eq((int)$leaf->computedStyle->width->toPx(), 123, '.deep 规则已应用 → 123');
    StyleEngine::reset();
});

test('C2.7 倒排索引：开/关结果一致且候选数下降（对标 Blink RuleSet）', function () {
    StyleEngine::reset();
    assert_true(!StyleEngine::isIndexEnabled(), '索引默认关闭（计划要求）');
    // 4 条不相关规则 + 1 条命中规则；元素仅属 .hit 桶 + universal
    StyleEngine::registerCss(
        '.miss1 { width:10px; } .miss2 { width:20px; } #nope { width:30px; }'
        . ' section { width:40px; } .hit { width:99px; }'
    );
    $el = ['tag' => 'div', 'id' => null, 'classes' => ['hit'], 'attrs' => [],
        'index' => 1, 'ancestors' => [], 'prevSiblings' => [], 'states' => []];

    StyleEngine::setIndexEnabled(false);
    StyleEngine::resetSelectorMatchCount();
    $off = StyleEngine::declarationsFor($el);
    $cOff = StyleEngine::selectorMatchCount();

    StyleEngine::setIndexEnabled(true);
    StyleEngine::resetSelectorMatchCount();
    $on = StyleEngine::declarationsFor($el);
    $cOn = StyleEngine::selectorMatchCount();
    StyleEngine::setIndexEnabled(false);

    assert_eq(json_encode($off), json_encode($on), '开/关声明完全一致');
    assert_true($cOn < $cOff, "候选数下降（off=$cOff on=$cOn）");
    assert_eq($cOn, 1, '仅 .hit 桶内 1 个候选被测');
    StyleEngine::reset();
});

test('C2.7 倒排索引：多类/祖先/兄弟组合子不漏命中', function () {
    // classBuckets 仅用 subject compound 首类作键，需验证多类选择器与
    // 组合子规则（subject 仍为最右 compound）在索引下不被漏筛。
    StyleEngine::reset();
    StyleEngine::registerCss(
        '.a.b { width:11px; } .anc .t { height:22px; } .prev + .t { top:33px; }'
    );
    $mk = function (array $o): array {
        return array_merge(['tag' => 'div', 'id' => null, 'classes' => [], 'attrs' => [],
            'index' => 1, 'ancestors' => [], 'prevSiblings' => [], 'states' => []], $o);
    };
    $anc = $mk(['classes' => ['anc']]);
    $prev = $mk(['classes' => ['prev']]);
    $els = [
        $mk(['classes' => ['b', 'a']]),                                  // 多类（序反）
        $mk(['classes' => ['t'], 'ancestors' => [$anc]]),                 // 后代
        $mk(['classes' => ['t'], 'prevSiblings' => [$prev], 'index' => 2]), // 相邻兄弟
    ];
    foreach ($els as $i => $el) {
        StyleEngine::setIndexEnabled(false);
        $off = StyleEngine::declarationsFor($el);
        StyleEngine::setIndexEnabled(true);
        $on = StyleEngine::declarationsFor($el);
        StyleEngine::setIndexEnabled(false);
        assert_eq(json_encode($off), json_encode($on), "元素#$i 开/关一致");
        assert_true(!empty($on), "元素#$i 索引下仍命中（不漏筛）");
    }
    StyleEngine::reset();
});

$exitCode = print_summary();
exit($exitCode);
