# Bilibili 布局修复计划（CSS 标准版）

## 问题分析

### 截图现象
当前运行截图显示：窗口大部分为默认深灰色背景，只有少量白色/粉色元素在顶部，左侧有重复的"[SH]"文字（Unicode字符渲染失败）。这表明布局计算严重异常，几乎所有元素位置都错误。

### CSS 标准根因分析

布局链：
```
ColumnFlexContainer (flex, column)
  └─ MainContent (flex:1, block, overflow-y:auto)
       └─ VideoGrid (display:grid, grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)), gap:16px)
            └─ VideoCards ...
```

#### 核心问题：`resolveGridLayout` 未处理 block-level `width: auto`

CSS Grid Level 1 规范明确规定：
> If the grid container's width is `auto` and it's a block-level element, the used value is the available width — the containing block's content width.

但在 `LayoutResolver.php` 的 `resolveGridLayout` 中：

```php
$width = $this->resolvePercent($style, 'width', 'widthPercent', $parentW);
// resolvePercent returns 0 when width is null/'auto'
$node->w = max(0, (int)$this->applyMinMax($style, $width, true));
// w = 0 — 完全不符合 CSS 标准！
```

而 `resolvePercent` 的实现对 `auto` 返回 0：
```php
$raw = $style[$key] ?? null;
if ($raw === null || $raw === 'auto' || $raw === '' || is_string($raw)) {
    return 0;  // ← 这就是问题根源
}
```

#### 布局时序分析（为什么两遍布局也救不了）

1. **flex Step 1**: `resolveNode(MainContent)` → `resolveNormalFlow` → `resolveNode(VideoGrid)` → `resolveGridLayout` 用 `w=0` 计算 auto-fill → `cols=1, cellW=0` → 所有 grid 子项定位在 `(0,0)`，宽高为 0

2. **flex Step 5**: flex-grow → MainContent 获得 `flex:1` 分配的高度

3. **flex Step 11**: `align-items:stretch` → MainContent 的宽度从父 flex 容器拉伸到正确值

4. **两遍布局**: 
   - 对 MainContent 的子项（VideoGrid）调用 `resolveNode(VideoGrid, gcOffset, ..., MainContent)`
   - **`resolveGridLayout` 再次运行，`$parent` 现在是 MainContent（宽度正确）**
   - 但 `resolvePercent` 仍然返回 0 → `VideoGrid.w = 0` → grid children 再次以 `w=0` 定位！
   - 然后 `finalizeScrollContainer(MainContent, ...)` 将 `VideoGrid.w` 设置为正确宽度
   - **但 grid 子项的定位已经用 w=0 算完了，没有第二次 resolveGridLayout 重新定位它们！**

#### 对比例子：为什么 block/flex 容器没有这个 bug

- **block 容器子项**: `resolveBlockLayout` 的 auto-stack 会设置子项的宽度 `$child->w = $containerW`（第 331 行），且这些子项没有自己的内部子项定位问题
- **flex 容器子项**: 两遍布局中，flex/grid 容器的全重新布局直接在 `chTp->style['width'] = chTp->w` 后调用 `resolveNode`（第 1101-1108 行），`resolvePercent` 会返回显式的像素值而非 0

## 修复方案

### 修复1：`resolveGridLayout` — 使用父容器内容宽度作为 auto 宽度（CSS 标准行为）

**位置**: `LayoutResolver.php` `resolveGridLayout()`，在第 1258 行 `resolvePercent` 之后、第 1270 行 `$node->w` 赋值之前

**修改**: 当 grid 容器没有显式 `width`/`widthPercent`，且 `resolvePercent` 返回 0 时，默认使用父容器的宽度

```php
// 在 resolveGridLayout 中，第 1258 行之后：
$hasExplicitWidth = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);
if (!$hasExplicitWidth && $width === 0 && $parent !== null) {
    $width = $parent->w;
}
// 同样的逻辑用于高度（虽然对 auto-fill 影响较小）
$hasExplicitHeight = array_key_exists('height', $style) || array_key_exists('heightPercent', $style);
if (!$hasExplicitHeight && $height === 0 && $parent !== null) {
    $height = $parent->h;
}
```

**效果**（在两遍布局的第二遍中生效）：
- 第一遍 flex 布局：MainContent 自身 w=0 → VideoGrid 得到 w=0 → 不正确，但无所谓
- 第二遍（两遍布局）：MainContent.w 正确 → VideoGrid 的 `$parent->w` 正确 → `$width = parent.w` → VideoGrid.w 正确 → auto-fill 算对 → grid 子项正确定位

**CSS 标准依据**：
> CSS Grid Level 1: §7.1. If the grid container's width is `auto` (block-level), use the available width (containing block width).

### 修复2（次要）：`resolveFlexLayout` — 相同问题的防范

**位置**: `resolveFlexLayout()` 第 589 行之后

同样的逻辑：当 flex 容器无显式宽度时，使用父容器宽度。这对防止各种嵌套布局组合场景的 bug 很重要。

```php
$hasExplicitWidth = array_key_exists('width', $style) || array_key_exists('widthPercent', $style);
if (!$hasExplicitWidth && $width === 0 && $parent !== null) {
    $width = $parent->w;
}
$hasExplicitHeight = array_key_exists('height', $style) || array_key_exists('heightPercent', $style);
if (!$hasExplicitHeight && $height === 0 && $parent !== null) {
    $height = $parent->h;
}
```

注意：`resolveFlexLayout` 已有 auto-sizing 逻辑（第 1149-1203 行），auto-width 会从子项计算。但那是**在子项布局之后**，影响的是 flex 容器自身的外围宽度。而这里要解决的是 flex 容器作为一个 block-level 元素时应有的**初始宽度**，确保内部的 flex 子项在 Step 1 就能获得正确的主轴/交叉轴尺寸。

### 修复3：`resolveBlockLayout` — 确保非滚动 block 容器的 auto-stack 后对 grid 子项重新定位

**位置**: `resolveBlockLayout()` 第 330 行 auto-stack 中

当前 auto-stack 只设置 `$child->w = $containerW`，但如果是 grid/flex 子项，其内部子项已用错误宽度定位。需要在设置宽度后，对 `display: grid` 或 `display: flex` 的子项重新 `resolveNode`。

```php
if (!$hasExplicitWidth || $child->w === 0) {
    $child->w = max(0, (int)$containerW);
    $child->style['width'] = $containerW;
    // 如果是 grid 或 flex 容器，需要重新 resolve 其内部子项
    $childDisplay = $childStyle['display'] ?? 'block';
    if (($childDisplay === 'grid' || $childDisplay === 'flex') && isset($child->layoutDirty)) {
        $child->layoutDirty = true;
        // 注意：这会触发递归的 resolveNode，子项自身会重新布局
    }
}
```

但这需要小心处理 — 如果在这个循环中重新 resolve 子节点，可能会引起无限递归或重复操作。更好的方式是在 auto-stack 完成后，对所有被修改宽度的 grid/flex 子项做一次统一的重新 resolve。

**简化方案**: 由于 `finalizeScrollContainer` 已经有两遍保障，且大部分 grid 容器都位于 flex 子项或 scroll 容器内（两遍布局会处理它们），这个修复可以作为低优先级的鲁棒性加固。

### 修复4（可选）：VideoGrid 模板显式添加 `width: 100%`

在 `VideoGrid.vue` 的 grid 容器 div 上添加 `width: 100%` 作为显式声明。这是对现有框架 `resolvePercent` 限制的直接适配 — 既然框架还不完全支持 `auto` 宽度填充，显式声明是最保险的方式。

**注意**: 在 CSS 标准中这不应该需要（`auto` 应自动填满），但作为防御性编程和框架兼容性保障值得加上。

## 关键文件
- `framework/Rendering/LayoutResolver.php` — 修复目标（修复1+2+3）
- `apps/bilibili/components/VideoGrid.vue` — 可选：添加 `width:100%`
- `apps/bilibili/gen/VideoGridComponent.php` — 重新编译后自动更新

## 验证方法
1. 运行 `build_run.ps1` 重新构建 bilibili
2. 运行截图脚本并查看结果（grid 卡片应正确定位）
3. 运行 `tests/unit/LayoutResolverTest.php` 确认现有测试通过
4. 对比截图与设计图 `bilibili.png` 检查布局还原度
