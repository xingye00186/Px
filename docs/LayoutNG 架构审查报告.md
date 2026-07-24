# LayoutNG 架构审查报告（对照 Blink LayoutNG）

## 一、当前架构数据流

```
LayoutOrchestrator.layout(root)
├─ mainLayout(root)                          ← 每 cycle 仅 4 次（顶层节点）
│   ├─ 缓存早退（cachedFragment + 约束比较）
│   ├─ 【Phase B】递归预布局所有子节点 → $childFragments   ← 用通用约束盲目预布局
│   ├─ selectAlgorithm(display) → Block/Flex/Grid/Inline/Table
│   ├─ algo.layout(space, style, ..., childFragments)      ← 算法被动消费预布局结果
│   ├─ 【Phase C】flex/grid 检测宽度不一致 → 重布局子节点 + 重跑算法 + merge
│   └─ teardown：重建 Fragment + 写缓存
├─ oofLayout（OOF 独立通行证）
└─ postProcess（文本截断）
```

---

## 二、核心缺陷清单（严格对照 Blink）

### 🔴 缺陷 1：Phase B 全量预布局 + Phase C 补救 = 三重布局（最严重）

| | Blink LayoutNG | Px 当前 |
|---|---|---|
| 子项布局时机 | 算法**按需**调 `LayoutChild()`，用算法特定约束（flex-basis / grid track）一次成型 | 编排器 Phase B 用**通用约束**盲目预布局所有子节点 |
| 约束正确性 | 子项第一次就用对的约束 | Phase B 用错约束 → 算法重排 → Phase C 再重布局 |
| 布局次数 | 1 次 | **最多 3 次**（Phase B + 算法 + Phase C） |

**代码证据**：
- Phase B：`LayoutOrchestrator` L195-247（foreach 子节点递归 mainLayout）
- Phase C：L298-369（`MAX_RELAYOUT_ITERATIONS=3` 上限防振荡）
- FlexAlgorithm L385：`$useOrig = abs($origW - $itemW) <= 5` —— **5px 容差启发式**调和 Phase B 与 flex 计算
- FlexAlgorithm L388：`translateFragmentTree` —— Phase B 布局在错误位置，flex 再平移子树
- L344-348 注释自认："Phase C 重布局产生 auto-fill(202)，flex 产出 234…用 flex 权威宽度覆盖" —— **merge 补丁**

### 🔴 缺陷 2：ChildLayoutProvider 半迁移（架构债）

- 接口已存在（`layoutChild` 按需布局，这是 Blink 正道）
- `LayoutAlgorithm.layout()` 签名注释明写："childFragments 预计算子 fragment（**即将废弃，改用 layoutChild**）"
- **但 Block/Flex/Grid 全部仍消费 Phase B 的 childFragments，没有一个调用 layoutChild**
- 新旧两条路径并存 → 缺陷 1 的根源

### 🔴 缺陷 3：style key 归一化不一致 → Grid 布局实际损坏（实证确认）

```
getRaw($key)              → rawDeclarations[$key] ?? null     ← 无归一化
resolveCssLength/Color/Keyword → $d[$key] ?? $d[camelToKebab($key)]  ← 有双向 fallback
```
- SFC 编译的 style 数组用 **kebab**（`'grid-template-columns'`）
- GridAlgorithm L69 读 **驼峰** `getRaw('gridTemplateColumns')` → **永远 NULL**
- → `computeTracks` 返回空 → 退化成 **2 列默认**（TextHeavy 应 20 列、HoverGrid 应 10 列）
- **gap 正常**（走 typed resolver 有 fallback），但 grid-template 无 typed resolver 兜底
- **结论：TextHeavy/HoverGrid 的 grid 实际渲染是坏的（2 列），bench 无视觉断言所以没暴露**

### 🟡 缺陷 4：Inline 布局双重实现

- `BlockAlgorithm` 内部自己处理 inline 子项（L98-110 `flushInlineBuffer`、L255 `layoutInlineBuffer`、INLINE_TYPES 白名单）
- 同时存在独立的 `InlineAlgorithm`（77 行）
- 两处实现 inline 布局，职责重叠、易不一致

### 🟡 缺陷 5：算法各自为政的"二次 pass"，无统一两阶段

| 算法 | ad-hoc 二次 pass |
|---|---|
| Block | percent-height 二次 pass（L75-93 `reResolveChild`） |
| Flex/Grid | Phase C 二次 pass（编排器层） |
| Grid | auto-track 迭代 pass（`$iteration`、NeedsAnotherPass） |

每个算法用**不同方式**补救单阶段缺陷，没有统一的 measure→layout 两阶段架构。

### 🟡 缺陷 6：relative 定位内嵌于 block 堆叠

- `stackBlockChildren` L241-243 内联处理 `position:relative` 偏移
- 违反项目已确立的 **V3 原则**（memory：relative 应提取为独立后处理阶段）

### 🟡 缺陷 7：cachedFragment 双重语义污染（memory 记录）

- Px 的 `cachedFragment` 存于**子 RenderNode**，被算法原地修改 x/y/w/h
- Blink 的 `NGPhysicalBoxFragment` **不可变**，仅由父项 children 数组持有
- cachedFragment 同时承担 intrinsic 缓存 + 最终布局结果双重语义 → 缓存污染

---

## 三、GridAlgorithm=0 根因（明确回答）

**是架构问题，但分两层：**

1. **Grid_cnt=0 本身是缓存假象**：grid 容器在 warmup 布局后写入 cachedFragment；测量期 cell 只改背景色（paint-only，不 dirty 布局）→ grid 容器命中缓存早退 → 测量期 GridAlgorithm 0 次调用。**这不是 bug。**

2. **但 Grid 布局本身是坏的**（缺陷 3）：warmup 那次 GridAlgorithm 调用因 `getRaw` key 不匹配拿到 NULL grid-template → 退化成 2 列。**这是真 bug，且是架构性的**（style key 归一化不统一 + grid-template 无 typed resolver）。

---

## 四、覆盖度评估（除极不常用外）

| 算法 | 覆盖度 | 问题 |
|---|---|---|
| **Block** | 较完整（width/height/margin-collapse/percent/box-sizing） | relative 内嵌、inline 双实现 |
| **Flex** | 较完整（grow/shrink/basis/wrap/align/order/gap） | 全靠 Phase C + 启发式补丁，非两阶段 |
| **Grid** | 基础（tracks/fr/auto） | **getRaw bug 致实际损坏**；缺 grid-area/span/auto-flow/命名线 |
| **Inline** | 简单（单行+换行） | 缺 bidi、复杂 IFC、baseline 对齐 |
| **Table** | 基础 | 缺 colspan/rowspan、复杂表格布局 |
| **OOF** | 独立通行证 | 尚可 |

**常见场景（block/flex/简单 grid/定位）框架上覆盖了，但 Grid 实际损坏是硬伤，Flex/Grid 的正确性依赖补丁而非干净架构。**

---

## 五、算法互相穿插/拆台的具体路径

```
父容器 mainLayout
  ├─ Phase B：用【通用约束】预布局子项 C1（block 算法）→ C1 宽度 = auto-fill 父宽
  ├─ 父算法（flex）：按 flex 规则算出 C1 应得宽度（≠ Phase B 宽度）
  ├─ Phase C：发现 C1 宽度差 > 5px → 用【flex 确定宽度】重布局 C1（再跑一次 block）
  └─ merge：取 flex 权威的 w/h + Phase C 重布局的 children → 拼成新 Fragment
```
**三次布局 + 一次手动 merge**，且 merge 用 `PhysicalFragmentBuilder` 逐字段拼接（脆弱）。FlexAlgorithm 的 `translateFragmentTree` 还要平移 Phase B 布局好的子树坐标。**这就是"互相拆台"的完整链路。**

---

## 六、干净有序的迭代方案（对照 Blink + 现代工程原则）

按"先正确性、后架构、可分阶段验证"排序：

| 阶段 | 内容 | 类型 | 风险 |
|---|---|---|---|
| **P0** | 修 Grid getRaw bug：`getRaw` 加 `camelToKebab` fallback（与 typed resolver 对齐） | 正确性 | 低 |
| **P1** | 统一 style key 归一化：ComputedStyle 构造时归一化 key，消除 kebab/camel 二义 | 正确性 | 中 |
| **P2** | 完成 ChildLayoutProvider 迁移：算法改用 `layoutChild` 按需布局，**删除 Phase B 全量预布局** | 架构核心 | 高 |
| **P3** | 统一两阶段：measure（intrinsic）+ layout（final），消除 Phase C 补丁与 merge | 架构核心 | 高 |
| **P4** | 合并 Inline 实现：Block 的 inline 处理收归 InlineAlgorithm | 职责分离 | 中 |
| **P5** | relative 提取为独立后处理阶段（落实 V3 原则） | 职责分离 | 中 |
| **P6** | Fragment 不可变化：算法产出新 Fragment，cachedFragment 仅表 intrinsic | 对齐 Blink | 高 |

**目标终态（Blink 对齐）**：算法主导子项布局（按需 LayoutChild）+ Fragment 不可变 + 统一 measure/layout 两阶段 + 单一 inline 实现 + 各关注点独立阶段。

## 七、关键原则：几何/样式概念严格对齐 Blink，杜绝错误嫁接

> **核心原则**：布局算法的正确性建立在每个几何/样式概念的精确语义之上。`contentWidth ≠ width`、`x ≠ parentContentX ≠ bfcOffset`——这些看似相近的量在 Blink 中有严格区分，一旦混淆就会产生隐蔽的算法错误（溢出、百分比基准逃逸、坐标偏移），且极难通过视觉检查发现。

