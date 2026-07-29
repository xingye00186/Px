<?php
/**
 * CascadeResolver 单元测试（C1.1）
 *
 * 覆盖完整六槽位序矩阵、同槽位 specificity 序、源顺序 tie-break、
 * 独立 longhand 不被简写覆盖、animation 槽预留不参与。纯函数单测。
 *
 * Usage: php tests/unit/CascadeResolverTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\CascadeResolver as CR;

echo "========================================\n";
echo " CascadeResolver 单元测试（层叠决策）\n";
echo "========================================\n\n";

/** 便捷：构造一条声明 */
function decl(string $prop, $val, string $origin = CR::ORIGIN_AUTHOR, bool $imp = false, array $spec = [0, 0, 0, 0], ?int $order = null): array
{
    $d = ['property' => $prop, 'value' => $val, 'origin' => $origin, 'important' => $imp, 'specificity' => $spec];
    if ($order !== null) $d['order'] = $order;
    return $d;
}

// =============================================================
// 1. origin/importance 六槽位序
// =============================================================
echo "--- 1. 六槽位序 ---\n";

test('author normal 覆盖 UA normal', function () {
    $r = CR::resolve([
        decl('color', 'ua', CR::ORIGIN_UA),
        decl('color', 'author', CR::ORIGIN_AUTHOR),
    ]);
    assert_eq($r['color'], 'author', 'author normal > UA normal');
});

test('inline normal 覆盖 author normal（不论 specificity）', function () {
    $r = CR::resolve([
        decl('color', 'author', CR::ORIGIN_AUTHOR, false, [0, 1, 0, 0]), // 高 specificity 的 author
        decl('color', 'inline', CR::ORIGIN_INLINE, false, [0, 0, 0, 0]),  // inline 无 specificity
    ]);
    assert_eq($r['color'], 'inline', 'inline normal 胜过任何 author 选择器');
});

test('author !important 覆盖 inline normal', function () {
    $r = CR::resolve([
        decl('color', 'inline', CR::ORIGIN_INLINE, false),
        decl('color', 'author-imp', CR::ORIGIN_AUTHOR, true),
    ]);
    assert_eq($r['color'], 'author-imp', 'author !important > inline normal');
});

test('inline !important 覆盖 author !important', function () {
    $r = CR::resolve([
        decl('color', 'author-imp', CR::ORIGIN_AUTHOR, true),
        decl('color', 'inline-imp', CR::ORIGIN_INLINE, true),
    ]);
    assert_eq($r['color'], 'inline-imp', 'inline !important > author !important');
});

test('UA !important 最高（覆盖 inline !important）', function () {
    $r = CR::resolve([
        decl('color', 'inline-imp', CR::ORIGIN_INLINE, true),
        decl('color', 'ua-imp', CR::ORIGIN_UA, true),
    ]);
    assert_eq($r['color'], 'ua-imp', 'UA !important 为最高槽位');
});

test('全六槽位混合 → UA !important 胜', function () {
    $r = CR::resolve([
        decl('color', 'ua', CR::ORIGIN_UA),
        decl('color', 'author', CR::ORIGIN_AUTHOR),
        decl('color', 'inline', CR::ORIGIN_INLINE),
        decl('color', 'author-imp', CR::ORIGIN_AUTHOR, true),
        decl('color', 'inline-imp', CR::ORIGIN_INLINE, true),
        decl('color', 'ua-imp', CR::ORIGIN_UA, true),
    ]);
    assert_eq($r['color'], 'ua-imp', '六槽位全混合最高为 UA !important');
});

test('UA normal 是最低槽位（任何 author normal 都覆盖它）', function () {
    $r = CR::resolve([
        decl('color', 'ua', CR::ORIGIN_UA, false, [0, 0, 0, 1]),
        decl('color', 'author', CR::ORIGIN_AUTHOR, false, [0, 0, 0, 0]),
    ]);
    assert_eq($r['color'], 'author', 'UA normal 最低');
});

// =============================================================
// 2. 同槽位 specificity 序
// =============================================================
echo "\n--- 2. 同槽位 specificity ---\n";

test('同为 author normal：ID > class', function () {
    $r = CR::resolve([
        decl('color', 'class', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0]),
        decl('color', 'id', CR::ORIGIN_AUTHOR, false, [0, 1, 0, 0]),
    ]);
    assert_eq($r['color'], 'id', 'ID specificity > class');
});

test('同为 author normal：class > element', function () {
    $r = CR::resolve([
        decl('color', 'element', CR::ORIGIN_AUTHOR, false, [0, 0, 0, 1]),
        decl('color', 'class', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0]),
    ]);
    assert_eq($r['color'], 'class', 'class specificity > element');
});

test('高 specificity 但低槽位仍败给低 specificity 高槽位', function () {
    $r = CR::resolve([
        decl('color', 'author-id', CR::ORIGIN_AUTHOR, false, [0, 1, 0, 0]), // author normal，高 spec
        decl('color', 'author-imp', CR::ORIGIN_AUTHOR, true, [0, 0, 0, 1]), // author !important，低 spec
    ]);
    assert_eq($r['color'], 'author-imp', '槽位优先于 specificity');
});

test('specificity 字典序：多组件比较', function () {
    $r = CR::resolve([
        decl('color', 'a', CR::ORIGIN_AUTHOR, false, [0, 1, 2, 0]),
        decl('color', 'b', CR::ORIGIN_AUTHOR, false, [0, 1, 3, 0]),
    ]);
    assert_eq($r['color'], 'b', '[0,1,3,0] > [0,1,2,0]');
});

// =============================================================
// 3. 源顺序 tie-break
// =============================================================
echo "\n--- 3. 源顺序 tie-break ---\n";

test('同槽位同 specificity：后者胜（显式 order）', function () {
    $r = CR::resolve([
        decl('color', 'first', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0], 0),
        decl('color', 'second', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0], 1),
    ]);
    assert_eq($r['color'], 'second', '同权后者胜');
});

test('同槽位同 specificity：后者胜（隐式数组序）', function () {
    $r = CR::resolve([
        decl('color', 'first', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0]),
        decl('color', 'second', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0]),
    ]);
    assert_eq($r['color'], 'second', '默认按数组下标，后者胜');
});

test('前者高 order 不被后者低 order 覆盖', function () {
    $r = CR::resolve([
        decl('color', 'high-order', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0], 5),
        decl('color', 'low-order', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0], 2),
    ]);
    assert_eq($r['color'], 'high-order', 'order 5 > order 2');
});

// =============================================================
// 4. 多属性独立层叠（longhand 不被无关简写覆盖）
// =============================================================
echo "\n--- 4. 多属性独立 ---\n";

test('不同属性各自独立层叠', function () {
    $r = CR::resolve([
        decl('color', 'red', CR::ORIGIN_AUTHOR),
        decl('background', 'blue', CR::ORIGIN_AUTHOR),
        decl('color', 'green', CR::ORIGIN_INLINE),
    ]);
    assert_eq($r['color'], 'green', 'color 走 inline');
    assert_eq($r['background'], 'blue', 'background 独立保留');
});

test('独立 longhand 与其他属性互不干扰', function () {
    $r = CR::resolve([
        decl('padding-left', '10', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0]),
        decl('padding-right', '20', CR::ORIGIN_AUTHOR, false, [0, 0, 1, 0]),
        decl('padding-left', '30', CR::ORIGIN_AUTHOR, false, [0, 1, 0, 0]),
    ]);
    assert_eq($r['padding-left'], '30', 'padding-left 高 spec 胜');
    assert_eq($r['padding-right'], '20', 'padding-right 不受影响');
});

// =============================================================
// 5. animation 槽预留（不参与，视为 author normal 之上 inline 之下）
// =============================================================
echo "\n--- 5. animation 槽预留 ---\n";

test('animation origin 归入预留槽（高于 author normal）', function () {
    $r = CR::resolve([
        decl('color', 'author', CR::ORIGIN_AUTHOR, false),
        decl('color', 'anim', CR::ORIGIN_ANIMATION, false),
    ]);
    assert_eq($r['color'], 'anim', 'animation 槽 > author normal（预留序）');
});

test('animation 低于 inline normal', function () {
    $r = CR::resolve([
        decl('color', 'anim', CR::ORIGIN_ANIMATION, false),
        decl('color', 'inline', CR::ORIGIN_INLINE, false),
    ]);
    assert_eq($r['color'], 'inline', 'inline normal > animation（预留序）');
});

// =============================================================
// 6. 边界
// =============================================================
echo "\n--- 6. 边界 ---\n";

test('空声明集返回空数组', function () {
    assert_eq(CR::resolve([]), [], '空输入');
});

test('缺 property 的声明被跳过', function () {
    $r = CR::resolve([
        ['value' => 'x'],
        decl('color', 'ok'),
    ]);
    assert_eq($r['color'] ?? null, 'ok', '有效声明保留');
    assert_eq(count($r), 1, '无 property 声明被跳过');
});

test('未知 origin 归入 author', function () {
    $r = CR::resolve([
        decl('color', 'author', CR::ORIGIN_AUTHOR),
        decl('color', 'unknown', 'weird-origin'),
    ]);
    assert_eq($r['color'], 'unknown', '未知 origin 按 author normal 处理，后者胜');
});

test('compareSpecificity 相等返 0', function () {
    assert_eq(CR::compareSpecificity([0, 0, 1, 0], [0, 0, 1, 0]), 0, '相等 0');
});

test('compareSpecificity A>B 返 1', function () {
    assert_eq(CR::compareSpecificity([0, 1, 0, 0], [0, 0, 1, 0]), 1, 'ID > class');
});

test('compareSpecificity A<B 返 -1', function () {
    assert_eq(CR::compareSpecificity([0, 0, 0, 1], [0, 0, 1, 0]), -1, 'element < class');
});

$exitCode = print_summary();
exit($exitCode);
