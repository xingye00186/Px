# Px CSS 子系统重构 — 分批执行计划

> 依据：《Px_CSS对齐Blink_迭代事实文档.md》r4（范围契约）+ 评估核对（13 项论断 12 项精确、1 项重大失真）。
>
> 水位基线（较文档刷新）：css-test diff **49**（文档写 60，已被 055 滚动条批 @038acaae 超越）、40/55 通过、gates 31/32、compare_php_aot 54/55。
>
> 每批通用纪律：330 快照不破 + 55 case diff ≤ 上批水位 + gates 不降 + 引擎改动跑 compare_php_aot；台账《Px_LayoutNG_架构审计报告_对标Blink.md》续 §46 起；推测改动零收益即回退零残留。

---

## C0 — 前置修正（评估新增，C1 硬前置）

### C0.1 文档勘误批（半天）

- 修 3 处：§1.1 单测行改"63 文件，run_all 11/58（47 失败=旧命名空间僵尸）"；§1.2 `sfc-compiler L1020` → `framework/Compiler/Helpers/MiscHelper.php L130`；锚点日期 07-31 → 07-29。
- 水位数字 60 → 49 同步。
- 验收：文档与代码现状零矛盾。

### C0.2 单测僵尸甄别批（1-2 天，可拆 2-3 提交）

- 对 run_all_tests 47 个失败逐个定性：
  - A 类 = 旧命名空间（`Px\Rendering\*` → `Px\Layout\*` 等）→ 修复；
  - B 类 = 测已删除 API 且覆盖已被 css-standards 取代 → 移入 `tests/unit/_retired/`（不删，保考古）；
  - C 类 = 真回归（预期 0，若有立即修）。
- 重点保绿文档 §四复用矩阵所列 7 文件：RenderPipelineFixTest、RenderTreeManagerTest、MemoryStressTest、CssValueParserTest、CssMappingsTest、SfcCompilerPartsTest、ThemeProviderTest。
- 验收：`php tests/run_all_tests.php` 除显式 `_retired` 外全绿；矩阵 7 文件 100%。

---

## C3a — alpha 修复（P0 正确性，与 C1 并行；依赖 C0.2 的 CssValueParserTest 绿）

### C3a.1 立靶批

- 新建 case-058-rgba-alpha（rgba / #RRGGBBAA / #RGBA 三形态 + 半透明叠加布局），采浏览器真值入库，报告板 55 → 56 case。
- 验收：case 进板、E 侧按现状必然 FAIL（红靶确认）。

### C3a.2 解析批

- parseHexColor：rgba 正则补第 4 捕获组；#RRGGBBAA / #RGBA 分支（消灭 `strlen!==6` 返 0 变黑）；alpha 进 `CssColor::$argb` 高 8 位。
- CssValueParserTest 扩 alpha 矩阵（~12 断言）。
- 验收：`#FF000080` 不再返 0；单测绿；330 快照色值不回归。

### C3a.3 渲染批

- Skia 后端消费 A 通道（sk_alpha_fill_rect 已有 opacity 参数，接通道）；GDI 忽略 alpha 但保 RGB。
- 验收：case-058 色值列对齐（截图列维持跳过）；56 case 全量零回归。

---

## C1 — Cascade 统一 + UA 单源

### C1.1 CascadeResolver 纯函数批

- 新建 `framework/Css/CascadeResolver.php`：六槽位序（UA normal → author normal → inline normal → author !important → inline !important → UA !important），同槽位内 specificity + 源顺序。
- origin 枚举预留 animation 槽位常量（不实现）。
- 同批 CascadeResolverTest（~25 断言）。
- 验收：单测绿；**此批不接线**（零行为变化）。

### C1.2 UAStyles 单源批（高风险点 1，按散点拆 3 提交）

- 新建 `framework/Css/UAStyles.php` 常量数组，逐散点收编：
  1. TemplateParser L552/L889 内联标签族（b/strong/em/i/u/code/small/mark）；
  2. ComputedStyle 控件特例（bg / align-items / overflow:clip / border-spacing / caption 居中 / TABLE_DISPLAY_MAP / INLINE_TYPES 迁入）；
  3. InlineAlgorithm monospace 族字体。
- **每个散点收编后 case-046/048/050 diff 逐字节不变 + 330 快照全绿，才提交下一个**。
- 验收：`grep defaultStyles framework/Compiler` 零命中；getDefaultsArray 无元素特判残留。

### C1.3 双通道接线批

- mergeClassStylesIntoNode（MiscHelper L130）与 resolveClassStyles 改调 CascadeResolver。
- StyleResolver L79 保留 !important 元数据传入。
- 新建 Level-32-Cascade（author 覆盖 UA、!important 覆盖 inline、同权后者胜）。
- 验收：330 + 56 case 全量零变化（层叠序等价性证明）。

### C1.4 INHERITED_KEYS 补全批（逐键提交）

- textTransform → listStyleType → listStylePosition → overflowWrap 等，每键独立提交过 330 快照。
- 验收：与 Blink css_properties inherited 标志对照表入台账。

### C1.5 命名清退 + ThemeData 删除批

- StyleResolver → InlineStyleParser 更名（grep framework 零命中）。
- 删 ThemeData 族 9 文件 + App L477-480 注入段 + RTM L15 死 import；同批修 RenderPipelineFixTest L75。
- 验收：`framework/Theme/` 仅剩 ThemeProvider.php（瘦身为纯 classStyleRegistry ~30 行）；全量三层绿。

**C1 退出标准**：330/330 + 56 case diff ≤ C3a 后水位 + AOT 编译过 + compare_php_aot 分叉不增。

---

## C2 — 选择器引擎 + StyleSheetContents（解锁动态样式）

### C2.1 Tokenizer/Parser 批

- CssTokenizer + SelectorParser（复合链 AST + specificity 计算并入 AST）。
- SelectorMatcherTest 先建 tokenize/AST 断言部分。不接线。

### C2.2 SelectorChecker 批（r3 命名）

- 右向左匹配、任意级组合器、:nth-child(an+b) / :not / 属性选择器。
- SelectorMatcherTest 补匹配矩阵。不接线。

### C2.3 StyleSheetContents codegen 批

- sfc-compiler 产出 `RuleData{selectorAST, declarations, specificity, order, scopeId}` 常量数组进 gen。
- SfcCompilerPartsTest 模式加 codegen 断言。
- 验收：gen 产物含规则表但运行时尚未消费（零行为变化）；AOT 编译过（常量数组 AOT 友好性实证）。

### C2.4 运行时通道接线批

- StyleRecalcPass 传真实 precedingSiblingClasses（修 L52）。
- 运行时走 SelectorChecker + CascadeResolver 消费 StyleSheetContents。
- Level-25 迁移到新 API（迁移前后断言不变 = 等价性验收）。

### C2.5 规则 ID 级烘焙切换批（高风险点 2，需中间对照）

- 烘焙门（MiscHelper L159）改产出匹配集（元素 → 规则 ID 列表）；`:class` 节点静态部分不再丢失。
- 切换前后**每元素 ComputedStyle 逐字段等价**对照脚本先行，330 快照秒级拦截。
- 验收：56 case diff 零升；reactive-bench style_recalc 恶化 ≤20%（超限回看 StylePool key 而非回退）。

### C2.6 StylePool key 迁移批

- `spl_object_id` → ruleID 列表指纹 + 父池 key 链。
- 同批评估父 ComputedStyle 指针继承（替代 toExportArray 数组传播——顺带根治台账 §36 再构造链丢键族，可解 044(6)/046(2) 采集宽语义储备）；改动面过大则拆独立批。

### C2.7 RuleSet 倒排索引定形批

- codegen 产出"类名 → 规则 ID"索引数据形状 + selector_match_count 观测点。
- **不激活查表**（数据门控）。

### C2.8 :hover 复活批

- StyleSheetContents 供给 pseudoStyles → 生产复活。
- 新建 case-057-dynamic-class（:class 切换断几何 + hover 只断绘制类）、case-056-structural-pseudo（nth-child 条纹真值）。
- 验收：bilibili app 手测 hover 可视；56 → 58 case。

### C2.9 ThemeProvider 终删批

- classStyleRegistry → StyleSheetContents 注册 API。
- 迁移 §2.2 六测试文件（先迁移绿后删旧）；`framework/Theme/` 目录消失。

**C2 退出标准**：Level-25 迁移全绿 + case-056/057/058 进板通过 + hover 生产可视 + Theme 目录消失 + compare_php_aot 分叉不增。

---

## C3b — 值系统 + 动态样式

### C3b.1 命名色批

- CSS Color 4 全 148 色常量表 + CssValueParserTest 抽样。

### C3b.2 calc 树批

- 表达式树替换 4 模式正则（嵌套/混合单位/乘除），求值延迟 resolveInContext；单测矩阵。

### C3b.3 var() 批

- `--x` 进 ComputedStyle 自定义属性表 + 继承 + computed-value 期替换（依赖 C2.5 规则身份）。
- 新建 Level-34-Custom-Properties。

### C3b.4 v-bind() 批

- 编译成 var(--hash) + 组件状态写根 VNode 自定义属性；编译单测。
- 验收：运行时改 var 触发样式更新（reactive-bench 新场景 = 文档 §2.5 主题切换能力演示）。

---

## C4 — 增量重算（双重门控，可搁置）

### C4.0 取证批

- reactive-bench 加"单节点 class 切换"场景，读 style_pool_hit/miss + style_recalc。
- **若命中率 >90% 且 recalc <200μs → 降 P2 搁置，计划终于此**。

### C4.1（仅数据支持时）

- VNode style-dirty 位接 patchFlags；StyleRecalcPass 跳 clean 子树；失效集复用 C2.7 索引。

---

## C5 — @media（可选独立小批）

- 仅视口维度；依赖 C2.3。需求出现再排。

---

## 并行与依赖图

```text
C0.1 → C0.2 ──┬──> C3a.1 → C3a.2 → C3a.3                （alpha 并行线）
              │
              └──> C1.1 → C1.2 → C1.3 → C1.4 → C1.5
                     → C2.1 → C2.2 → C2.3 → C2.4 → C2.5
                     → C2.6 → C2.7 → C2.8 → C2.9
                     → C3b.1..4 → C4.0 (→ C4.1) → C5
```

---

## 风险与开放问题（承文档 §七 + 评估）

1. 两个最高风险点（C1.2 UA 收编、C2.5 烘焙切换）均要求"逐字节/逐字段等价"中间验证步，不可跳。
2. LayoutNG 剩余 49 diff（048 table 几何 17 / 019 CB 精度 7 / 044 采集宽 6 / 尾差）挂起不阻塞本计划；C2.6 父指针继承可能顺带消解 044/046 储备。
3. AOT 兼容：每个新类（CascadeResolver / SelectorChecker / StyleSheetContents 常量）首个接线批必须跑 AOT 编译 + compare_php_aot（54/55 基线不降）。
4. 手动 build.bat 后补写 `.build_hash`（记忆纪律，防管线假挂）。
