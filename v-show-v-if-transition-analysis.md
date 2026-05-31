# Px 框架中 v-show 和 v-if 的实现分析

## 一、总体架构对比

### v-if 和 v-show 的区别

| 特性 | v-if | v-show |
|------|------|--------|
| **编译时处理** | 条件判断代码生成 | 样式条件化 |
| **运行时机制** | VNode 树重建 | DOM 保留，样式修改 |
| **实现方式** | 闭包生成器 + if 语句 | 动态 style 属性 |
| **性能特点** | 切换代价大（VNode 树重建） | 切换代价小（仅样式修改） |
| **适用场景** | 大幅修改结构 | 频繁显示/隐藏 |

---

## 二、v-if 的实现（条件渲染）

### 2.1 编译流程

#### Step 1: collectVNodeBindKeys() 提取 bind keys
文件：framework/compiler/sfc-compiler.php:239-252

仅支持简单变量名（如 $showPanel），不支持复杂表达式。
提取的 key 会在编译的 script 块中注入 public 属性。

#### Step 2: generateVNodeExpr() 生成代码

关键逻辑（698-1004行）：如果任何子节点有 v-if/v-else-if/v-else，则使用闭包构建器而非内联数组。

生成的 PHP 代码示例：
```
VNode::h('div', [...], (function() {
    $c = [];
    if ($this->showPanel) {
        $c[] = VNode::h('div', ['class' => 'panel'], 'Panel');
    } else if ($this->showDialog) {
        $c[] = VNode::h('div', ['class' => 'dialog'], 'Dialog');
    } else {
        $c[] = VNode::h('div', ['class' => 'default'], 'Default');
    }
    return $c;
})())
```

#### Step 3: v-if/v-else-if/v-else 链处理

文件：framework/compiler/sfc-compiler.php:778-1004

IfElseChain 处理流程：
- 检测连续相同条件的 v-if 节点，将其合并到一个 if 块中
- 按 v-if → v-else-if → v-else 顺序生成链式 if 语句
- 优化：多个条件相同的 v-if 合并为单个 if 块

### 2.2 v-if 的限制

1. 仅支持简单变量：v-if="showPanel" ✓，v-if="showPanel && canEdit" ✗
2. 不支持表达式：需要在 script 中预先计算条件
3. 必须按链式排列：v-if → v-else-if → v-else 必须相邻，中间不能有其他元素

### 2.3 使用示例

计算器 Display 组件（apps/calculator-ng/components/CalculatorDisplay.vue）：
```html
<template>
  <div style="left:0px;top:0px;width:318px;height:100px">
    <span v-if="hasMemory === '1'" style="left:4px;top:4px;font-size:12px;color:#FF9F0A">M</span>
    <span style="left:0px;top:24px">{{ expression }}</span>
  </div>
</template>

<script lang="php">
    public string $hasMemory = '0';
</script>
```

---

## 三、v-show 的实现（条件可见性）

### 3.1 编译流程

文件：framework/compiler/sfc-compiler.php:257-263, 594-604, 1006-1009

Step 1: 提取 bind key
- 仅支持简单变量名

Step 2: 编译时处理 v-show
- 解析条件表达式
- 保存原始 style
- 标记为 __vShowCond 和 __vShowOrigStyle，用于后续处理

Step 3: 生成条件化 style
- 在 props 循环后添加条件化的 style 属性

### 3.2 v-show 机制

核心思想：通过 CSS visibility 属性控制可见性

生成的代码示例：
```
VNode::h('div', [
    'style' => ($this->isVisible) 
        ? 'background:#2196F3;left:10px;top:50px'
        : 'background:#2196F3;left:10px;top:50px;visibility:hidden'
], 'Content')
```

CSS 属性选择：
- visibility:hidden 而非 display:none
- 原因：保留元素占位空间，避免重排
- display:none 会彻底移除元素盒子，导致重排
- visibility:hidden 仅隐藏内容，保持盒子尺寸

### 3.3 使用示例

```html
<div v-show="isVisible" style="background:#2196F3">
  Toggle Me
</div>
```

编译为：
```php
VNode::h('div', [
    'style' => ($this->isVisible) 
        ? 'background:#2196F3' 
        : 'background:#2196F3;visibility:hidden'
], 'Toggle Me')
```

---

## 四、Transition 组件设计方案

### 4.1 设计策略

Px 框架当前没有内置动画系统（GDI 渲染引擎不原生支持）。
Transition 组件有两个实现方向：

方案 A：状态驱动 + 手动步进（推荐）
- 利用 Scheduler::addMacrotask() 实现帧动画
- 组件内部维护动画状态
- 原理：监听 show prop 变化 → 启动 macrotask 序列 → 逐帧更新样式 → 完成后清理

方案 B：时间戳驱动
- 记录起始时间
- 通过每次 render 计算已进度时间
- 推导中间帧

---

### 4.2 完整实现：BaseTransition 组件

文件结构：
```
apps/example/
├── App.vue                    根组件
├── components/
│   └── Transition.vue         动画包装组件
└── gen/
    ├── AppComponent.php
    └── TransitionComponent.php
```

Transition.vue 模板设计：
```html
<template>
  <div v-show="isAnimating || finalShow" 
       :style="'opacity:' . opacityValue . ';left:' . finalLeft . 'px'">
    <slot></slot>
  </div>
</template>
```

脚本实现要点：
- 维护 isAnimating、finalShow、opacityValue、finalLeft 等状态
- 配置 animationDuration（毫秒）
- 启动进入动画 startEnterAnimation()：opacity 0→1，left -10→0
- 启动离开动画 startLeaveAnimation()：opacity 1→0，left 0→-10
- 调度帧动画 scheduleAnimationFrame()：向 Scheduler 添加 macrotask
- 更新帧 updateAnimationFrame()：计算进度，更新样式，继续调度

---

### 4.3 改进方向

#### 问题 1：prop 变化检测

当前 v-if/v-show 在编译时直接读取组件属性，运行时无法主动检测变化。

解决方案：在组件的 setBindValue() 中检测特定 prop
```php
public function setBindValue(string $bindKey, string $value): void
{
    switch ($bindKey) {
        case 'show':
            $newShow = ($value === 'true');
            if ($newShow !== $this->show) {
                $this->show = $newShow;
                if ($newShow) {
                    $this->startEnterAnimation();
                } else {
                    $this->startLeaveAnimation();
                }
            }
            break;
    }
}
```

#### 问题 2：精确时间控制

Px 框架缺少 setTimeout()，macrotask 仅能按 tick 调度。

解决方案：保存 startTime，每帧计算 (now - startTime) / duration
```php
private int $startTime = 0;

public function updateAnimationFrame(): void
{
    $now = (int)(microtime(true) * 1000);
    $elapsed = $now - $this->startTime;
    $progress = min(1.0, $elapsed / $this->animationDuration);
    
    // Apply easing and state updates...
}
```

#### 问题 3：缓动函数

可添加 easing 函数库，例如：
```php
private function easeInOutQuad(float $t): float
{
    return $t < 0.5 ? 2 * $t * $t : -1 + (4 - 2 * $t) * $t;
}
```

---

## 五、对比总结

| 维度 | v-if | v-show | Transition |
|------|------|--------|-----------|
| **编译时机制** | VNode 条件代码生成 | Style 条件化 | 不适用 |
| **运行时机制** | VNode 树重建 | CSS 属性修改 | 逐帧状态更新 |
| **性能** | 低频切换最优 | 高频切换最优 | 视动画复杂度 |
| **支持场景** | 大幅 UI 变更 | 简单显示/隐藏 | 平滑过渡效果 |
| **AOT 兼容性** | 完全支持 | 完全支持 | 需闭包支持 |
| **存储开销** | 低 | 高 | 中等 |

---

## 六、开发建议

### 6.1 何时使用 v-if

- 条件组件：根据权限显示不同面板
- 模态对话框：创建/销毁完整的 UI 树
- 选项卡内容：切换时完全重建内容

### 6.2 何时使用 v-show

- 切换开关：频繁显示/隐藏同一元素
- 折叠面板：多次展开/收起
- 浮层：工具提示、菜单等临时浮层

### 6.3 何时使用 Transition

- 页面过渡：路由变更时的淡入淡出
- 列表项动画：添加/删除时的动画反馈
- 模态打开：dialog 的缩放进入动画

---

## 七、相关文件速查

| 文件 | 功能 |
|------|------|
| framework/compiler/sfc-compiler.php:239-252 | 提取 v-if/v-show bind key |
| framework/compiler/sfc-compiler.php:594-604 | v-show 编译处理 |
| framework/compiler/sfc-compiler.php:698-1004 | v-if 链生成 |
| framework/compiler/sfc-compiler.php:1006-1009 | 生成条件化 style |
| framework/compiler/expression/ExpressionParser.php | 表达式解析 |
| framework/ReactiveComponent.php:58-79 | markDirty + scheduleUpdate |
| framework/Core/Scheduler.php:37-63 | macrotask 机制 |
| framework/Platform/PlatformEvent.php:69-78 | TimerEvent 类 |
| AGENTS.md:9.6-9.7 | 使用文档 |

