# Px 框架组件定位传递 Bug 修复方案

## Context

### 问题背景

用户最近报告了一个回归 bug：点击计算器数字按钮后，"整个都往右下夸张的偏移了"。

**根因追踪**：

`Application::transferComponentPositioning()` 方法负责将 `#component` 占位符的 `left/top` 定位传递到子组件根元素的 style。该方法使用基于字符串的正则替换来移除已有的 `left`/`top` 声明，然后追加新的值。

当前实现有两个相关的 bug：

1. **正则 `\b` 词边界缺陷**：`/\b(left|top)\s*:\s*\d+\s*;?\s*/i` 中的 `\b` 要求 `left`/`top` 前是 `\W` 字符。但当样式字符串如 `background:#2C2C2Eleft:11;` 时，`E` 和 `l` 都是 `\w` 字符，`\b` 不匹配，导致该次 `preg_replace` 无法移除之前注入的 `left:11;`。每次 re-render 都会追加新的 `left:11;top:524;`，造成样式字符串退化。

2. **缺少分号分隔**：`$existingStyle .= "left:{$left};"` —— 当 `$existingStyle` 不以 `;` 结尾时（如 `background:#2C2C2E`），拼接结果为 `background:#2C2C2Eleft:11;`，CSS 解析器将 `#2C2C2Eleft:11` 视为单个颜色值。

这些 bug 在 `matchComponentNode` 重用路径中被触发（每次点击数字都会触发 re-render，走重用路径）。

### 文档分析

`docs/修复 Px 框架以直接兼容 Vue 3 模板（定位与子组件复用）.md` 提出方案：
1. LayoutResolver 无条件应用 left/top（当前代码已实现）
2. 创建 `mergePlaceholderStyle` 使用结构化（数组）合并替代字符串操作
3. 在 `expandComponentNode` 和 `matchComponentNode` 中均调用

### 修复目标

用结构化数组合并替换 `transferComponentPositioning` 中脆弱的字符串操作，从根本上解决反复 re-render 时样式字符串退化的问题。

---

## 修复方案

### 核心思路：结构化 CSS 数组合并

将 `transferComponentPositioning` 重写为使用数组操作：

1. **解析阶段**：将 `$placeholderStyle` 解析为 `['left' => 11, 'top' => 524]`（当前已正确实现）
2. **定位目标**：遍历 `#root` 链找到第一个可渲染元素（当前已正确实现）
3. **合并阶段（改）**：
   - 将目标元素的 `$target->props['style']` 字符串解析为关联数组 `['property' => 'value', ...]`
   - 从数组中删除 `left`/`top` 键
   - 将新的 `left`/`top` 值写入数组
   - 将数组重新序列化为 CSS 字符串

这样彻底消除了正则匹配的脆弱性，确保幂等性。

### 方案对比

| 方案 | 优点 | 缺点 |
|------|------|------|
| **A：最小修复**（修复 `\b` → `(?<![a-zA-Z])` + 加分号） | 改动最小，2 行 | 仍依赖正则，边缘情况仍可能出错 |
| **B：结构化数组合并**（推荐） | 彻底解决，幂等，可读性强 | 需额外解析/序列化函数 |
| **C：LayoutResolver 直接读 #component style | 最简洁 | 需要修改 LayoutResolver 签名，耦合增加 |

**推荐方案 B**。

---

## 修改文件

### 1. `framework/Rendering/CssMappings.php` — 新增辅助方法

在 `CssMappings` 类中新增两个 `public static` 方法：

```php
/**
 * 将 CSS 样式字符串解析为键值对数组。
 * 输入: "background:#2C2C2E;color:#FFF;left:10px"
 * 输出: ['background' => '#2C2C2E', 'color' => '#FFF', 'left' => '10px']
 *
 * AOT 安全：仅使用字符串操作和数组遍历。
 */
public static function parseStyleStringToArray(string $style): array
{
    $result = [];
    $pairs = explode(';', $style);
    foreach ($pairs as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;
        $colonPos = strpos($pair, ':');
        if ($colonPos === false) continue;
        $prop = trim(substr($pair, 0, $colonPos));
        $value = trim(substr($pair, $colonPos + 1));
        if ($prop !== '') {
            $result[$prop] = $value;
        }
    }
    return $result;
}

/**
 * 将键值对数组序列化为 CSS 样式字符串。
 * 输入: ['background' => '#2C2C2E', 'left' => '11px']
 * 输出: "background:#2C2C2E;left:11px;"
 * 输入: []
 * 输出: ""
 *
 * AOT 安全：仅使用字符串操作和数组遍历。
 */
public static function buildStyleStringFromArray(array $style): string
{
    if (empty($style)) {
        return '';
    }
    $parts = [];
    foreach ($style as $prop => $value) {
        $parts[] = "{$prop}:{$value}";
    }
    return implode(';', $parts) . ';';
}
```

**why static**：这两个方法不依赖实例状态，设计为纯函数。`Application` 中通过 `CssMappings::parseStyleStringToArray()` 调用，无需创建 `CssMappings` 实例。

**AOT 安全验证**：
- 不使用 `compact()`/`extract()`/`eval()`
- 不使用 `$obj->$prop` 动态属性
- 字符串操作仅使用 `explode`/`strpos`/`trim`/`substr`/`implode`/`empty`

### 2. `framework/Core/Application.php` — 重写 `transferComponentPositioning()`

**修改**：第 382-403 行的合并逻辑替换为调用 `CssMappings` 的静态方法。

**删除**：
```php
$existingStyle = $target->props['style'] ?? '';
// 移除已有的 left: 和 top: 声明（不区分大小写，可能带 px 后缀）
$existingStyle = preg_replace(
    '/\b(left|top)\s*:\s*\d+\s*px\s*;?\s*/i',
    '',
    $existingStyle
);
// 也移除不带 px 后缀的（兼容其他来源）
$existingStyle = preg_replace(
    '/\b(left|top)\s*:\s*\d+\s*;?\s*/i',
    '',
    $existingStyle
);

if ($left !== null) {
    $existingStyle .= "left:{$left};";
}
if ($top !== null) {
    $existingStyle .= "top:{$top};";
}
$target->props['style'] = $existingStyle;
```

**替换为**：
```php
// 将现有 style 解析为数组 → 结构化合并 → 序列化回字符串
$existingStyle = $target->props['style'] ?? '';
$styleArray = CssMappings::parseStyleStringToArray($existingStyle);
// 移除已有的 left/top（保证幂等性）
unset($styleArray['left'], $styleArray['top']);
// 写入新的定位值（带 px 单位，与原始解析值格式一致）
if ($left !== null) { $styleArray['left'] = $left . 'px'; }
if ($top !== null) { $styleArray['top'] = $top . 'px'; }
$target->props['style'] = CssMappings::buildStyleStringFromArray($styleArray);
```

**注意**：
- `$left` 和 `$top` 当前为 `(int)` 类型（由第 348-351 行的 `(int) trim(substr($pair, 5))` 转换）
- `$left . 'px'` 转换为字符串 `"11px"`，与解析阶段保留的 `"11px"` 格式一致
- 即使 `$left`/`$top` 为 `null`，`unset` 操作也是安全的（`unset` 不存在的 key 无副作用）

### 3. 确保 `use` 导入

在 `Application.php` 顶部添加（如尚不存在）：
```php
use Px\Rendering\CssMappings;
```

### 4. `framework/Core/Application.php`（matchComponentNode 路径验证）

**验证 `matchComponentNode()`**（第 483-492 行）中已有的 `transferComponentPositioning` 调用：

```php
// matchComponentNode 中的重用路径
$newNode->componentInstance = $instance;
$newNode->children = $instance->getVNodeTree();
$placeholderStyle = $newNode->props['style'] ?? '';
if ($placeholderStyle !== '') {
    $this->transferComponentPositioning($placeholderStyle, $newNode->children);
}
```

**无需修改**：两个路径（`expandComponentNode` 和 `matchComponentNode`）都已调用 `transferComponentPositioning`，且方法体被重写后两者都受益。`matchComponentNode` 中当 `$instance === null` 时会 fall through 到 `expandComponentNode`，后者也调用该方法。

### 5. 测试更新

#### 5.1 `ComponentTreeTest.php` — 新增多轮 re-render 测试

在现有 "组件定位在重用实例后保留" 测试之后，新增一个专门测试多次 re-render 不退化的用例：

```php
test('多次 re-render 后定位值不退化', function () {
    $app = newInstanceWithoutApp();

    // 加载真实组件（与现有测试一致）
    $appDir = realpath(__DIR__ . '/../../apps/calculator-ng');
    require_once $appDir . '/gen/ComponentFactory.php';
    require_once $appDir . '/gen/HistoryPanelComponent.php';

    $owner = new _BubbleTrackerComponent('owner');
    $owner->setId('ownerId');
    $owner->arrowText = '>';

    $patchMethod = new \ReflectionMethod(Application::class, 'patchComponentTree');
    $patchMethod->setAccessible(true);

    $iterations = 5;   // 多次 re-render 暴露样式字符串退化
    $prevRoot = null;
    $instance = null;

    for ($i = 0; $i < $iterations; $i++) {
        // 每次创建新的 VNode 树（模拟真实 re-render）
        $root = VNode::h('#root', ['style' => 'width:340px;height:660px'], [
            VNode::hComponent(
                'HistoryPanelComponent',
                ['style' => 'left:11px;top:524px'],
                ['arrow' => 'arrowText']
            ),
        ]);
        $root->groupId = 'app';

        // 切换 arrowText 使组件进入 dirty 状态（模拟实际业务操作）
        $owner->arrowText = ($i % 2 === 0) ? '>' : 'v';

        // patchComponentTree: 传入旧树 oldNode=$prevRoot 启用实例复用
        $patchMethod->invoke($app, $root, $owner, $prevRoot);

        // 验证实例复用
        $childNode = $root->children[0] ?? null;
        assert_not_null($childNode, "第 {$i} 次应有子节点");
        if ($i === 0) {
            $instance = $childNode->componentInstance;
            assert_not_null($instance, "第 {$i} 次应创建实例");
        } else {
            assert_same($instance, $childNode->componentInstance,
                "第 {$i} 次应复用实例");
        }

        // 展开 #root → 找到子组件根元素
        $childRoot = $instance->getVNodeTree();
        $rootElement = $childRoot;
        while ($rootElement !== null && $rootElement->type === '#root') {
            if (is_array($rootElement->children)) {
                $rootElement = $rootElement->children[0] ?? null;
            } elseif ($rootElement->children instanceof VNode) {
                $rootElement = $rootElement->children;
            } else {
                break;
            }
        }
        assert_not_null($rootElement, "第 {$i} 次应有根元素");

        $style = $rootElement->props['style'] ?? '';

        // ── 断言 1：left:11 存在 ──
        assert(strpos($style, 'left:11') !== false,
            "第 {$i} 次 re-render 后应包含 left:11，实际: {$style}");

        // ── 断言 2：top:524 存在 ──
        assert(strpos($style, 'top:524') !== false,
            "第 {$i} 次 re-render 后应包含 top:524，实际: {$style}");

        // ── 断言 3（关键）：left: 在整个 style 中只出现一次 ──
        // 如果有两次 left:，说明旧值未被清除，样式字符串退化
        $leftPropCount = substr_count($style, 'left:');
        assert($leftPropCount === 1,
            "第 {$i} 次 re-render 后 left: 应恰好出现 1 次（实际 {$leftPropCount} 次），style={$style}");

        // ── 断言 4（关键）：top: 在整个 style 中只出现一次 ──
        $topPropCount = substr_count($style, 'top:');
        assert($topPropCount === 1,
            "第 {$i} 次 re-render 后 top: 应恰好出现 1 次（实际 {$topPropCount} 次），style={$style}");

        $prevRoot = $root;
    }
});
```

**为什么这个测试能捕获退化的根本原因**：

| 场景 | 正则 \b 可正确移除旧 left？ | left: 出现次数 | 测试是否失败？ |
|------|---------------------------|---------------|---------------|
| 第 1 次 re-render | 是（style 还干净） | 1 | 通过 |
| 第 2 次 re-render | 可能否（看前次拼接） | ≥1 | 通过/可能通过 |
| 第 3+ 次 re-render | 否（`#...Eleft` 中 `\b` 不匹配） | ≥2（退化） | **失败** ✅ |

断言 3 和 4 要求 `left:`/`top:` 恰好出现 1 次——如果 `\b` 无法匹配导致旧值未被移除，计数就会 ≥2，测试立即失败。

#### 5.2 `MemoryStressTest.php`（可选）

可在内存压力测试中增加类似的多帧累加检测，验证长时间运行后定位不发生偏移。非当前修复必要。

### 不修改的文件

| 文件 | 原因 |
|------|------|
| `LayoutResolver.php` | 已无条件应用 left/top（无需 position 检查） |
| `sfc-compiler.php` | `:style` 绑定是可选特性，非当前 bug 所需 |

---

## 验证步骤

### 1. 单元测试

```bash
D:\swoole_compiler\php.exe tests/run_all_tests.php
```

所有测试应通过。

### 2. 新增测试验证

```bash
D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php
```

确认"组件定位保留"测试在多次 re-render 后仍正确。

### 3. 手动验证（可选）

用 `D:\swoole_compiler\php.exe` 运行计算器应用，点击数字按钮验证不再出现"整体往右下偏移"。

---

## 回归风险

| 风险 | 可能性 | 影响 | 应对 |
|------|--------|------|------|
| CSS 解析/序列化引入顺序变化 | 低 | 低 | 解析后 key 顺序与 PHP 数组插入顺序一致 |
| 空样式字符串处理 | 低 | 低 | `parseStyleStringToArray('')` 返回空数组 |
| 带 `!important` 的样式值 | 低 | 低 | `!important` 在值中被保留为 `value !important` |
| 带 `:` 的样式值（如 `content:"..."`） | 中 | 低 | `strpos($pair, ':')` 只取第一个 `:` 作为分隔，`content` 中的 `:` 会保留在值中 |
| 不影响现有功能 | — | — | `buildStyleStringFromArray` 输出的字符串语义等价于拼装方式 |

### `content` 属性中 `:` 的处理

若样式中有 `content:"item:1"`，`strpos($pair, ':')` 找到第一个 `:` 后的结果为：
- prop: `content`
- value: `"item:1"`

这是正确的行为，因为 CSS 属性名中不含 `:`，值中的 `:` 会被正确保留。

---

## 关键文件清单

| 文件 | 操作 | 说明 |
|------|------|------|
| `framework/Rendering/CssMappings.php` | 修改 | 新增 `parseStyleStringToArray()` / `buildStyleStringFromArray()` 静态方法 |
| `framework/Core/Application.php` | 修改 | 重写 `transferComponentPositioning()` 中的合并逻辑，调用 CssMappings 静态方法；确认 use 导入 |
| `tests/unit/ComponentTreeTest.php` | 修改 | 增强"组件定位保留"测试，模拟多次 re-render |

---

## 修复效果预期

修复后，每次 re-render 时：
1. `parseStyleStringToArray()` 将 style 解析为键值对数组
2. `unset($styleArray['left'])` 和 `unset($styleArray['top'])` 保证幂等
3. 写入新值 → `buildStyleStringFromArray()` 序列化
4. 无论 re-render 多少次，style 字符串都不会退化
