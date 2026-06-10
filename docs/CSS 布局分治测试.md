## ：全自动 CSS 布局与样式分治测试与迭代修复（基于 Px 框架，无需用户提供命令）

**角色定位**  
你是一个具备内置浏览器以及**完全自主控制 Px 框架环境**能力的 AI 助手。你可以读取、理解并修改 Px 框架的所有源码，可以执行 PHP CLI 命令（包括调用编译器、运行应用），可以生成并运行脚本，无需用户提供任何额外命令或环境配置。你的目标是：**将任意给定的复杂 HTML 页面，通过分治测试和增量迭代，在 Px 框架中一比一复刻，使得框架渲染结果与浏览器像素级一致**。

**核心原则**  
- **最小化**：每次只测试一个 CSS 特性或一个页面区域。  
- **数据驱动**：输出几何信息（位置、尺寸），而不是凭眼看。  
- **增量修复**：通过一个简单用例 → 修复框架 → 回归测试 → 加入复杂用例。  
- **自动化**：整个流程无需人工干预。  
- **自力更生**：分析框架源码，找到编译入口、运行入口、如何添加布局快照输出，并执行所有必要命令。

**输入**  
- 目标 HTML 文件的完整内容（例如 `xx.html`）。  
- Px 框架源码目录（已提供，AI 可读）。  

**输出**  
- 分治测试用例集（HTML 文件）。  
- 浏览器布局样式快照 JSON。  
- 引擎布局样式快照 JSON。  
- 差异报告。  
- 待完善清单与修复计划（针对框架源码或 `.vue` 模板）。  
- 最终验证报告（所有用例通过，复杂页面完全复刻）。

---

## 一、前置工作：使 Px 框架能够输出布局快照

Px 框架本身布局快照导出功能有无须先核查，若无先为框架添加该能力。步骤示例如下：

### 1.1 分析现有代码
- 查看 `framework/Core/Application.php` 和 `framework/Rendering/RenderTreeManager.php`，了解 `RenderNode` 树的结构。
- 找到 `Application::render()` 方法，在渲染后可以拿到 `$rootRenderNode`。

### 1.2 添加序列化方法
生成以下补丁，并自动应用到框架中（通过文件写入或 `patch` 命令）：

**`framework/Core/Application.php`** 新增方法：
```php
public function dumpLayoutToFile(string $path): void
{
    $root = $this->renderTreeManager->getRootRenderNode();
    $data = $this->serializeRenderNode($root);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

private function serializeRenderNode(?RenderNode $node): ?array
{
    if (!$node) return null;
    return [
        'type' => $node->type,
        'x' => $node->x,
        'y' => $node->y,
        'w' => $node->w,
        'h' => $node->h,
        'visualW' => $node->visualW,
        'visualH' => $node->visualH,
        'layer' => $node->layer,
        'style' => $node->style,
        'children' => array_map([$this, 'serializeRenderNode'], $node->children),
    ];
}
```

### 1.3 修改入口文件 `apps/myapp/main.php`
自动生成或修改该文件，添加 `--dump-layout` 命令行参数支持：
```php
$app = Application::create();
$app->mount(new AppComponent());
if (in_array('--dump-layout', $argv)) {
    $app->dumpLayoutToFile('engine_layout.json');
    exit(0);
}
$app->run();
```

### 1.4 编译组件
自动执行：
```bash
php framework/compiler/sfc-compiler.php apps/myapp/App.vue
```

这样，引擎就可以通过 `php apps/myapp/index.php --dump-layout` 输出布局 JSON。

---

## 二、复杂页面拆分与分步实现策略

目标 HTML 往往是一个完整的大页面，直接一次性转换并测试很难定位问题。应采用**分而治之**的方法：

### 2.1 静态分析目标 HTML
- 识别页面中的独立区域（如：侧边栏、头部、内容区、表格、卡片列表等）。
- 列出每个区域所使用的 CSS 特性（Flex、Grid、Position、滚动等）。

### 2.2 生成拆分计划
AI 输出一个拆分表，例如：

| 区域名 | 包含元素 | CSS 特性 | 预计实现难度 |
|--------|----------|----------|--------------|
| 侧边栏 TOC | `<aside>` + `<nav>` | Flex 列、position: sticky、滚动 | 中 |
| Hero 头部 | `.hero` | Flex 行、渐变背景、绝对定位伪元素 | 低 |
| 导读卡片 | `.reading-guide` | Flex 列、border-left、特殊字体 | 低 |
| 章节表格 | `<table>` | 表格布局、边框、背景色 | 低 |
| 代码块 | `<pre><code>` | 背景色、内边距、等宽字体 | 低 |
| 能力差距表格 | 复杂 Grid 表格 | Grid 布局、fr 单元、gap | 高 |
| 滚动高亮交互 | 目录 + 滚动监听 | 需要 JavaScript / PHP 事件 | 高（暂缓） |

### 2.3 分步实现与测试顺序
按以下顺序实现并测试：
1. **独立无关区域**（Hero、卡片、表格、代码块）—— 不依赖复杂布局，可快速验证。
2. **Flex 布局区域**（侧边栏、卡片组）。
3. **Grid 布局区域**（能力矩阵表格）。
4. **滚动与交互区域**（侧边栏 sticky、目录高亮）—— 最后处理，因为需要事件系统。

每个区域先拆分为独立的 `.vue` 组件然后在 `App.vue` 中组合。调用sfc-compiler编译生成相关component.php文件。

---

## 三、分治测试自动化闭环

编写一个主控脚本，实现以下完整循环：

### 步骤 1：生成浏览器快照（内置浏览器）
- 对每个测试用例（包括各区域独立组件和最终整页），启动加载 HTML，执行 `serializeLayout`，保存 `browser_{id}.json`。

### 步骤 2：生成引擎快照
- 对于每个测试用例，生成对应的 `.vue` 文件（内容为该区域 HTML + 样式）。
- 调用编译器：`php framework/compiler/sfc-compiler.php apps/myapp/Test_{id}.vue`
- 修改 `apps/myapp/main.php` 临时加载该组件，运行 `--dump-layout` 得到 `engine_{id}.json`。

### 步骤 3：对比差异
- 编写比较函数，忽略路径差异（因为引擎中的元素可能因包装不同而路径不同，改用 class+id+父级关系匹配）。
- 输出差异报告，格式：
  ```
  [FAIL] HeroComponent: .hero h1 left
    browser: 44.00
    engine:  40.00
    diff: -4.00
    possible cause: padding-left not applied correctly.
  ```

### 步骤 4：自动修复尝试
根据差异类型，尝试以下修复策略：
- **所有改动严格遵循css标准并查证。
- **如果差异是应用层的问题，就优先修改应用层。
- **如果差异出现在某个 CSS 属性上**：检查 `CssMappings` 中该属性的解析是否完整，若不完整，自动添加映射并重新编译。
- **如果差异出现在 Flex/Grid 布局计算上**：分析 `FlexLayoutStrategy` 或 `GridLayoutStrategy` 中对应算法的代码，生成补丁并应用。
- **如果差异是由框架缺失功能导致**（如 `position: sticky`）：记录到“待完善清单”，并暂时禁用该特性（用替代布局实现）。

应用补丁后，重新执行步骤 2-3，直到差异消除或达到最大迭代次数（如 10 次）。

### 步骤 5：集成测试
当所有独立区域测试通过后，生成完整的 `App.vue`（组装所有子组件），运行整页测试，重复步骤 3-4。

### 步骤 6：最终报告
输出一份 Markdown 报告，包含：
- 所有测试用例的结果（通过/失败）。
- 应用的修复补丁列表。
- 无法自动修复的项（如交互滚动高亮，需人工或后续实现）。
- 最终整页对比的差异图（如果仍有差异，输出 JSON 差异）。

---

## 五、最终输出的具体内容

请 AI 生成以下文件：

### 5.1 `auto_test.php`（主控脚本）
```python
# 自动化所有测试用例，循环修复，输出报告
```

### 5.2 `test_cases/` 目录
包含所有分治测试用例的 HTML 文件（层级 0~7 以及各区域组件）。

### 5.3 `patches/` 目录
存放针对 Px 框架的补丁（`.diff` 文件），按修复顺序编号。

### 5.4 `run_all.sh` / `run_all.bat`
一键运行脚本，调用 php 主控。

### 5.5 `report.md`
最终验证报告模板。

---

## 六、成功标准
- 所有层级 0~6 的基础测试用例通过。
- 复杂页面的每个区域独立测试通过。
- 整页渲染结果与浏览器截图像素级匹配（或差异在可接受范围）。
- 自动修复所有可自动修复的差异--严格按照css标准。

若仍有无法自动修复的问题（如 `position: sticky` 需要 C++ 扩展改动），AI 应在报告中明确列出，并给出迭代建议。

---
