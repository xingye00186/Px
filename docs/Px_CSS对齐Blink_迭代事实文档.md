# Px CSS 对齐 Blink — 迭代事实文档（范围 · 边际 · 测试 · ThemeProvider 处置）

> 锚点：HEAD `ba5852a0`（2026-07-31 前后）。前置文档：《Px_LayoutNG_架构审计报告_对标Blink.md》§二十（第六次核验 ~80%）。
> 本文档性质：**迭代事实与范围契约**——每阶段做什么、明确不做什么（边际）、用哪些现有测试、补哪些新测试、退出标准是什么。所有现状论断均有代码行号证据。
> 修订 r1：融合外部评审结论——烘焙策略由"值级"升级为"规则 ID 级"（§C2.5）、alpha 修复拆为独立前置小批 C3a、反向索引提前至 C2 定形、硬伤清单补 `:class` 烘焙门双输事实（§1.3.6）。评审中的错误机制描述（hexToBgr 截断说、StylePool 命中仍遍历说、class 被消化说）已逐条证伪，不采纳。
> 修订 r2（Blink 对齐严格核查）：纠正 q 引号→LayoutTheme 错误嫁接（q 规则属 html.css UA 表，深度解析属 LayoutQuote，与 LayoutTheme 无关）；cascade 槽位补全 inline style 与动画 origin；标注 :hover 应用机制偏差（Px Paint 期叠加 vs Blink 全量 recalc）；补继承对齐条目；新增 §八术语对照表（Px 命名 ↔ Blink 对应物 ↔ 差异）防名同物异。
> 修订 r3（命名占用清退，强制）：凡 Px 术语占用 Blink 名称但语义不同的，一律改用与 Blink 语义一致的命名——规划术语"RuleSet"（规则存储）已全文更名 `StyleSheetContents`（条目 `RuleData`），`RuleSet` 名称让渡给倒排索引（与 Blink bucketing 职能一致）；现存类 `StyleResolver` 列入 C1 更名整改（C1.6）。详见 §8.1。
> 修订 r4（核查清单复核补漏）：var() 替换期由 used-value 更正为 **computed-value 期**（CSS Variables L1 §3，C3b.3）；UA 散点计数"5+ 处"更正为 **4 处实体散点**（BlockAlgorithm L16 仅为 INLINE_TYPES 单源引用点，非散点，§1.3.1）。

---

## 〇、结论速览

| 问题 | 结论 |
|---|---|
| CSS 子系统当前定性 | "编译期把级联算死 + 运行时正则兜底"双通道，运行时通道在**生产环境是死路**（注册表恒空，证据见 §2.1） |
| 迭代分几步 | C1 Cascade统一+UA单源（**C3a alpha 修复可并行前置**）→ C2 选择器引擎+StyleSheetContents/RuleSet+规则ID级烘焙 → C3b var()/v-bind 动态样式 → C4 增量重算（数据先行，P3）→ C5 @media（可选） |
| ThemeProvider 能否彻底去掉 | **能，但分两步**：ThemeData 主题族 9 文件生产零消费者，C1 期间即可直接删除；`classStyleRegistry` 注册表是 C2 重构的被测物与回归网，随 StyleSheetContents 机制落地时替换删除，同批迁移 6 个测试文件（详见 §二） |
| 测试策略 | 三层现有资产（330 快照 / 55 case 真值对照 / 27 个单测）全复用为回归网；新增 3 个 css-standards Level、2-3 个 css-test case、4 个单测文件（详见 §四/§五） |

---

## 一、事实基线（HEAD ba5852a0）

### 1.1 测试资产现状（三层）

| 层 | 位置 | 规模 | 当前水位 | 性质 |
|---|---|---|---|---|
| css-standards 快照 | `tests/css-standards/` | 31 个 Level + template-tests | **330/330**，gates 31/32（template-tests 既有失败） | 引擎自断言（Blink 真值护栏过的快照），秒级，主回归网 |
| css-test 真值对照 | `apps/css-test/test_case/` | 55 case | **40/55 通过**，全量 diff 60，PHP-RT ≡ AOT（54/55 点等） | 浏览器三方对照（E vs B），case 驱动主战场 |
| 单元测试 | `tests/unit/` | 27 文件 | 常绿 | CSS 直接相关：CssMappingsTest、CssMappingsBorderTest、CssValueParserTest、RenderingPipelineTest、RenderTreeManagerTest、RenderPipelineFixTest、MemoryStressTest、PxTest/ThemeProviderTest |
| 性能基准 | `apps/reactive-bench/` | — | style_recalc ~175μs/帧地板 | PerfCounter 计数（style_pool_hit/miss 已埋点） |

### 1.2 双通道消费矩阵（谁在喂、谁在读）

| 通道 | 写入方 | 读取方 | 生产状态 |
|---|---|---|---|
| 编译期烘焙 | `mergeClassStylesIntoNode`（sfc-compiler L1020） | VNode 内联样式 → 全布局管线 | **生产唯一活通道** |
| 运行时注册表 | `ThemeProvider::registerClassStyles` | `StyleResolver::resolveClassStyles` / `extractPseudoStyles` | **生产恒空**：gen 文件零调用（grep `apps/**/gen` 0 命中）；Application L482-483 注释自证"编译时 class→style 合并已完成，无需运行时注册" |

### 1.3 关键既成事实（迭代动机）

1. **UA 散点税**：近期 4 批提交（`2ab4faaa` form-control、`c393bf9a` q 引号、`22a21802` text-emphasis、case-048 border-spacing）各自寻找注入点，UA 语义实体散点 **4 处**（ComputedStyle 默认值 / TemplateParser L886 / InlineAlgorithm / LayoutOrchestrator）；r4 勘误：BlockAlgorithm L16 仅为 INLINE_TYPES 单源的引用点（消费者），不计入散点。
2. **specificity 算了没用**：`CssMappings::calculateSpecificity`（L1420）实现完整，但运行时 `resolveClassStyles` 靠遍历顺序覆盖，不排序。
3. **运行时兄弟组合器失效**：StyleRecalcPass L52 恒传空 `precedingSiblingClasses`。
4. **rgba alpha 被丢弃**：三处断点——`parseHexColor` L101 的 `rgba()` 正则只捕获 RGB 三组（主断点）；8 位/4 位 hex（`#RRGGBBAA`/`#RGBA`）经 `hexToBgr` 的 `strlen !== 6` 检查**直接返回 0（纯黑）**，比"变不透明"更糟；`CssColor` 已有 `argb` 字段与 `alpha()` 方法但闲置。注意：不存在"截断为 RRGGBB"路径（hexToBgr 的 `substr($hex,2)` 是 0x 前缀剥离分支，勿误读）。
5. **`:hover` 生产死通道**（本次新发现，见 §2.3）。
6. **`class` + `:class` 并存双输**：烘焙门条件 `isset(class) && !isset(:class)`（MiscHelper L159）——带 `:class` 的节点**整体跳过烘焙**，其静态 class 部分也一并丢失；又因运行时注册表恒空，两条通道全断。这是当前动态样式只能靠 `:style` 内联绑定的根因（注意：class 属性本身**未被消化**，仍存活于 props，RenderTreeManager L896 依赖它提取伪类——失效原因是规则表死，不是选择器上下文丢失）。

---

## 二、ThemeProvider 处置分析

### 2.1 取证结论：生产僵尸

`framework/Theme/` 共 10 文件（ThemeProvider 102 行 + ThemeData/ColorScheme/TextTheme/ComponentTheme/PlatformAdapter/PlatformStyling/Win32Styling/MacOSStyling/LinuxStyling）。逐条证据：

| # | 事实 | 证据 |
|---|---|---|
| 1 | 主题注入后**无人读取** | `Application::mount` L477-480 构造 ThemeData::light() + 平台样式并 `inject()`；但 `ThemeProvider::of()` / `forSubtree()` / `getClassStyles()` 在 framework 内**零消费者**（grep 唯一命中是 ThemeProvider 自身） |
| 2 | 注册表生产**零写入** | `registerClassStyles` 生产路径零调用：gen 文件 0 命中；Application L482-483 注释自证 getClassStyles() 已从 gen 移除 |
| 3 | 注册表唯一读者读到的**恒为空** | `StyleResolver::resolveClassStyles` L222 / `extractPseudoStyles` L397 读 `getAllClassStyles()`；MiscHelper L102-103 注释自证："烘焙模式无运行时 class 注册（extractPseudoStyles 永空）" |
| 4 | RenderTreeManager 的 import 是**死 import** | L15 `use Px\Theme\ThemeProvider;` 文件体内零使用 |
| 5 | Flutter 主题计划是**半成品弃案** | `docs/style-system-refactor.md` 的 ColorScheme/TextTheme/forSubtree 子树覆盖栈全部落地但从未接线（无任何组件调 forSubtree） |

### 2.2 唯一残余价值 = 测试载体

运行时注册表的活跃用户全部在测试侧（6 个文件）：

| 文件 | 用法 |
|---|---|
| `tests/css-standards/Level-25-Selectors-Pseudos/test_selectors_pseudos.php` | L30/L45/L60 注册选择器规则，**是运行时选择器通道的现成被测物** |
| `tests/unit/RenderTreeManagerTest.php` | L309/L313/L365 三处注册 |
| `tests/unit/RenderingPipelineTest.php` | L186-188 反射重置注册表 |
| `tests/unit/RenderPipelineFixTest.php` | L75-76 inject + 注册 |
| `tests/unit/MemoryStressTest.php` | §7 注册表增长压测 + L581 反射清理 |
| `tests/unit/Layout/Grid*Diag.php`（3 个） | 诊断脚本注册 class 样式 |

### 2.3 `:hover` 的尴尬事实（新发现，建议入待解决清单）

消费端三层齐备：PaintPipeline L191-199/L1294-1318 有 hover/focus/active 完整应用路径；RenderTreeManager L896 调 `extractPseudoStyles` 填 `RenderNode::$pseudoStyles`。但供给端双断：

- 烘焙通道：`mergeClassStylesIntoNode` **不处理伪类**（编译器全目录 grep "hover" 0 命中）；
- 运行时通道：注册表生产恒空 → extractPseudoStyles 永空。

即：**生产应用中 `<style>` 里的 `.btn:hover{}` 当前完全不生效**，只有测试直接构造 `__hoverStyle` raw key（RenderPipelineFixTest L198-199 路径 B）能触达。此事实使"删注册表"的风险为零（没有生产功能依赖它），同时使 C2 的验收标准明确：**C2 规则机制落地时 `:hover` 必须首次在生产复活**。

### 2.4 处置方案：两步走

**第一步（C1 期间，随手做）——删 ThemeData 主题族 9 文件**：
- 删除：ThemeData/ColorScheme/TextTheme/ComponentTheme/PlatformAdapter/PlatformStyling/Win32Styling/MacOSStyling/LinuxStyling + Application L476-480 注入段 + RenderTreeManager L15 死 import。
- ThemeProvider.php 本体**暂留**，瘦身为纯 classStyleRegistry（删 rootTheme/themeStack/of/forSubtree/inject/restore，约剩 30 行）。
- 同批修：RenderPipelineFixTest L75（删 inject 行）、MemoryStressTest/RenderingPipelineTest 的反射清理不受影响。
- 理由：零生产消费者、零测试断言其主题行为（ThemeProviderTest 测的是注册表不是主题）。

**第二步（C2 落地时）——注册表被 StyleSheetContents 机制替换，ThemeProvider 文件删除**：
- 新选择器引擎的规则容器（编译期产出的 StyleSheetContents PHP 数组 + 运行时注册 API）取代 classStyleRegistry；
- 迁移 §2.2 的 6 个测试文件到新 API（Level-25 是迁移的主验收：迁移前后断言结果不变）；
- 此时 `framework/Theme/` 目录整体消失。

**不建议现在一步删光的理由**：Level-25 + 4 个单测正把运行时通道当被测物，它们是 C2 重构唯一的行为基线；先删 = 自毁回归网。

### 2.5 未来要不要"主题系统"？

运行时主题切换（style-system-refactor.md 的原始动机）在新架构下的正确形态是 **C3b 的 var() 运行时化**（`:root { --primary: … }` + 运行时改 CSS 变量 = Vue/Web 标准做法），而不是 Flutter 式 ThemeData 对象树。删除 Theme 族不损失任何目标能力。

---

## 三、迭代范围与边际（C1-C5）

> 通用纪律（承袭 css-test 工作流）：每批**零回归**（330/330 不破、55 case diff 总数不升、gates 不降）；引擎改动跑 `compare_php_aot` 确认 CLI≡AOT；每批提交信息记录 case 水位变化。

### C1 — Cascade 统一 + UA 单源（地基）

**目标**：一个 `CascadeResolver` 吃掉所有"声明合并"决策；一份 UA 规则表吃掉 4 处硬编码散点。

**In-scope**：
1. `CascadeResolver`：输入 = 带元数据（origin / specificity / 源顺序 / important）的声明集，输出 = 合并后声明数组。**完整槽位序（对齐 CSS Cascade 4 §6.1，r2 补全）**，低→高：
   `UA normal → author normal（规则）→ inline normal（style 属性，视为 author origin 但胜过任何选择器）→ author !important（规则）→ inline !important → UA !important`；
   specificity 与源顺序只在**同槽位内部**比较（`compareSpecificity` 已有，接上）。对应 Blink StyleCascade 的 CascadePriority(origin, importance, position) 语义的声明集简化形（Blink 是 per-declaration 优先级映射，Px 用声明集排序合并，语义等价）。
2. UA stylesheet 单源化：新建 `framework/Css/UAStyles.php`（PHP 常量数组，AOT 友好，对应 Blink html.css/DefaultStyleSheets），收编——TemplateParser L886-903（b/strong/em/i/u/code/small/mark）、ComputedStyle 控件特例（bg/align-items/overflow:clip/border-spacing/caption 居中）、InlineAlgorithm monospace 族字体（Blink html.css `pre,code,kbd,samp{font-family:monospace}` 同源）。收编后原散点删除。
3. 编译期 `mergeClassStylesIntoNode` 与运行时 `resolveClassStyles` 都改调 CascadeResolver（消灭两套注释声明的层叠序）；
4. 运行时通道 !important 不再剥掉忽略（StyleResolver L79 正则现状）；
5. **INHERITED_KEYS 对照补全**（r2 补，对齐 Blink css_properties 的 inherited 标志）：现白名单 ~22 键缺真继承属性 textTransform/listStyleType/listStylePosition/overflowWrap 等，逐个补齐并过 330 快照验证（每补一个都可能改变现有用例继承链，逐个提交）；
6. **`StyleResolver` 更名整改**（r3，命名占用清退 §8.1）：声明合并职责移交 CascadeResolver 后，剩余职责（parseInlineStyle + 池入口）更名为 `InlineStyleParser`（或并入 StyleRecalcPass）；`StyleResolver` 名称冻结不再使用——Blink 中该名属元素→ComputedStyle 全流程编排者，Px 不设同名异职类；
7. §2.4 第一步：删 ThemeData 族。

**Out-of-scope（边际）**：
- `@layer`（CSS Cascade L5）——无用户需求，规则量小，不做；
- origin `user` 层——桌面封闭应用无用户样式表，永久不做；
- **动画/过渡不进 cascade**（r2 显式化的有意偏差）：Blink 中 animation 声明是独立 cascade origin（高于全部 normal、低于 important，transition 最高）；Px 现状在 `RenderNode::$animatedStyle` 渲染树层叠加，不参与级联。维持偏差（动画与 !important 交互的边缘语义放弃），但在 CascadeResolver 的 origin 枚举中**预留 animation 槽位常量**不实现，防未来接入时重排序；
- UA 中属于**内容生成/盒生成/控件度量**的部分维持现状分层，不强行收编进 UAStyles（r2 拆开表述，纠正原"Blink 在 LayoutTheme 做"的笼统嫁接）：
  - **q 引号**：Blink 中规则本体在 html.css（`q::before{content:open-quote}`）= UA 样式表职责，引号**深度解析与文本确定**在 LayoutQuote（布局树 attach 期），与 LayoutTheme 无关。Px 无 generated-content 盒机制，InlineAlgorithm 内联实现是**有意偏差**（深度/字形放置部分与 LayoutQuote 同层，规则部分未对齐）；待 C2 伪元素通道成熟后评估 content:open-quote 规则回迁 UAStyles；
  - **`<option>` 零盒化**：Blink 在布局树构建期抑制盒生成（select 为控件盒），Px 在 LayoutOrchestrator 同位处理 ✓ 正确分层；
  - **控件内在尺寸**：Blink 属 LayoutTheme + html.css 尺寸声明双源，Px 在 LayoutOrchestrator ✓ 尺寸部分正确分层（其中属样式声明的 bg/overflow 已在本阶段收编范围）；
- CSSOM API——不做。

**现有测试利用**：
- case-046（appearance/控件 UA）、case-048（table UA spacing）、case-050（q 引号）= UA 收编的**直接回归网**，收编前后 diff 必须逐字节不变；
- Level-21-Html-Migration（31 gate 之一）覆盖 b/strong/em 等 UA 默认样式；
- 330 快照全量 = 层叠序等价性总闸。

**新增测试**：
- `tests/unit/CascadeResolverTest.php`：完整六槽位序矩阵（含 inline normal 胜 author 规则、author !important 胜 inline normal、inline !important 胜 author !important、UA !important 最高）/ 同槽位 specificity 序 / 源顺序 tie-break / 独立 longhand 不被简写覆盖，纯函数单测 ~25 断言；
- `tests/css-standards/Level-32-Cascade/`：author 覆盖 UA（用 C1 收编范围内且 Px 已支持的属性：如 `input{background:#eee}` 覆盖 UA 白底、author 覆盖 caption 的 UA text-align:center）、!important 覆盖 inline、同 specificity 后者胜。（r2 勘误：原例 `q{quotes:none}` 不可用——quotes 属性 Px 未实现且引号在布局层硬编码，C1 时点必挂）。

**退出标准**：330/330 + 40/55 不回退；`grep -r "defaultStyles" framework/Compiler` 0 命中；ComputedStyle::getDefaultsArray 中除纯默认值外无元素特判（TABLE_DISPLAY_MAP/INLINE_TYPES 保留，它们就是 UA 表的一部分，迁入 UAStyles）；`StyleResolver` 类名清退（grep framework 零命中，C1.6）。

### C2 — 选择器引擎 + 规则集机制（StyleSheetContents/RuleSet，解锁动态样式）

**目标**：正则三遍扫 → 最小 tokenizer + 复合选择器链 + 右向左匹配；编译期产出规则存储常量。**命名强制（r3，§8.1）**：规则存储+预算元数据命名 `StyleSheetContents`（条目 `RuleData`，与 Blink 同义）；`RuleSet` 名称专指本阶段第 7 条的编译期倒排索引（承接 Blink RuleSet bucketing 职能）；严禁用 RuleSet 指代规则存储。烘焙升级为规则 ID 级。

**In-scope**：
1. `CssTokenizer` + `SelectorParser`（复合选择器链 AST）+ `SelectorMatcher`（右向左，任意级组合器）；
2. 补齐：`:nth-child(an+b)` / `:not(简单选择器)` / 属性选择器 `[attr]`/`[attr=v]` / 三级以上组合链；
3. 修复运行时兄弟组合器（StyleRecalcPass 传真实 preceding siblings）；
4. 编译期产出 `StyleSheetContents`：每组件 `<style>` → `RuleData{selector AST, declarations, specificity, order, scopeId}` 数组写入 gen 文件（**编译期最大化**：运行时零解析）；scope 用组件 id 标记（对标 Vue `[data-v-hash]` 语义）；
5. 烘焙通道升级为**规则 ID 级烘焙**（r1 修订，替换原"值级烘焙保留"方案）：编译期对静态可判定元素（无 `:class`/`:style` 动态绑定）只固化**匹配集**（元素 → 命中规则 ID 列表），级联合并推迟到运行时统一 CascadeResolver。收益：所有元素走同一级联语义（"双通道一致性"由构造保证而非测试压出）、规则身份保留（var()/主题/!important 天然就绪）、匹配仍是编译期 O(1)；运行时多出的 ID→声明合并成本由 StylePool 吸收。动态元素走运行时 SelectorMatcher 求匹配集后进同一 CascadeResolver；
6. StylePool key 同批迁移：`spl_object_id` → **ruleID 列表指纹 + 父池 key 链**（顺带关闭审计 5.5 遗留项）。r2 注记：这才是 Blink MatchedPropertiesCache 的真实 key 语义——MPC 以**匹配声明集**为 key（命中还需父样式继承数据等价校验），现状的 `className|type|inlineFp|parentObjId` 只是输入路径近似；同批评估**父 ComputedStyle 指针继承**（替代 toExportArray 声明数组传播，对齐 Blink inherited 数据组共享；若改动面过大则降级为独立批后置，记开放问题）；
7. **RuleSet（倒排索引）定形**（r1 修订自 C4 提前；r3 正式承接 RuleSet 命名，与 Blink bucketing 职能对齐）：StyleSheetContents codegen 同批产出"类名 → 候选规则 ID"倒排索引的数据形状；**激活数据门控**——先线性扫 + `selector_match_count` 观测，数据证明需要才启用查表路径；同一索引 C4 复用作失效集。不做 bitmask 快速路（动态 class 字符串→位图本身仍需字符串处理，isset 哈希已 O(1)，且命中候选后仍需匹配器验证组合器/伪类，位图无法替代）；
8. `:hover`/`:focus`/`:active` 经 StyleSheetContents 供给 `RenderNode::$pseudoStyles` → **生产复活**。r2 偏差标注：Blink 中状态伪类变化触发**全量样式重算**（经 InvalidationSets），hover 样式可改变布局；Px 机制是 Paint 期 pseudoStyles 叠加（PaintPipeline L1294-1318），**仅绘制类属性生效，布局类 hover 属性（尺寸/边距）不触发 relayout**——维持为有意偏差（桌面应用 hover 改布局是反模式，且免 relayout 是性能优势），布局类 hover 属性列入 Out-of-scope；
9. §2.4 第二步：classStyleRegistry → StyleSheetContents 注册 API，删 ThemeProvider，迁移 6 个测试文件。

**Out-of-scope（边际）**：
- `:has()`、container queries——Blink 自己都是近年才落地，不做；
- `:is()/:where()`——可延后到需求出现；
- Blink 式运行时 bucketing 构建——Px 的 RuleSet（倒排索引）为编译期生成（C2.7），不做运行时构建；
- **C1 期间提前停烘焙**——被否决的评审建议：运行时通道在 C2 matcher+scope 就位前无 scope 隔离（StyleResolver L239-242 自证复合选择器 type-subject 会跨组件互污染），提前拨开关 = 破裂中间态。烘焙开关只在本阶段规则 ID 级方案就绪后切换；
- Shadow DOM / `::part`——无宿主概念，永久不做。

**现有测试利用**：
- **Level-25-Selectors-Pseudos 是现成被测物**：迁移到新 API 后扩容，迁移前后断言不变 = 重构等价性证明；
- SfcCompilerPartsTest / SfcCompilerVIfTest 模式复用于 StyleSheetContents/RuleSet codegen 断言；
- css-test 55 case 全量 = 规则 ID 级烘焙切换不回归的总闸（**特别关注**：切换前后每元素最终 ComputedStyle 必须逐字段等价——值级烘焙结果 vs ID 级+运行时级联结果的中间对照脚本，330 快照秒级先行拦截）。

**新增测试**：
- `tests/unit/SelectorMatcherTest.php`：tokenize 边界（转义/引号/括号嵌套）、nth-child 公式矩阵（odd/even/an+b/负 a）、右向左匹配正误例、specificity 与 AST 一致性；
- Level-25 扩容（或新 Level-33-Selectors-Full）：nth-child 布局效果、三级组合链、属性选择器、`+`/`~` 运行时（修复项的回归钉）；
- css-test 新 case：`case-056-structural-pseudo`（nth-child 条纹表格，浏览器真值）、`case-057-dynamic-class`（含 `:class` 动态绑定 + hover 的组件，多帧管道验证：`:class` 切换断几何（走完整重算），hover **只断绘制类属性**（Px Paint 叠加机制边际，见 In-scope 第 8 条）——55 case 首个动态样式 case）；
- **双通道一致性断言**（新单测）：同一规则集，烘焙路径与运行时路径产出的 ComputedStyle 逐字段相等。

**退出标准**：Level-25 迁移后全绿；case-056/057 进 55 case 板并通过；`:hover` 在一个生产 app（bilibili）可视验证；ThemeProvider 文件删除、`framework/Theme/` 目录消失。

### C3a — alpha 修复（P0 正确性，独立前置小批，可与 C1 并行）

> r1 修订：自原 C3 拆出。不依赖 C1/C2 的任何数据结构，是纯值管道修复；但**不是"一行代码"**——涉及解析、类型、双后端、真值对照四层，必须按小批纪律走。

**In-scope**（顺序即实施序，**测试先行**）：
1. 先补 `case-058-rgba-alpha` 浏览器真值（半透明叠加布局 + 导出色值）——当前 color 100% 通过率恰因无 alpha 用例，先立靶再改引擎；
2. parseHexColor：`rgba()` 正则补第 4 捕获组；`#RRGGBBAA`/`#RGBA` 路径（消灭"返回 0 变黑"）；alpha 进 `CssColor::$argb` 高 8 位（字段已在）；
3. Skia 后端消费 A 通道；GDI 后端策略：忽略 alpha 但保 RGB 正确（不再变黑），完整混合留待 AlphaBlend 需求出现；
4. css-test 导出/归一化层：色值对照兼容浏览器 `rgba()` 输出格式。

**Out-of-scope**：GDI AlphaBlend 完整混合、渐变多色标 alpha。

**测试**：CssValueParserTest 扩（alpha 保真/#RRGGBBAA/#RGBA 矩阵）+ case-058 + 330 快照（色值不回归）。

**退出标准**：alpha 端到端（.vue rgba → Skia 渲染带 A 通道）；#RRGGBBAA 不再渲染为黑色。

### C3b — 值系统 + 动态样式（Vue 3 对齐）

**In-scope**：
1. 命名色全表（CSS Color 4 §6.1，148 色，常量数组）；
2. calc 表达式树：替换 4 模式正则，支持嵌套/混合单位/乘除，求值延迟到 `resolveInContext`；
3. var() 运行时化：`--x` 进 ComputedStyle 自定义属性表 + 参与继承（INHERITED 语义）+ **computed-value 期替换**（CSS Variables L1 §3，r4 更正：原误写 used-value 期——var() 替换发生在 computed-value 时间；替换后的值再走常规流程，%/em 等相对单位的 used-value 解析仍属 CssLength::resolveInContext 职责，两者不得混同），替代现编译期字符串替换——依赖 C2 规则 ID 级烘焙（规则身份保留是前提）；
4. `v-bind()` in CSS：编译成 `var(--<hash>-expr)`，组件状态变更写入根 VNode 自定义属性（对标 Vue useCssVars）。

**Out-of-scope（边际）**：lab/lch/oklch 颜色空间、`@property` 类型化注册、三角/指数函数、`color-mix()`——均不做。

**现有测试利用**：CssValueParserTest 直接扩展（命名色/calc 树）；Level-27-Box-Sizing-Units（单位回归）；reactive-bench（var() 运行时化的开销水位）。

**新增测试**：
- CssValueParserTest 扩：命名色抽样、calc 嵌套求值矩阵；
- `Level-34-Custom-Properties`：var() 继承链（父定义子消费）、fallback、覆盖；
- SfcCompiler 单测：v-bind() in CSS 编译产物断言。

**退出标准**：var() 运行时改值触发样式更新（reactive-bench 新场景）；运行时主题切换用例（§2.5 承诺的能力）可演示。

### C4 — 增量重算（数据先行，可降级）

**前置取证（必须先做）**：reactive-bench 加场景"单节点 class 切换"，读 style_pool_hit/miss 与 style_recalc 总耗时。**若 StylePool 命中率 > 90% 且 style_recalc < 200μs，本阶段降为 P2 搁置**——175μs 地板本来不高，避免重蹈"unkeyed 位置匹配实验 +17~48% 回归"的过度优化教训。

**In-scope（若数据支持）**：VNode style-dirty 位（区别于 layoutDirty 三级脏位体系，接入 patchVNodeTree 的 patchFlags）；StyleRecalcPass 跳过 clean 子树；失效集直接复用 C2 已定形的 RuleSet（倒排索引，r1 修订：不在本阶段新建）。

**时序硬约束（r1 修订）**：本阶段**必须后置于 C1/C2 完成**——二者会重构声明数据结构与匹配流程，先写 style-dirty 必然重写。叠加 reactive-bench 数据门槛后为双重门控。

**Out-of-scope**：Blink 通用失效机制（descendant/sibling InvalidationSets 的运行时构建与 kSubtreeStyleChange 全子树回退）——规则集编译期已知，用倒排索引精确解，不需要运行时构建。

**测试**：reactive-bench 前后对照 + 330/55 零几何回归 + MemoryStressTest（索引表增长）。

### C5 — @media（可选，独立小批）

仅 `width/height/min-/max-` 视口维度（桌面窗口 resize 响应式）。Out：print/prefers-*/resolution。前置依赖 C2 StyleSheetContents（@media 是规则分组，正则通道无法表达）。测试：multi-scroll 或新 app 手测 + Level 快照（固定视口下等价性）。

---

## 四、测试资产复用矩阵

| 现有资产 | C1 | C2 | C3 | C4 |
|---|---|---|---|---|
| css-standards 330 快照 | 层叠序等价总闸 | 规则ID级烘焙切换总闸 | 单位/颜色回归 | 零几何回归闸 |
| Level-21-Html-Migration | UA 收编回归 | — | — | — |
| Level-25-Selectors-Pseudos | — | **被测物+迁移验收** | — | — |
| css-test case-046/048/050 | **UA 单源直接回归网** | — | — | — |
| css-test 55 case 全量+diff 水位(60) | 每批 | 每批 | 每批 | 每批 |
| compare_php_aot（54/55 点等） | 引擎改动必跑 | 必跑 | 必跑 | 必跑 |
| CssValueParserTest / CssMappingsTest | specificity 接线 | tokenizer 迁移 | **直接扩展** | — |
| SfcCompilerPartsTest 模式 | — | StyleSheetContents + RuleSet codegen | v-bind 编译 | —（索引已在 C2 定形） |
| reactive-bench + PerfCounter | — | selector_match 观测点 | var() 开销水位 | **前置取证 + 验收** |
| MemoryStressTest | 注册表段随 C2 迁移 | 迁移 | — | 索引增长压测 |
| RenderPipelineFixTest（hover 路径 B） | — | hover 供给端复活后重写 | — | — |

## 五、新增测试用例总清单

| 名称 | 层 | 断言点 | 阶段 |
|---|---|---|---|
| CascadeResolverTest.php | unit | origin/important/specificity/源顺序 4 维排序矩阵 | C1 |
| Level-32-Cascade | css-standards | UA<author 覆盖、!important、同权后者胜 | C1 |
| SelectorMatcherTest.php | unit | tokenize 边界、nth 公式矩阵、右向左匹配、AST-specificity 一致 | C2 |
| Level-25 扩容（或 Level-33） | css-standards | nth-child/属性选择器/三级链/兄弟组合器运行时 | C2 |
| case-056-structural-pseudo | css-test | nth-child 条纹布局浏览器真值 | C2 |
| case-057-dynamic-class | css-test | 动态 :class 切换多帧几何 + hover（首个动态样式 case） | C2 |
| 双通道一致性断言 | unit | 值级烘焙 vs 规则ID级+运行时级联 ComputedStyle 逐字段相等（切换中间对照） | C2 |
| CssValueParserTest 扩（alpha） | unit | rgba alpha 保真/#RRGGBBAA/#RGBA 不再返 0 | C3a |
| case-058-rgba-alpha | css-test | 半透明叠加真值（布局+导出色值），**先于引擎改动立靶** | C3a |
| CssValueParserTest 扩（值系统） | unit | 命名色抽样/calc 嵌套求值矩阵 | C3b |
| Level-34-Custom-Properties | css-standards | var() 继承/fallback/覆盖 | C3b |
| v-bind() 编译单测 | unit | 编译产物 custom property 注入 | C3b |
| reactive-bench 场景扩 | bench | 单节点 class 切换 style_recalc 计数/耗时 | C4 前置 |

## 六、回归护栏与纪律

1. 每批必跑：css-standards 全量（330 快照 + 32 gates）→ css-test 55 case 全量（diff 总数 ≤ 上批水位）→ 引擎改动加跑 compare_php_aot + AOT 编译；
2. EOL 陷阱（第六次核验教训）：快照 diff expected/actual 肉眼相同 → 查 core.autocrlf，`.gitattributes *.snap eol=lf` 已双保险，旧 checkout 需 renormalize；
3. UA 收编（C1.2）与规则 ID 级烘焙切换（C2.5）是两个最高风险点，各自要求收编/切换前后 **每元素最终 ComputedStyle 逐字段等价** 的中间验证步；
4. C3a 纪律：先补 case-058 真值立靶，后改引擎（color 当前 100% 通过率是无 alpha 用例的假象，顺序不可反）；
5. 测试文件迁移（§2.4）一律"先迁移绿、后删旧 API"。

## 七、开放问题

1. `:hover` 生产复活的可视验收选哪个 app（bilibili hover 卡片是现成场景）；
2. ~~StylePool key 用 `spl_object_id`（审计 5.5）~~ → 已纳入 C2.6（ruleID 列表指纹方案），不再是开放问题；
3. C4 是否执行取决于 reactive-bench 前置取证，不预先承诺；
4. css-test 截图列当前全 ⏭️ 跳过——alpha/渐变类视觉属性的像素级验证待截图管线恢复后补（C3a 退出标准暂以导出色值为准）；
5. 规则 ID 级烘焙的运行时增量成本需实测：C2 切换批附带 reactive-bench 前后对照（style_recalc/style_pool_hit 水位），若 style_recalc 恶化 >20% 需回看 StylePool key 设计而非回退值级烘焙；
6. 父 ComputedStyle 指针继承（替代 toExportArray 声明数组传播，C2.6 同批评估）：若改动面过大则降级为独立后置批，归属待定。

---

## 八、术语对照表（Px ↔ Blink，防名同物异 / 错误嫁接）

> r2 新增。本文档及后续提交信息引用 Blink 概念时以此表为准；标 ⚠️ 的是已知语义差异，不得在文档中宣称"与 Blink 同款"。

| Px 术语/模块 | Blink 对应物 | 差异注记 |
|---|---|---|
| CssTokenizer + SelectorParser（C2 计划） | CSSTokenizer + CSSParserImpl | Px 为最小子集：无 at-规则全语法、无 CSSOM 回写 |
| 规则存储编译期常量（gen 产物，C2.4） | **StyleSheetContents + RuleData** | ✅ r3 已改用 Blink 同义命名（原规划名"RuleSet"占用 Blink 名称但职责不同，已清退） |
| RuleSet（编译期倒排索引，类名→规则ID，C2.7） | RuleSet bucketing（id/class/tag 分桶）+ InvalidationSets（C4 复用时） | ✅ r3 正式承接 RuleSet 命名（bucketing 职能一致）；Blink 运行时构建，Px 编译期生成、激活数据门控；一个结构兼两职 |
| SelectorMatcher（C2） | SelectorChecker | 均右向左；Px 无 :has/:is/:where/Shadow 相关分支 |
| （C2 后的匹配集收集步） | ElementRuleCollector + MatchRequest | Px 无独立类，职责内联在解析入口 |
| CascadeResolver（C1） | StyleCascade + CascadePriority | ⚠️ Blink 是 per-declaration 优先级映射；Px 用声明集排序合并（语义等价）；无 @layer/user origin；动画不进 cascade（预留槽位不实现） |
| UAStyles.php（C1） | html.css / DefaultStyleSheets | 控件度量/盒生成不入表（对应物是 LayoutTheme/布局树构建，分属 LayoutOrchestrator） |
| StylePool | MatchedPropertiesCache | ⚠️ 现 key 为输入路径近似（className\|type\|inlineFp\|parentObjId）；C2.6 迁 ruleID 指纹后才是 MPC 真实 key 语义（匹配声明集 + 父继承数据校验） |
| ComputedStyle（扁平 ~120 readonly 字段） | ComputedStyle（分组存储 + COW + inherited 组指针共享） | ⚠️ Px 无分组/共享；继承走父声明数组（INHERITED_KEYS 白名单 ≈ css_properties inherited 标志的手维子集，C1.5 补齐） |
| StyleRecalcPass | Document style recalc 阶段（RecalcStyle） | ⚠️ Px 全树递归无脏位；Blink 脏位（NeedsStyleRecalc/ChildNeeds…）驱动；C4 补差距 |
| Px `StyleResolver` | Blink `StyleResolver` | ⚠️ **名同物异 → 强制整改（C1.6）**：Px 的只做声明合并+池入口；Blink 的是元素→ComputedStyle 全流程编排者（调度 ElementRuleCollector/MPC/继承）。C1 内更名 `InlineStyleParser`（或并入 StyleRecalcPass），`StyleResolver` 名称冻结 |
| RenderNode::$pseudoStyles + Paint 叠加 | 状态伪类全量 recalc + 伪元素独立 ComputedStyle | ⚠️ 有意偏差：Px 仅绘制类属性生效，不触发 relayout（C2.8） |
| InlineAlgorithm q 引号内联 | html.css 规则 + LayoutQuote 深度解析 | ⚠️ 规则部分未对齐 UA 表（C1 Out-of-scope 条目，C2 后评估回迁）；与 LayoutTheme 无关 |
| 动画 AnimationManager + RenderNode::$animatedStyle | CSS Animations 作为独立 cascade origin + 插值在样式层 | ⚠️ 有意偏差：不进 cascade，渲染树层叠加 |

### 8.1 命名整改要求（强制，r3）

**规则**：凡 Px 术语占用 Blink 名称但语义不同，一律替换为与 Blink 语义一致的命名（或让出该名称）；新建类在语义一致时优先直接采用 Blink 同名。

| # | 占用项 | 整改 | 时点 | 状态 |
|---|---|---|---|---|
| 1 | 规划术语"RuleSet"（指规则存储） | 更名 `StyleSheetContents`（条目 `RuleData`）；`RuleSet` 让渡给倒排索引（bucketing 职能与 Blink 一致） | 本文档 r3 | ✅ 已全文替换（未落地代码，零成本） |
| 2 | 现存类 `Px\Css\StyleResolver` | 声明合并移交 CascadeResolver 后，剩余职责更名 `InlineStyleParser`（或并入 StyleRecalcPass）；`StyleResolver` 名称冻结 | C1.6，退出标准含 grep 零命中 | ⏳ 待 C1 |
| 3 | （反向采用，可选）`StylePool` | 非占用（Px 自创名）；C2.6 key 迁 ruleID 指纹、语义与 MPC 对齐后，可更名 `MatchedPropertiesCache` | C2.6 后评估 | 可选 |
| 4 | （反向采用，建议）C1/C2 新建类 | 语义一致处直接用 Blink 名：`SelectorMatcher`→建议命名 `SelectorChecker`；`CascadeResolver` 保留（Blink 对应物 StyleCascade 为双词术语，Px 名无占用冲突） | C1/C2 建类时 | 建议 |

**无需整改（语义一致，仅结构差异）**：`ComputedStyle`（同义：元素计算后样式快照，存储形式不同属 §八 ⚠️ 注记范围）、`CssTokenizer`（同义）、`RuleData`（同义）、`StyleRecalcPass`（Blink 无同名类，对应阶段概念，无占用）。
