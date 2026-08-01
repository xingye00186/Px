# AOT foreach 按引用遍历写回失效 — 最小复现用例

对应框架踩坑案例库 [docs/agents/lessons.md](../docs/agents/lessons.md) **L5** 与
[coding-conventions.md](../docs/agents/coding-conventions.md) **L37** 规则。

## 症状

- calculator-ng exe 启动后窗口全黑（仅背景色 `#1C1C1E`）
- 日志：`#component(...) SKIPPED - instance=null`，`collected layers=0 elements=1`
- 事件循环随后崩溃：`The parameter 'object' must be 'object', got 'null'`
- **PHP CLI 完全正常**（fragment 树完整，46 元素），仅 AOT 编译产物异常

## 根因

Swoole Compiler（tpc.exe v0.4.3）对 `foreach ($obj->arr as &$v) { $v = ...; }` 的
AOT 转译存在缺陷：**按引用遍历对数组元素的修改不写回原数组**。

框架 `Application::patchComponentTree()` 正是用该写法展开组件（把含
`componentInstance` 的克隆节点写回 `children` 数组）。写回失效后：

1. 组件展开结果丢失 → RenderTreeManager 遍历到未展开的占位节点（`instance=null`）→ 子树全部跳过
2. 只剩根背景 → 整窗空白（全黑）
3. 二次 rebuild 时旧实例无法复用 → 全部重建 + 卸载 → `TransitionController->node` 为 null → TypeError

## 复现步骤

```bat
:: 1. CLI 运行（预期 DIRECT_OK / NESTED_OK；-r 显式调用 main()，因文件为 AOT 兼容格式）
D:\swoole_compiler\php.exe -r "require 'foreach_byref_repro.php'; main();"
type foreach_byref_out.txt

:: 2. AOT 编译并运行（预期 DIRECT_FAIL:["a","b","c"] / NESTED_FAIL）
build_repro.bat
foreach_byref_repro.exe
type foreach_byref_out.txt
```

## 实测输出

| 环境 | testDirect | testNested |
|------|-----------|------------|
| PHP CLI（D:\swoole_compiler\php.exe） | `DIRECT_OK` | `NESTED_OK` |
| AOT（tpc.exe v0.4.3 -O2，MSVC 编译） | `DIRECT_FAIL:["a","b","c"]` | `NESTED_FAIL` |

## 修复方案

被 AOT 编译的代码禁止 `foreach ($arr as &$v)` 修改数组元素，改为**索引遍历 + 整体赋值**：

```php
// ❌ AOT 下写回失效
foreach ($node->children as &$child) {
    $child = $replacement;
}

// ✅ AOT 下可靠（直接属性赋值不受影响）
$newChildren = $node->children;
foreach ($newChildren as $i => $child) {
    $newChildren[$i] = $replacement;
}
$node->children = $newChildren;
```

已落地修复：

- `framework/Core/Application.php` — `patchComponentTree()` 索引遍历 + 整体写回
- `framework/Paint/PaintPipeline.php` — `applyTextTransform()` capitalize 分支同类写法
- `framework/Animation/TransitionComponent.php` — `onUnmount()` 增加 `$node !== null` 防御（次因）
- `tools/aot-checker.php` — 新增 `aot_foreach_byref` 规则（ERROR 级，构建 Step 0.5 编译前拦截）

## 文件清单

| 文件 | 说明 |
|------|------|
| `foreach_byref_repro.php` | 最小复现源码（CLI 与 AOT 行为对比） |
| `build_repro.bat` | 一键 AOT 编译脚本（VS2022 + tpc.exe） |
| `foreach_byref_out.txt` | 运行输出（复现后生成） |
