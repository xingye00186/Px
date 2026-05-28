# Px Framework 演示应用计划

## Context

用户希望创建一个**演示应用**作为 Px 框架的使用指南，不仅展示 vc-ui 组件库，还要展示整个框架的能力、缺失功能和设计规范。

这个演示应用将成为：
1. Px 框架的功能展示
2. 组件库 API 文档
3. 设计规范的参考
4. 缺失功能的报告单

## 目标

创建一个 `apps/component-showcase/` 应用，包含：
- 所有 vc-ui 组件的交互式演示
- 框架核心能力的演示（布局、事件、滚动）
- CSS 设计规范的可视化
- 缺失/待实现功能的标注

## 已完成探索

### VC-UI 组件库（58 个组件）
- 表单输入: 11 个
- 选择器: 9 个
- 展示: 13 个
- 布局: 4 个
- 模态框: 8 个
- 数据可视化: 2 个
- 复杂组件: 6 个
- 专用: 2 个

### 渲染能力（~50% 完整）
- 已实现: rect, text, button, input, scroll-container, flex, grid
- 缺失: 圆角、阴影、边框、渐变、图片、键盘导航

## 实施计划

### Step 1: 创建基础展示应用框架

**目录**: `apps/component-showcase/`

**UI 设计**:
- 窗口尺寸: 1200x800
- 左侧导航: 220px 宽，按组件类别分类
- 右侧内容区: 980px 宽，显示选中组件的演示和说明
- 样式: 类似 Element UI 的文档风格

**导航结构**:
```
├── 基础组件
│   ├── Button 按钮
│   ├── Icon 图标
│   └── Text 文本
├── 表单组件
│   ├── Input 输入框
│   ├── Switch 开关
│   ├── Slider 滑块
│   ├── Select 选择器
│   └── ...
├── 展示组件
│   ├── Tag 标签
│   ├── Badge 徽章
│   ├── Progress 进度条
│   └── ...
├── 布局组件
│   ├── Row/Col 网格
│   └── Card 卡片
├── 模态框
│   ├── Modal 弹窗
│   ├── Drawer 抽屉
│   └── Message 消息
├── 数据展示
│   ├── Table 表格
│   ├── Tree 树形
│   └── ...
└── 框架能力
    ├── 布局系统
    ├── 事件系统
    └── 滚动系统
```

**文件结构**:
```
apps/component-showcase/
├── App.vue                 # 主入口，侧边导航 + 内容区
├── main.php                # 入口文件 (1200x800)
├── project.yml             # 构建配置
├── gen/                    # 编译输出
└── showcase/
    ├── ShowcaseItem.vue    # 单个组件展示卡片
    └── ShowcasePanel.vue   # 组件详情面板
```

**导航设计**:
- 左侧导航按类别分类
- 点击后右侧显示对应组件的演示
- 每个组件包含：属性说明、使用示例、渲染效果

### Step 2: 实现组件演示页面

按优先级分批实现：

**第一批（核心表单）**:
1. Button - 基础按钮
2. Input - 文本输入
3. Switch - 开关
4. Slider - 滑块

**第二批（展示组件）**:
5. Text/Title/Paragraph - 文本
6. Tag/Badge - 标签徽章
7. Icon - 图标
8. Progress - 进度条

**第三批（选择器）**:
9. Select - 下拉选择
10. DatePicker - 日期选择
11. ColorPicker - 颜色选择

**第四批（布局+模态）**:
12. Row/Col - 网格布局
13. Card - 卡片容器
14. Modal - 模态框
15. Drawer - 抽屉

### Step 3: 每个组件测试流程

对每个新增组件：
1. 创建演示 `.vue` 文件
2. 在 App.vue 中添加导航入口
3. 运行 `build.bat component-showcase --run`
4. 截图验证渲染效果
5. 如有问题，修复后重新测试
6. 记录问题和缺失功能到文档

### Step 4: 创建文档

**文件**: `docs/component-framework-guide.md`

内容包括：
1. 框架概述
2. 安装配置
3. 快速开始
4. 组件分类索引（链接到 showcase 中的演示）
5. CSS 设计规范
6. 布局系统
7. 事件系统
8. AOT 编译
9. 已知限制/缺失功能

## 关键文件

- `apps/component-showcase/App.vue` - 主展示应用
- `apps/component-showcase/main.php` - 入口
- `apps/component-showcase/project.yml` - 构建配置
- `docs/component-framework-guide.md` - 框架文档
- `docs/missing-features.md` - 缺失功能报告
- `library/vc-ui/` - 组件库

## 验证方式

1. `build.bat component-showcase` 成功生成 exe
2. 截图验证所有组件渲染正确
3. 交互测试（点击、输入、滚动）
4. 检查 AOT 编译无错误