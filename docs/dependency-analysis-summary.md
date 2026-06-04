# AOT 编译依赖收集 — 验证总结

## 实现组件

| 组件 | 路径 | 功能 |
|------|------|------|
| 依赖分析器 | `tools/dependency-analyzer.php` | 从入口 main.php 递归 AST 分析，生成 dep.json |
| 配置生成器 | `tools/generate-dep-project.php` | 将 dep.json 文件列表注入 project.yml，输出 project.dep.yml |
| Composer 隔离 | `tools/composer.json` | 独立 vendor/，与 swoole_compiler 的 vendor 完全隔离 |
| 构建集成 | `build.bat` Step 1.5.5 | 在 SFC 编译后、AOT 编译前，自动运行依赖分析 |

## 验证项目清单

排除 skia-poc，共验证 **7** 个项目：

| # | 项目 | 类型 | gen/ 来源 | PHP 文件 | 框架文件 | C++ 文件 | 说明 |
|---|------|------|-----------|---------|---------|---------|------|
| 1 | calculator-ng | 有 .vue | 已有 | 39 | 29 | 2 | 基线验证项目 |
| 2 | design-guide | 有 .vue | 已有 | 43 | 30 | 2 | 10 个 gen 组件 |
| 3 | list-test | 有 .vue | 已有 | 35 | 30 | 2 | 2 个 gen 组件 |
| 4 | multi-scroll | 有 .vue | SFC 编译生成 | 35 | 30 | 2 | gen/ 为空，需先 SFC |
| 5 | bilibili | 有 .vue + component-libraries | SFC 编译生成 | 41 | 30 | 2 | 8 个 gen 组件，component-libraries 保留 |
| 6 | aot-property-test | 无 .vue | 不需要 | 1 | 0 | 0 | 独立测试，自包含 |
| 7 | aot-syntax-test | 无 .vue | 不需要 | 1 | 0 | 0 | 独立测试，自包含 |

## 效率提升

| 指标 | 旧（目录通配符） | 新（精确分析） | 改善 |
|------|----------------|---------------|------|
| 框架 PHP 文件 | 54（全部） | 29-30（实际依赖） | **-44%~-46%** |
| 总 PHP 编译量 | ~64-66 | 35-43 | **-35%~-45%** |
| C++ 文件 | 2（全量） | 2（按需） | 保持不变 |

## 验证中发现并修复的问题

### 问题 1：php-parser v5 API 不兼容
- **症状**：`Call to undefined method createForNewestSupportedLevel()`
- **原因**：php-parser v5 的方法名为 `createForNewestSupportedVersion()`
- **修复**：改方法调用

### 问题 2：CLI 代码在 require 时自动执行
- **症状**：单元测试 require 分析器时立即执行 CLI 逻辑
- **原因**：CLI 入口代码未被条件包裹
- **修复**：用 `$GLOBALS['_TEST_MODE']` 守卫 CLI 执行块

### 问题 3：部分项目 gen/ 目录缺失
- **症状**：multi-scroll 和 bilibili 没有 gen/ 文件，分析器输出不包含组件
- **原因**：gen/ 由 SFC 编译器生成，需要先运行 sfc-compiler.php
- **流程确认**：build.bat 中 Step 1（SFC 编译）在 Step 1.5.5（依赖分析）之前，所以正常构建流程中不会遇到此问题

### 问题 4：C++ 文件在 sources 中的处理
- **发现**：原始 project.yml 的 sources 包含 `../../cpp` 目录，AOT 编译器需要 .cc 文件在 sources 中才能编译链接
- **处理**：generate-dep-project.php 将 C++ 文件也写入 sources 节（在 PHP 文件列表之后）

### 问题 5：generate-dep-project.php 变量名重复
- **症状**：`$cxxFiles` 变量被赋值两次
- **修复**：删除重复行

## 边界场景处理

| 场景 | 处理方式 | 验证结果 |
|------|---------|---------|
| 无 .vue 文件项目（aot-property-test, aot-syntax-test） | 直接分析 main.php | 正确输出 1 个 PHP 文件 |
| 有 .vue 但 gen/ 为空（multi-scroll） | build.bat 流程保证先 SFC 再分析 | 分析器安全网正确扫描 gen/ |
| 组件库（bilibili component-libraries） | project.dep.yml 保留 component-libraries 节 | 正确保留 |
| C++ 函数调用 | CXX_MAPPING 匹配 vue_/sk_ 前缀 | 正确关联 .cc + stub |
| 独立测试项目不调用任何框架 | AOT 测试项目只包含 main.php | 正确输出单文件 |
| 安全网（gen/ 兜底） | 递归分析后扫描所有未访问的 gen/*.php | 有效 |

## 项目文件清单

所有验证完成后，各项目的 gen/、dep.json、project.dep.yml 状态：

### 已有 gen/ 且已生成 dep.yml
- **calculator-ng**: gen/ 7 文件, project.dep.yml ✓
- **design-guide**: gen/ 9 文件, project.dep.yml ✓
- **list-test**: gen/ 2 文件, project.dep.yml ✓

### SFC 编译后生成 dep.yml
- **multi-scroll**: gen/ 2 文件 (AppComponent, ComponentFactory), project.dep.yml ✓
- **bilibili**: gen/ 8 文件 (含 6 个子组件), project.dep.yml ✓

### 无 gen/ 需求
- **aot-property-test**: project.dep.yml ✓
- **aot-syntax-test**: project.dep.yml ✓

## 后续建议

1. **初次构建**：如果 gen/ 不存在，需先运行 SFC 编译（正常 build.bat 流程会处理）
2. **增量构建**：gen/ 文件已存在时，直接运行 `build.bat <app>` 即可
3. **手动运行分析**：
   ```
   php tools/dependency-analyzer.php --app=<app-name>
   php tools/generate-dep-project.php --app=<app-name>
   ```
4. **回退机制**：如果 project.dep.yml 不存在或分析失败，build.bat 自动回退到原始 project.yml
