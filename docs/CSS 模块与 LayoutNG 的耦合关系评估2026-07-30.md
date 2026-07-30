# CSS 模块与 LayoutNG 的耦合关系评估

## 一、结论先行

**两个模块本来就可以各自独立发展，CSS 迭代完成后 LayoutNG 无需任何改动即可受益。** 它们通过 `ComputedStyle` 这个**不可变快照接口**单向耦合——CSS 模块是生产者，Layout 模块是消费者，接口方向是 CSS → Layout 单向流动。

但"独立发展"不等于"零交互"——CSS 迭代会在三个层面改善 Layout 的行为，且存在一处耦合泄漏需要关注。

---

## 二、代码证据：耦合面是单向且稳定的

### 2.1 接口形态：ComputedStyle 是唯一桥梁

Layout 模块的 7 个算法文件全部通过同一个入口消费样式：

```php
// 每个 LayoutAlgorithm::layout() 签名一致
public function layout(
    ConstraintSpace $space,
    ?ComputedStyle $style = null,  // ← 唯一 CSS 输入
    string $textContent = '',
    array $childNodes = [],
    ?PhysicalFragment $inputFragment = null
): PhysicalFragment
```

这与 Blink 完全一致——Blink 的 `LayoutObject` 通过 `StyleRef()` 获取 `ComputedStyle&`，Layout 算法期间**不访问任何 CSS 规则/选择器/级联逻辑**。

### 2.2 Layout 读取的属性清单（取证汇总）

Layout 从 ComputedStyle 读取的属性按影响分类：

| 分类 | 属性数 | 示例 | 影响方式 |
|---|---|---|---|
| 盒模型（直接决定几何） | ~15 | width/height/margin/padding/border-*/box-sizing | 像素级参与计算 |
| 显示模式（决定分派） | ~5 | display/position/overflow/float | LayoutOrchestrator 路由 |
| Flex/Grid（算法核心输入） | ~15 | flex-direction/gap/align-items/grid-template-* | 专用算法消费 |
| 文本（间接影响测量） | ~10 | font-size/font-weight/line-height/white-space | 测量→行盒→几何 |
| 定位偏移 | ~6 | left/top/right/bottom/transform | OOF 算法消费 |
| **纯视觉（不影响布局）** | **~30** | color/opacity/box-shadow/border-radius/background-* | **Layout 不读取** |

**关键发现**：Layout 不读取约 30 个纯视觉属性（颜色/背景/阴影/圆角/透明度等）。这意味着 CSS 模块对这些属性的任何改进（alpha 修复、命名色扩充、currentColor、渐变增强）都**完全不影响 Layout**，零回归风险。

### 2.3 Layout 对 Px\Css 的命名空间依赖

```
Layout 依赖的 Px\Css 类（共 8 个）：
├── ComputedStyle    — 核心接口（稳定，字段只增不删）
├── CssLength        — 值类型（稳定）
├── CssKeyword       — 值类型（稳定）
├── StylePool        — 仅 StylePool::empty() 空样式工厂（稳定）
├── UAStyles         — 仅 isMonospaceType() 查询（C1 新增，稳定）
├── CssValueParser   — 仅 resolveTranslate()（耦合泄漏⚠️）
├── CssMappings      — 仅 parseGridTemplateValue()（耦合泄漏⚠️）
└── CssFlex/CssRect  — 值类型（稳定）
```

---

## 三、Blink 对照：同样的单向耦合

Blink 中 CSS 与 Layout 的耦合结构与 Px 同构：

```
Blink:  CSS 引擎 → ComputedStyle（不可变快照）→ LayoutObject → Fragment
Px:     CSS 引擎 → ComputedStyle（不可变快照）→ LayoutAlgorithm → PhysicalFragment
```

| 维度 | Blink | Px | 一致性 |
|---|---|---|---|
| 接口 | `ComputedStyle&` 不可变引用 | `ComputedStyle` 只读对象 | ✅ |
| 值解析 | Layout 只调 ComputedStyle 方法 | Layout 偶尔直接调 CssValueParser | ⚠️ Px 有泄漏 |
| 模式分派 | `display` → LayoutObject 子类 | `display` → selectAlgorithm() | ✅ |
| 样式 diff | `StyleDifference` 优化 relayout | 无（全量 layout） | 差异（C4 计划补） |
| 继承数据 | `InheritedData` 独立结构+指针共享 | `INHERITED_KEYS` 白名单+声明数组 | 差异（C2.6 评估中） |

---

## 四、CSS 迭代对 LayoutNG 的三层影响

### 第一层：零影响（纯视觉改进）

C3a/C3b 的颜色/值系统改进完全在 Layout 消费面之外：

- alpha 修复 → Layout 不读 alpha
- 命名色 148 → Layout 不读颜色
- currentColor → Layout 不读颜色
- calc 表达式树 → Layout 消费的 CssLength 接口不变（`toPx()` 返回值变了但接口不变）
- 全局关键字 inherit/initial/unset → ComputedStyle 构造期已解析，Layout 读到的仍是具体值

**结论**：这些改进 Layout 完全感知不到，零适配成本。

### 第二层：自动受益（继承补全 → 布局上下文改善）

C1.5 INHERITED_KEYS 补全（+5 键：textTransform/listStyleType/listStylePosition/overflowWrap/wordWrap）会让子元素**正确继承**父元素的这些属性。其中 overflowWrap/wordWrap 影响文本断行→间接影响行盒高度→间接影响 block 布局。

**这不是 bug 而是改善**——之前这些属性未继承导致文本断行不符合 CSS 规范，补全后 Layout 自动获得更正确的输入。LayoutNG 代码无需改动。

### 第三层：需关注（耦合泄漏点）

两处 Layout 直接调用 CSS 解析器（绕过 ComputedStyle 接口）：

| 泄漏点 | 位置 | 风险 |
|---|---|---|
| `CssValueParser::resolveTranslate()` | OOFLayoutAlgorithm L319 | 低——translate 值解析不在 ComputedStyle 字段中，OOF 直接解析 raw 值 |
| `CssMappings::parseGridTemplateValue()` | GridTracker | 中——Grid 模板解析是 CSS 语法职责，不应由 Layout 直接调用 |

这两处是**已有的架构债务**（Layout 越权消费了 CSS 解析能力），不是 CSS 迭代引入的新问题。但它们意味着如果未来 CssValueParser 或 CssMappings 的接口变化，Layout 会受影响。

---

## 五、最终判定

| 问题 | 回答 |
|---|---|
| CSS 迭代完成后 LayoutNG 需要改动吗？ | **不需要**。ComputedStyle 接口不变，Layout 消费方式不变。 |
| 两个模块能各自独立发展吗？ | **能**。它们通过 ComputedStyle 不可变快照单向耦合，CSS 是生产者、Layout 是消费者。CSS 内部的 CascadeResolver/SelectorChecker/StyleSheetContents 等重构对 Layout 完全透明。 |
| 会"完美适配"吗？ | **会自动适配**——CSS 迭代改善的是 ComputedStyle 的**值质量**（更正确的级联、更完整的继承、更准确的颜色），Layout 读取的接口（字段名/类型/语义）不变，所以不需要"适配"动作。 |
| 有什么需要注意的？ | 两处耦合泄漏（CssValueParser/CssMappings 被 Layout 直接调用）建议长期治理——将 `resolveTranslate` 和 `parseGridTemplateValue` 迁入 ComputedStyle 字段或 Layout 内部，消除 Layout 对 CSS 解析器的直接依赖。但这不是 CSS 迭代的阻塞项。 |

**一句话总结**：CSS 和 LayoutNG 的关系就像"编译器"和"CPU"——编译器改进指令解码（CSS 迭代）不影响 CPU 的指令集接口（ComputedStyle），CPU 自动受益于更正确的指令翻译，两个模块沿着接口契约各自独立演进。