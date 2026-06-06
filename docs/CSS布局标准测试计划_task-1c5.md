# CSS 布局标准测试套件（新版）

## Context

当前 Bilibili 页面布局完全错误，但 420 个现有测试全部通过。

测试通过但布局错误的原因：
1. 现有测试只测单一简单场景，不覆盖真实世界的 CSS 属性组合
2. 没有精确预期坐标值（只有范围断言如 h>700）
3. 缺少失败时的布局树 dump
4. 不同项目（bilibili/calculator/list-test）有不同的布局需求，单一测试文件无法覆盖

## 核心方案

**按 CSS 属性族分文件**，每族一个测试文件，共享辅助函数：

```
tests/unit/Layout/
├── LayoutBase.php          # 共享: makeNode, treeToText, runResolver, assert_layout_tree
├── BlockLayoutTest.php     # auto-stack, margin, padding, width/height, min/max
├── FlexLayoutTest.php      # flex-direction, flex-grow/shrink, justify-content, align-items, wrap, gap, order
├── GridLayoutTest.php      # grid-template, gap, auto-fill, minmax, fr
├── PositionLayoutTest.php  # static, relative, absolute, fixed, z-index
├── ScrollLayoutTest.php    # overflow, contentHeight, scrollTop, scrollbar
├── ComboLayoutTest.php     # 嵌套组合（跨项目通用场景）
└── ── run_all.php          # 一键运行所有 Layout 测试
```

这样设计的好处：
- ✅ **按需运行**：开发 flex 时只跑 `php tests/unit/Layout/FlexLayoutTest.php`，6秒出结果
- ✅ **覆盖所有项目**：每个项目共用 Flex/Block/Grid 等基础测试，Combo 放跨项目组合场景
- ✅ **项目特定测试**：每个 app 可继承 `LayoutBase` 加自己的测试（如 `apps/bilibili/tests/`）
- ✅ **无 build 依赖**：直接构造 RenderNode 树，`php` 即可运行

## 文件职责与测试量

| 文件 | 测试内容 | 预计测试数 |
|------|---------|-----------|
| `BlockLayoutTest.php` | auto-stack, margin collapse, padding, `width:100%`, min/max, height:auto | ~12 |
| `FlexLayoutTest.php` | `flex:1` 占满剩余, flex-direction, flex-grow/shrink/basis, justify-content(6种), align-items(5种), wrap, order, gap, align-self | ~18 |
| `GridLayoutTest.php` | grid-template固定值/比例, gap, auto-fill, minmax, fr | ~8 |
| `PositionLayoutTest.php` | relative偏移, absolute定位, fixed相对视口, z-index层级, 嵌套定位 | ~8 |
| `ScrollLayoutTest.php` | overflow-y:auto, contentHeight, scrollTop偏移, scrollbar区域 | ~6 |
| `ComboLayoutTest.php` | 嵌套flex column+row, flex:1传递, fixed在flex中, scroll+flex, padding影响flex child | ~9 |
| **合计** | | **~61** |

## Phase 排期

| Phase | 文件 | 说明 |
|-------|------|------|
| **Phase 1** | `FlexLayoutTest.php` + `ComboLayoutTest.php` | 先解决 Bilibili 的 flex column/fixed 问题 |
| **Phase 2** | `BlockLayoutTest.php` + `PositionLayoutTest.php` | 基础容器定位 |
| **Phase 3** | `GridLayoutTest.php` + `ScrollLayoutTest.php` | 网格/滚动 |
| **Phase 4** | 所有 + `LayoutBase.php` 完善 | treeToText 排版、run_all 整合、持续集成 |

## 每个 Phase 的迭代循环

```
1. 写测试（加在对应 Phase 的文件中）
   → php tests/unit/Layout/FlexLayoutTest.php（看到哪些 FAIL）
2. 修改 framework/Rendering/LayoutResolver.php 修复
   → 再次运行，看到 PASS
3. 回归验证：
   → php tests/unit/LayoutEngineTest.php
   → php tests/unit/LayoutResolverTest.php
   → php tests/unit/BilibiliLayoutTest.php
4. 提交，进入下一个测试
```
整个循环 < 30 秒。

## 验证方法

最终验收标准：
1. `php tests/unit/Layout/*.php` 全部通过（精确坐标匹配 CSS 标准）
2. `php tests/unit/LayoutResolverTest.php` 全部通过（不退化）
3. `php tests/unit/BilibiliLayoutTest.php` 全部通过
4. `php tests/unit/run_all_tests.php` 全部 420+ 通过