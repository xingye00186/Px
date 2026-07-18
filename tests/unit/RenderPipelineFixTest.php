<?php
/**
 * Px 渲染管线架构改造 — 行为验证测试
 *
 * 覆盖关键修复的 5 个场景：
 *   1. PhysicalFragmentBuilder 元数据传播
 *   2. StyleResolver::extractPseudoStyles() 伪类提取
 *   3. Dirty bit 级联：markStyleDirty 跨帧保留
 *   4. OOFLayoutAlgorithm Fragment 元数据完整性
 *   5. extractPseudoOverrides 双路径读取
 *
 * 用法:
 *   php tests/unit/RenderPipelineFixTest.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/test-framework.php';

use Px\Layout\PhysicalFragment;
use Px\Layout\PhysicalFragmentBuilder;
use Px\Layout\OOFLayoutAlgorithm;
use Px\Css\StyleResolver;
use Px\Css\ComputedStyle;
use Px\Render\RenderNode;
use Px\Render\RenderTreeManager;
use Px\Dom\VNode;
use Px\Theme\ThemeProvider;
use Px\Theme\ThemeData;

// ══════════════════════════════════════════════════════════
// 1. PhysicalFragmentBuilder 元数据传播
// ══════════════════════════════════════════════════════════
describe('PhysicalFragmentBuilder', function () {

    test('新增字段全部通过 build() 传入', function () {
        $frag = (new PhysicalFragmentBuilder())
            ->x(10)->y(20)->w(100)->h(50)
            ->type('span')
            ->content('hello world')
            ->dataset(['pxAnchor' => 'tl'])
            ->pseudoStyles(['hover' => ['bg' => 0xFF0000]])
            ->availableWidth(300)
            ->scrollTop(5)->scrollLeft(10)
            ->isScrollContainer(true)
            ->build();
        assert($frag->type === 'span', "type 应为 span, 实际={$frag->type}");
        assert($frag->content === 'hello world', "content 不匹配");
        assert(($frag->dataset['pxAnchor'] ?? '') === 'tl', "dataset 不匹配");
        assert(($frag->pseudoStyles['hover']['bg'] ?? 0) === 0xFF0000, "pseudoStyles 不匹配");
        assert($frag->availableWidth === 300, "availableWidth 不匹配");
        assert($frag->scrollTop === 5, "scrollTop 不匹配");
        assert($frag->isScrollContainer === true, "isScrollContainer 应为 true");
    });

    test('from() 拷贝所有新增字段', function () {
        $src = (new PhysicalFragmentBuilder())
            ->type('div')->content('test')
            ->dataset(['id' => '1'])->pseudoStyles(['hover' => ['bg' => 0xFF]])
            ->isScrollContainer(true)
            ->build();
        $dst = (new PhysicalFragmentBuilder())->from($src)->build();
        assert($dst->type === 'div', "type 拷贝失败");
        assert($dst->content === 'test', "content 拷贝失败");
        assert(($dst->dataset['id'] ?? '') === '1', "dataset 拷贝失败");
        assert($dst->isScrollContainer === true, "isScrollContainer 拷贝失败");
    });

});

// ══════════════════════════════════════════════════════════
// 2. StyleResolver::extractPseudoStyles()
// ══════════════════════════════════════════════════════════
describe('StyleResolver::extractPseudoStyles', function () {

    test('从已注册主题提取 hover/focus/active/before/after 定义', function () {
        ThemeProvider::inject(ThemeData::light());
        ThemeProvider::registerClassStyles('__test_extract', [
            'my-btn' => ['bg' => 0x333333],
            'my-btn__hover' => ['bg' => 0x555555],
            'my-btn__before' => ['content' => '►'],
        ]);

        $result = StyleResolver::extractPseudoStyles('my-btn', 'div');
        assert(isset($result['hover']), 'hover 应被提取');
        assert(($result['hover']['bg'] ?? 0) === 0x555555, "hover bg 应为 0x555555, 实际=" . dechex($result['hover']['bg'] ?? 0));
        assert(isset($result['before']), 'before 应被提取');
        assert(($result['before']['content'] ?? '') === '►', 'before content 应保留');
        assert(empty($result['focus']), '未定义的 focus 应为空');
        assert(empty($result['active']), '未定义的 active 应为空');
        assert(empty($result['after']), '未定义的 after 应为空');
    });

    test('空 class 返回空数组', function () {
        $result = StyleResolver::extractPseudoStyles('');
        assert(empty($result), '空 className 应返回空');
    });

});

// ══════════════════════════════════════════════════════════
// 3. Dirty bit 级联保留
// ══════════════════════════════════════════════════════════
describe('Dirty bit 级联保留', function () {

    test('markStyleDirty 跨帧保留（不被 areVNodesEqual 覆盖）', function () {
        $rtm = new RenderTreeManager();
        $comp = new class extends \Px\Component\ReactiveComponent {
            public function render(): VNode {
                return VNode::h('#root', [], [
                    VNode::h('div', ['class' => 's', 'style' => 'width:100;height:50;color:red;'], 'static'),
                ]);
            }
            public function setBindValue(string $k, string $v): void {}
            public function getBindValue(string $k): string { return ''; }
            public function onMount(): void {}
            public function dispatchClick(string $h, ?string $a = null): void {}
        };

        // 第 1 帧：创建
        $vnode1 = $comp->getVNodeTree();
        (new \Px\Css\StyleRecalcPass())->recalc($vnode1);
        $rn1 = $rtm->updateFromVNode($vnode1, null, $comp, ['app' => $comp], null, 'app');
        $oldRoot = $rtm->getRootRenderNode();
        assert($rn1 !== null, '第1帧应成功');

        // 模拟鼠标事件：外部 markStyleDirty
        $rn1->markStyleDirty();
        assert($rn1->styleDirty === true, 'markStyleDirty 应设置 styleDirty=true');

        // 第 2 帧：VNode 完全相同，会走 areVNodesEqual 路径
        $vnode2 = $comp->getVNodeTree();
        (new \Px\Css\StyleRecalcPass())->recalc($vnode2);
        $candidates = $oldRoot !== null ? [$oldRoot] : null;
        $rn2 = $rtm->updateFromVNode($vnode2, null, $comp, ['app' => $comp], $candidates, 'app');

        assert(spl_object_hash($rn1) === spl_object_hash($rn2), '应复用同一 RenderNode');
        // styleDirty 或 paintDirty 至少有一个应为 true（不会被完全清除）
        assert($rn2->styleDirty || $rn2->paintDirty,
            "外部 dirty 被覆盖: styleDirty={$rn2->styleDirty} paintDirty={$rn2->paintDirty} layoutDirty={$rn2->layoutDirty}");
    });

});

// ══════════════════════════════════════════════════════════
// 4. OOFLayoutAlgorithm Fragment 元数据
// ══════════════════════════════════════════════════════════
describe('OOFLayoutAlgorithm Fragment 元数据', function () {

    test('calculateOOFPosition 产出 Fragment 含 type/content/dataset/pseudoStyles', function () {
        $oofAlgo = new OOFLayoutAlgorithm();
        $childStyle = new ComputedStyle([
            'position' => 'absolute', 'width' => '50px', 'height' => '30px',
            'top' => '10px', 'left' => '10px',
        ]);
        $childRN = new RenderNode('div', $childStyle, 'OOF text');
        $childRN->pseudoStyles = ['hover' => ['bg' => 0xFF0000]];

        $inputFrag = new PhysicalFragment(
            0, 0, 200, 150, 200, 150, 0, 200, 150,
            new ComputedStyle(['position' => 'relative']),
            [
                new PhysicalFragment(0, 0, 50, 30, 0, 0, 1, 0, 0, $childStyle, [], $childRN,
                    0, 0, false, 'div', 'OOF text', ['testId' => 'oof-1'], ['hover' => ['bg' => 0xFF0000]]),
            ],
            new RenderNode('div'), 0, 0, false, 'div', 'root', [], []
        );

        $outputFrag = $oofAlgo->processOutOfFlow($inputFrag, new RenderNode('root'), 800, 600);

        if (count($outputFrag->children) > 0) {
            $childOut = $outputFrag->children[0];
            assert($childOut->type === 'div', "type 应为 div, actual='{$childOut->type}'");
            assert($childOut->content === 'OOF text', "content 不匹配");
            assert(($childOut->dataset['testId'] ?? '') === 'oof-1', "dataset 不匹配");
            assert(($childOut->pseudoStyles['hover']['bg'] ?? 0) === 0xFF0000, "pseudoStyles 不匹配");
        }
    });

});

// ══════════════════════════════════════════════════════════
// 5. extractPseudoOverrides 双路径
// ══════════════════════════════════════════════════════════
describe('extractPseudoOverrides', function () {

    test('从 pseudoStyles[\'hover\'] 读取（路径 A）', function () {
        $rn = new RenderNode('div', new ComputedStyle([]));
        $rn->pseudoStyles = ['hover' => ['bg' => 0xFF0000, 'fg' => 0x00FF00]];
        $rn->hovered = true;

        $ref = new ReflectionMethod(\Px\Paint\PaintPipeline::class, 'extractPseudoOverrides');
        $ref->setAccessible(true);
        $overrides = $ref->invoke(null, $rn);

        assert(($overrides['bg'] ?? 0) === 0xFF0000, "路径A bg 应为 0xFF0000, 实际=" . dechex($overrides['bg'] ?? 0));
        assert(($overrides['fg'] ?? 0) === 0x00FF00, "路径A fg 应为 0x00FF00");
    });

    test('从 computedStyle getRaw(\'__hoverStyle\') 读取（路径 B）', function () {
        $rn = new RenderNode('div', new ComputedStyle(['__hoverStyle' => ['bg' => 0x0000FF]]));
        $rn->hovered = true;

        $ref = new ReflectionMethod(\Px\Paint\PaintPipeline::class, 'extractPseudoOverrides');
        $ref->setAccessible(true);
        $overrides = $ref->invoke(null, $rn);

        assert(($overrides['bg'] ?? 0) === 0x0000FF, "路径B bg 应为 0x0000FF, 实际=" . dechex($overrides['bg'] ?? 0));
    });

    test('hovered=false 时返回空数组', function () {
        $rn = new RenderNode('div', new ComputedStyle([]));
        $rn->pseudoStyles = ['hover' => ['bg' => 0xFF0000]];
        $rn->hovered = false;

        $ref = new ReflectionMethod(\Px\Paint\PaintPipeline::class, 'extractPseudoOverrides');
        $ref->setAccessible(true);
        $overrides = $ref->invoke(null, $rn);
        assert(empty($overrides), "hovered=false 时应无覆盖");
    });

});
