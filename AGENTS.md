# Px Framework — AI Agent 路由入口

## 项目定位

Px 是一款 **PHP → 原生 exe** 的跨平台 GUI 框架，模板语法类似 **Vue 3**，渲染引擎基于 **Win32 GDI/Skia**，通过 **Swoole Compiler** 实现 AOT 编译。

```
.vue → sfc-compiler.php → PHP → Swoole Compiler → C++ → MSVC → .exe
```

---

## 目录路由表

| 目录 | 职责 | 修改频率 |
|------|------|----------|
| `framework/` | 核心框架（所有应用共享） | 高 |
| `framework/Layout/` | 布局引擎（Block/Flex/Grid/Inline） | 高 |
| `framework/Paint/` | 绘制层 + Backend 渲染后端 | 中 |
| `framework/Compiler/` | SFC 编译器（.vue → PHP） | 中 |
| `framework/Reactive/` | 原生响应式系统（#[Reactive]） | 低 |
| `framework/Component/` | 组件系统（ReactiveComponent） | 低 |
| `framework/Css/` | 样式解析（CssLength/CssValue/StylePool） | 中 |
| `framework/Text/` | 文本渲染后端（GDI/Skia/DWrite） | 低 |
| `framework/Theme/` | 主题系统（跨平台样式） | 低 |
| `framework/Render/` | RenderNode 树管理 | 中 |
| `apps/` | 各独立应用目录 | 高 |
| `cpp/` | C++ 桥接层（skia_render.cc 等） | 低 |
| `tests/` | 单元测试 + 截图测试 | 高 |
| `stub/` | PHP stub（C++ 原生函数声明） | 低 |
| `docs/` | 设计文档 | — |

---

## 高频任务路径

| 任务 | 命令/入口 |
|------|-----------|
| 构建应用 | `build.bat <app-name>` 或 `build.bat <app-name> --run` |
| SFC 编译 | `php sfc-compiler.php apps/<name>/App.vue` |
| 运行全部测试 | `D:\swoole_compiler\php.exe tests/run_all_tests.php` |
| PHP 语法检查 | `D:\swoole_compiler\php.exe -l <file>` |
| AOT 静态检查 | `D:\swoole_compiler\php.exe framework/aot-checker.php --project apps/<name>` |
| 截图测试 | `powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1` |
| 新建应用 | 见 [dev-tasks.md](docs/agents/dev-tasks.md) §9.1 |

---

## 高风险区域 — 触发条件

| 触发条件 | 必读文档 | 原因 |
|----------|----------|------|
| 修改 `framework/Layout/` 任何文件 | [architecture.md](docs/agents/architecture.md) + [css-layout.md](docs/agents/css-layout.md) | 布局坐标全由 LayoutResolver 管理，需了解职责边界 |
| 涉及 `use native_types` 文件 | [aot-constraints.md](docs/agents/aot-constraints.md) §7.5–7.7 | C2440/C2446 类型错误高发区 |
| 修改 `framework/Paint/` | [backend.md](docs/agents/backend.md) | 6 个后端候选 + 故障降级机制 |
| 新增/修改 GDI 渲染调用 | [coding-conventions.md](docs/agents/coding-conventions.md) §13 检查清单 | clip 栈平衡 + drawText 守卫 |
| 修改滚动相关逻辑 | [events-scroll.md](docs/agents/events-scroll.md) §6 | ScrollManager 状态机 + directRender |
| 新增/修改组件/修改 .vue | [dev-tasks.md](docs/agents/dev-tasks.md) + [aot-constraints.md](docs/agents/aot-constraints.md) | AOT 闭包限制 + SFC 编译规则 |
| 修改响应式系统 | [architecture.md](docs/agents/architecture.md) Reactive 模块 | DependencyTracker + Effect 微任务调度 |
| 修改测试或新增管道测试 | [testing.md](docs/agents/testing.md) | 15 条最佳实践 + 多帧稳定性 |
| 构建失败排查 | [build.md](docs/agents/build.md) §8.3 | 常见失败表 + config.yml |

---

## 深度文档链接

> 仅在涉及对应领域时加载，避免一次性读取全部 2000+ 行。

| 文档 | 何时加载 | 内容概要 |
|------|----------|----------|
| [architecture.md](docs/agents/architecture.md) | 需要理解数据流、职责边界、关键类 API 时 | 完整数据流 + VNode/RenderNode/Application/Config 等类速查 |
| [events-scroll.md](docs/agents/events-scroll.md) | 修改事件处理或滚动逻辑时 | 点击/键盘事件链 + ScrollManager 架构 + 多容器注意事项 |
| [aot-constraints.md](docs/agents/aot-constraints.md) | 编写/修改 AOT 兼容代码时 | 禁止模式 + C2440/C2446 修复模式 + 闭包限制 + native_types 陷阱 |
| [build.md](docs/agents/build.md) | 构建、配置、部署相关操作时 | build.bat 步骤 + config.yml + vcvarsall + 常见失败 |
| [dev-tasks.md](docs/agents/dev-tasks.md) | 新建应用、添加功能、调试时 | 新建应用模板 + v-for/v-if/bind/事件 + 截图测试流程 |
| [known-issues.md](docs/agents/known-issues.md) | 遇到已知 bug 或设计债务时 | SOLID 违反记录 + 已实现功能表 + Frame 依赖型 bug |
| [coding-conventions.md](docs/agents/coding-conventions.md) | 修改框架代码前必读 | 22 条检查清单 + PHP/VNode 编码规范 |
| [testing.md](docs/agents/testing.md) | 编写/修改测试时 | 三层测试策略 + 15 条最佳实践 + 测试文件清单 |
| [css-layout.md](docs/agents/css-layout.md) | 修改布局引擎或 CSS 属性时 | 全部已支持 CSS 属性表 + Flex/Grid 算法说明 |
| [backend.md](docs/agents/backend.md) | 修改渲染后端或 Paint 层时 | 6 个后端架构 + RuntimeBackendSelector + ResilientRenderContext |

---

## 应用目录模板

```
apps/<app-name>/
├── App.vue             根组件 SFC
├── main.php            入口（AOT 常量 + main()）
├── project.yml         构建配置
├── components/         子组件（可选）
├── gen/                自动生成 PHP（sfc-compiler 产出）
└── bin/                构建输出（.exe + .dll）
```

---

## 核心原则（速记）

1. **VNode 坐标由 LayoutResolver 独占** — Application 只通过 bind 间接影响
2. **AOT 无动态** — 禁止 `$obj->$prop`、`eval()`、动态方法调用
3. **编译根组件** — 只编译 `App.vue`，子组件自动 BFS 发现
4. **clip 栈必须平衡** — push/pop 成对，帧结束为空
5. **native_types 外层加 (int)** — 数组访问/max/min 赋值给 int 变量时必须强转
6. **auto-height 排除 absolute** — CSS 2.2 §10.6.3
7. **多帧稳定性** — 布局修改后连续 2 次 resolve 结果必须一致
