<?php
/**
 * 最小复现：AOT 编译下 foreach 按引用遍历写回失效
 *
 * 复现方式：
 *   1. CLI 运行（预期 DIRECT_OK / NESTED_OK）：
 *        D:\swoole_compiler\php.exe -r "require 'foreach_byref_repro.php'; main();"
 *      然后查看 foreach_byref_out.txt
 *   2. AOT 编译运行（预期 DIRECT_FAIL / NESTED_FAIL）：
 *       build_repro.bat
 *      然后运行 foreach_byref_repro.exe 并查看 foreach_byref_out.txt
 *
 * 结论：同一份代码，PHP 解释器正常，tpc.exe v0.4.3 AOT 转译后
 *       foreach 按引用遍历对数组元素的修改不写回原数组。
 *       参见 README.md（症状/根因/修复）。
 *
 * 注意：本文件为 AOT 兼容（执行代码必须在 main() 内），
 *       CLI 验证需通过 -r 入口显式调用 main()。
 */

class ReproNode
{
    public array $children = [];
}

function testDirect(): string
{
    $n = new ReproNode();
    $n->children = ['a', 'b', 'c'];
    foreach ($n->children as &$child) {
        $child = 'X' . $child;
    }
    unset($child);
    return ($n->children[0] === 'Xa' && $n->children[1] === 'Xb' && $n->children[2] === 'Xc')
        ? 'DIRECT_OK' : 'DIRECT_FAIL:' . json_encode($n->children);
}

function testNested(): string
{
    // 模拟 VNode 树：root->children[0]->children = 组件节点
    // （对应 Application::patchComponentTree 展开组件的真实路径）
    $root = new ReproNode();
    $inner = new ReproNode();
    $root->children = [$inner];
    $inner->children = [new ReproNode(), new ReproNode()];

    $patch = function (ReproNode $node): void {
        foreach ($node->children as &$child) {
            if ($child instanceof ReproNode) {
                $child = new ReproNode(); // 模拟 expanded clone（携带 componentInstance）
            }
        }
        unset($child);
    };
    $patch($root);
    return ($root->children[0] !== $inner) ? 'NESTED_OK' : 'NESTED_FAIL';
}

function main(): int
{
    file_put_contents(__DIR__ . '/foreach_byref_out.txt', testDirect() . "\n" . testNested() . "\n");
    return 0;
}
