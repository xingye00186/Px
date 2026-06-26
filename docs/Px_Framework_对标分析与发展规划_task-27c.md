# Px Framework 对标分析与迭代发展规划

## 一、四维对比分析

### 1.1 架构维度

| 维度 | Px Framework | Flutter | Electron | Qt |
|------|-------------|---------|----------|-----|
| 语言 | PHP (AOT→C++→exe) | Dart (AOT→native) | JS/TS + Node.js | C++/QML/JS |
| 渲染管线 | VNode→RenderNode→Layout→Draw | Widget→Element→RenderObject→Layer | Chromium Blink | QPainter/RHI/QSG |
| 布局引擎 | CSS Flex/Grid/Block (自研) | 自研 (RenderBox/RenderSliver) | Web CSS | QML Anchor/Layout |
| 组件模型 | Vue 3 SFC 语法 (编译 → PHP) | Widget 组合 (公开 API) | Web Components | QObject 信号槽 |
| 运行时 | Swoole Compiler AOT | Dart VM AOT | V8 + Node | C++ 原生 |
| 跨平台 | Win32 仅 | iOS/Android/Web/Mac/Win/Linux | Win/Mac/Linux | Win/Mac/Linux/Embedded/Web |
| 进程模型 | 单进程 Win32 消息循环 | 单进程 + 隔离树 | 多进程 (主+渲染) | 单进程/多线程 |

**结论**: Px 的架构设计是**正确的**（VNode→RenderNode→Layout→Draw 管线与 Flutter 的 Widget→Element→RenderObject→Layer 是同构的）。差距在"广度"而非"深度"。

### 1.2 渲染维度

| 维度 | Px Framework | Flutter | Electron | Qt |
|------|-------------|---------|----------|-----|
| 渲染后端 | GDI / Skia-CPU / DWrite | Impeller / Skia | Blink (Skia) | RHI (D3D/Metal/Vulkan) / QPainter |
| 硬件加速 | GDI 无 / Skia-CPU 无 | 全 (Impeller: GL/Metal/Vulkan) | GPU (GPU process) | 全 (RHI) |
| 文本渲染 | DWrite / Skia / GDI (三引擎降级) | 自研 (Impeller 文本) | HarfBuzz + ICU + Skia | Qt Text Engine (HarfBuzz) |
| 离屏渲染 | Skia 离屏 bitmap | Layer Tree 离屏合成 | Compositor 离屏渲染 | QSG 离屏节点 |
| 抗锯齿 | GDI 无 / Skia 有 | 全 | 全 | 全 |
| 动画 | CSS Animation + Transition | 自研 AnimationController + Tween | CSS Animation + requestAnimationFrame | QML Animation / Qt Quick |
| 图形能力 | Rect/Text/Image/Button/Gradient/Shadow | Canvas/Paint/Path/Text/Filters | Full Canvas2D + WebGL | QPainter/QQuickPaintedItem |
| 3D | 无 | 有 (Impeller) | WebGL/WebGPU | Qt Quick 3D |

**结论**: 渲染是 Px 当前最大短板 —— 纯 GDI 路径无硬件加速、无抗锯齿、无特效。Skia-CPU 后端已可用但未激活硬件加速。需要加速 Impeller/D3D11 后端开发。

### 1.3 生态与工具链

| 维度 | Px Framework | Flutter | Electron | Qt |
|------|-------------|---------|----------|-----|
| 包管理 | composer (PHP) | pub.dev | npm | online installers |
| UI 组件库 | vc-ui (60+ 组件, 基础) | Material + Cupertino (百+ 组件) | 任意 Web UI 框架 | Qt Widgets/QML (千+ 组件) |
| 状态管理 | ReactiveComponent + markDirty | Provider/Riverpod/BLoC | Redux/Zustand/etc | Qt Model/View + QML Binding |
| 热重载 | 无 | 有 (Hot Reload) | 有 (HMR) | QML 有 / C++ 无 |
| DevTools | Config 诊断 / PerfCounter | Flutter DevTools (完整套件) | Chrome DevTools | Qt Creator Debugger |
| 调试 | error_log + dumpRenderTree | DevTools / Observatory | DevTools | Qt Creator |
| 国际化 | 无 | l10n / gen_l10n | i18n libraries | tr() / QML qsTr() |
| 主题 | ThemeProvider 初版 | ThemeData 完整 | CSS variables 任意 | QML Styles |
| 无障碍 | 无 | Semantics tree | ARIA accessible | QAccessible |
| 测试 | PHPUnit + css-standards + 截图 | flutter test + integration_test | Jest/Puppeteer | Qt Test |

**结论**: 工具链是基础薄弱环节。Px 没有任何"开发者体验"层面的创新追求（无 DevTools、无热重载、无包管理），这在近中期是必须攻克的。

### 1.4 性能维度

| 维度 | Px Framework | Flutter | Electron | Qt |
|------|-------------|---------|----------|-----|
| 二进制大小 | ~1MB (AOT exe) | ~6MB (release) | ~50-200MB | ~5-50MB |
| 内存占用 | ~10-30MB | ~20-50MB | ~100-500MB | ~20-100MB |
| 启动时间 | <100ms | <200ms | 1-5s | <200ms |
| 渲染帧率 | ~30fps (GDI) / ~60fps (Skia) | 120fps | 60fps | 60fps |
| CPU 消耗 | 低 (PHP AOT + GDI) | 中-高 | 高 | 低-中 |
| GPU 利用率 | 0% (GDI) | 高 (Impeller) | 高 (GPU process) | 中-高 (RHI) |

**结论**: Px 在**二进制大小、内存占用、启动速度**上有天然优势（PHP AOT 编译），这正是超越 Electron 的核心筹码。渲染性能是短板，需要硬件加速后补齐。

---

## 二、对比评分矩阵

| 维度 (权重) | Px 当前 | 目标 (3年) | Flutter 当前 | Electron 当前 | Qt 当前 |
|------------|---------|-----------|-------------|-------------|--------|
| 架构设计 | 7/10 | 9/10 | 9/10 | 6/10 | 8/10 |
| 渲染质量 | 4/10 | 8/10 | 9/10 | 7/10 | 8/10 |
| 开发体验 | 3/10 | 7/10 | 9/10 | 8/10 | 7/10 |
| 生态成熟度 | 2/10 | 5/10 | 8/10 | 9/10 | 9/10 |
| 运行时性能 | 7/10 | 9/10 | 8/10 | 4/10 | 9/10 |
| 二进制体积 | 10/10 | 9/10 | 7/10 | 2/10 | 7/10 |
| 跨平台 | 1/10 | 6/10 | 9/10 | 7/10 | 9/10 |
| 组件丰富度 | 4/10 | 7/10 | 9/10 | 10/10 | 10/10 |
| 测试能力 | 5/10 | 8/10 | 8/10 | 7/10 | 7/10 |
| **综合** | **4.8/10** | **7.6/10** | **8.4/10** | **6.7/10** | **8.2/10** |

---

## 三、迭代开发规划

### 近中期里程碑总览

```
Month 1-3   [渲染补全]  Skia-D3D11 硬件加速 + Impeller 路径 + 抗锯齿
Month 4-6   [跨平台]    Linux 适配 + macOS 适配 + 平台抽象稳定
Month 7-9   [开发者体验]  热重载 + DevTools + 包管理 + vc-ui 标准化
Month 10-12 [生态建设]   组件库完善 + i18n + 无障碍 + 文档 + CI/CD
Month 13-18 [对标冲刺]   移动端原型 + Web 原型 + Flutter 对标验证
Month 19-24 [全面竞争]   全平台稳定发布 + 社区生态 + 第三方绑定
Month 25-36 [创新超越]   性能超越 Electron + 特色能力
```

---

### 第一阶段：渲染补全与性能攻坚 (近 3 个月)

**目标**: 渲染质量追赶 Flutter，全面超越 Electron

**Task 1: Skia-D3D11 硬件加速完成**
- [ ] 修复并激活 SkiaGaneshD3D11Backend (precidence 90) 的全流程
- [ ] 添加 D3D11 离屏表面创建与 present 走通
- [ ] 验证 D3D11 下所有 drawElement 类型正常工作
- [ ] 添加抗锯齿支持 (Skia 原生自带，只需开启)
- [x] 回退链完整性验证 (D3D11→SkiaCpu→GDI)
- 文件: `framework/Rendering/Backend/SkiaGaneshD3D11Backend.php` + `cpp/skia_core.cc`

**Task 2: Skia 文字渲染后处理增强**
- [ ] 添加文字描边 (stroke) 支持
- [ ] 添加文字发光效果模拟
- [ ] 修复 Skia 下文字模糊/锯齿问题
- [ ] 统一 DWrite/Skia/GDI 三引擎文字渲染精度
- 文件: `cpp/skia_text.cc` + `framework/Rendering/TextBackend/*`

**Task 3: 动画系统硬件加速**
- [ ] 将动画合成层移至 Skia 离屏渲染，避免逐帧重绘
- [ ] 添加 transform 动画的 GPU 合成支持
- [ ] 实现 FLIP 动画的合成器加速
- 文件: `framework/Rendering/VNodeRenderer.php` + `framework/Animation/*`

**Task 4: 渲染管线微架构优化**
- [ ] RenderNode 对象池化 (减少 GC 压力)
- [ ] VNode→RenderNode diff 算法 O(n^2) 降 O(n)
- [ ] 增量绘制不再逐帧遍历整树，使用脏区域汇聚
- 文件: `framework/Rendering/RenderTreeManager.php`

**验证**: css-test 50 个 case 通过 (当前标准) + D3D11 后端全 case 像素级对比通过

---

### 第二阶段：跨平台与架构抽象 (4-6 个月)

**目标**: 从 Win32-only 变为三平台支持

**Task 5: Linux 平台适配**
- [ ] PlatformInterface 扩展为真正跨平台抽象
- [ ] LinuxPlatform 实现 (X11/Wayland 消息循环)
- [ ] Linux 下 Skia 渲染后端 (XCB/Wayland surface)
- [ ] Linux 下字体管理 (FontConfig 集成)
- 文件: `framework/Platform/LinuxPlatform.php` (新)

**Task 6: macOS 平台适配**
- [ ] macOSPlatform 实现 (RunLoop + NSApplication)
- [ ] macOS 下 Skia Metal 后端
- [ ] macOS 下 CoreText 集成
- 文件: `framework/Platform/MacOSPlatform.php` (新)

**Task 7: Platform 接口接口升级**
- [ ] PlatformInterface 新增: 窗口管理、输入法、拖放、剪贴板
- [ ] 统一事件序列化 (跨平台事件 → PlatformEvent)
- [ ] 窗口属性一致性 (title/w/min/pos/fullscreen)
- 文件: `framework/Platform/Platform.php` + `framework/Platform/PlatformFactory.php`

**Task 8: 构建系统跨平台化**
- [ ] Windows MSVC 构建保持 + Linux GCC/Clang 支持
- [ ] macOS Xcode toolchain 支持
- [ ] CMake 构建系统引入 (统一三平台)
- 文件: `CMakeLists.txt` (新) + `build.bat` 重构

**Task 9: SFC 编译器跨平台兼容**
- [ ] 消除 sfc-compiler 中的 Windows 路径依赖
- [ ] PHP 生成代码不绑定 Win32 API
- [ ] 平台适配层统一，框架代码无平台条件编译
- 文件: `framework/compiler/sfc-compiler.php`

**验证**: 三平台各自能运行 calculator-ng 演示应用 + 截图像素级对比一致

---

### 第三阶段：开发者体验与工具链 (7-9 个月)

**目标**: 提供基本完整的 DX，对标 Flutter DevTools 体验

**Task 10: 热重载 / 热重启**
- [ ] sfc-compiler watch mode (文件变更自动重新编译)
- [ ] 运行时 VNode 树更新 + 保持状态
- [ ] PHP 侧组件方法级替换 (AOT 限制：需 full rebuild)
- [ ] 非 AOT 模式下的增量编译热重载
- 文件: `framework/compiler/sfc-compiler.php` + `tools/hotreload.php`

**Task 11: Px DevTools**
- [ ] VNode 树可视化 (基于渲染管线快照)
- [ ] RenderNode 布局检查器 (点击元素→展示布局属性)
- [ ] 性能面板 (帧率/重绘区域/布局耗时)
- [ ] 后端选择状态可视化
- 文件: `framework/DevTools/*` (扩展)

**Task 12: 诊断与日志系统升级**
- [ ] 结构化日志 (取代 error_log 散乱调用)
- [ ] 渲染事件追踪 (每个 VNode 变更可追溯)
- [ ] 性能计数器持久化
- 文件: `framework/Core/Diagnostics.php` (新) + `framework/Core/PerfCounter.php` 升级

**Task 13: 包管理与组件分发**
- [ ] Px 包格式定义 (打包 .vue + 资源的目录结构)
- [ ] 包注册器 (文件系统 / 远程注册表)
- [ ] 第三方组件安装 CLI 工具
- 文件: `tools/package-manager/` (新目录)

**Task 14: vc-ui 组件库标准化**
- [ ] 组件接口审核：所有 60+ 组件统一 Props/Events/Slots 约定
- [ ] 组件 API 文档自动生成
- [ ] 新增关键缺失组件：Dialog, Toast, Menu, DatePicker, TreeSelect, Stepper
- [ ] 主题一致性：所有组件通过 ThemeProvider 获取颜色/字体
- 文件: `library/vc-ui/*.vue` (全部)

**验证**: 能在一个小时内从零搭建一个新 Px 应用 + 使用 3 个第三方组件包 + 通过 DevTools 排查一个布局问题

---

### 第四阶段：生态建设与质量提升 (10-12 个月)

**目标**: 从"能用"到"好用"，积累基础生态资产

**Task 15: 国际化系统**
- [ ] ICU 消息格式集成
- [ ] 运行时语言切换
- [ ] 组件内 i18n 透传
- 文件: `framework/Core/I18n.php` (新) + 各组件增加翻译挂载点

**Task 16: 无障碍系统**
- [ ] RenderNode 树增加 Semantics 属性
- [ ] Win32 下 MSAA/UIA 集成
- [ ] Linux 下 ATK/AT-SPI 集成
- 文件: `framework/Rendering/SemanticsNode.php` (新)

**Task 17: 测试基础设施完善**
- [ ] 集成测试覆盖率从 5% 提升至 60%
- [ ] 自动化截图对比 (跨平台基线管理)
- [ ] 性能基准测试套件 (持续跟踪帧率/内存/启动时间)
- 文件: `tests/` 全目录扩展 + `tools/PxTest/` 完成

**Task 18: 官方文档与示例**
- [ ] 入门教程 (Calculator → Todo App → 完整应用)
- [ ] API 参考 (框架 + vc-ui)
- [ ] 架构决策记录 (ADR)
- 文件: `docs/tutorials/` (新)

**Task 19: CI/CD 流水线**
- [ ] GitHub Actions 三平台构建
- [ ] 自动回归测试 + 截图基线对比
- [ ] Release 自动打包 + release note 生成
- 文件: `.github/workflows/*` 扩展

**验证**: 新用户首次接触 Px 能在 30 分钟内完成环境搭建并运行一个完整应用

---

### 第五阶段：对标 Flutter 冲刺 (13-18 个月)

**目标**: 核心能力达到 Flutter 基线水平

**Task 20: 移动端原型**
- [ ] Android 平台探索 (Skia OpenGL ES + Input/Event)
- [ ] iOS 平台探索 (Skia Metal + Touch Event)
- [ ] 移动端框架层适配
- 文件: `framework/Platform/AndroidPlatform.php` (新) + `framework/Platform/iOSPlatform.php` (新)

**Task 21: Web 后端原型**
- [ ] 评估 Web 后端可行性 (WASM 编译路径)
- [ ] 渲染后端：HTML Canvas 2D 或 Skia WASM
- [ ] 最小可行性 Web 应用
- 文件: `framework/Rendering/Backend/WebCanvasBackend.php` (新)

**Task 22: 复杂动画与交互对标**
- [ ] Hero 动画 (跨页面元素过渡)
- [ ] NestedScrollView (嵌套滚动)
- [ ] GestureDetector 手势系统
- [ ] Physics simulation (弹性/摩擦/衰减)
- 文件: `framework/Animation/PhysicsSimulation.php` (新) + `framework/Core/GestureDetector.php`

**Task 23: 状态管理生态**
- [ ] 响应式属性系统升级 (类似 Flutter ValueNotifier)
- [ ] Provider/Riverpod 模式适配
- [ ] 不可变状态管理 (类似 Bloc)
- 文件: `framework/StateManagement/` (新目录)

**Task 24: Flutter 互操作性**
- [ ] Px 组件可嵌入 Flutter (通过纹理共享)
- [ ] Flutter 组件可嵌入 Px (通过 FFI)
- [ ] 双向通信协议
- 文件: `framework/interop/FlutterBridge.php` (新)

**验证**: Flutter 的核心 benchmark (小米 6 上 120fps) 转换到 Px 目标 (同硬件 60fps, 中等复杂度 UI)

---

### 第六阶段：全面竞争与生态爆发 (19-24 个月)

**目标**: 成为桌面 GUI 开发的可行选择

**Task 25: 全平台稳定发布**
- [ ] Windows / Linux / macOS 三个平台 v1.0 Release
- [ ] ABI 稳定性承诺
- [ ] Semantic Versioning 发布
- 文件: Changelog + 里程碑发布

**Task 26: 第三方生态系统**
- [ ] 开放包注册表 (类似 pub.dev)
- [ ] 第三方组件资质认证
- [ ] 社区插件框架 (Spigot 风格)
- 文件: `tools/registry/` (新)

**Task 27: 高级渲染特性**
- [ ] 着色器支持 (自定义 fragment shader)
- [ ] 粒子系统
- [ ] 后处理特效 (模糊/辉光/颜色矩阵)
- 文件: `cpp/skia_shaders/` (新)

**Task 28: 高性能计算集成**
- [ ] C++ 原生计算绑定 (SIMD/GPU compute)
- [ ] 数据可视化管线 (图表/3D 场景)
- [ ] 视频/相机集成
- 文件: `cpp/accelerate/` (新)

**验证**: 能够构建与 Flutter Gallery 同等复杂度的示例应用

---

### 第七阶段：创新超越 (25-36 个月)

**目标**: 形成差异化竞争力，在特定维度和场景超越 Flutter

**Task 29: 超越 Electron 的终局之战**
- [ ] 同构 SSR (服务端渲染 Px 应用到静态 HTML)
- [ ] AOT 编译的 React Native 替代方案
- [ ] 嵌入式场景 (Raspberry Pi / IoT)
- [ ] Px → Web 转译器 (AOT 编译为 WASM)
- 文件: `tools/ssr/` + `tools/px2web/` (新)

**Task 30: Px 独特优势巩固**
- [ ] PHP 生态复用：直接在 .vue 中使用 PHP 库
- [ ] 零配置默认：一个 .vue 文件即可运行
- [ ] 最小二进制持续优化 (< 500KB 空应用)
- [ ] 编译时极致优化 (Dead Code Elimination, Tree Shaking)

**Task 31: AI 原生集成**
- [ ] AI 辅助 UI 生成 (自然语言 → .vue)
- [ ] 运行时 AI 布局优化 (自适应 UI)
- [ ] 无障碍智能降级 (AI 生成替代文本/交互)

**验证**: Px 在桌面场景的 NPS (净推荐值) 超过 Electron；在小型/中型的桌面应用场景市场份额站稳脚跟

---

## 四、核心竞争策略

### 4.1 超越 Electron 的策略

Electron 的核心**弱点**正是 Px 的**武器**：

| Electron 弱点 | Px 优势 | 具体行动 |
|--------------|---------|---------|
| 二进制 > 50MB | < 1MB | 保持 AOT 编译路径 + Tree Shaking |
| 内存 > 200MB | < 30MB | 共享框架 DLL + 惰性组件加载 |
| 启动 > 3s | < 100ms | 维持 AOT 编译 + 延迟初始化 |
| CPU 高消耗 | 低消耗 | PHP AOT 避免 V8 预热成本 |
| 打包复杂 | 单文件 exe | 保持 build.bat 单命令构建 |

**关键策略**: 不是在 Electron 的赛道（Web 兼容）上竞争，而是在其**做不到**的赛道上竞争——极致的轻量级、极速启动、原生集成。

### 4.2 对标 Flutter 的策略

Flutter 的**弱点**（Px 可差异化方向）：
- Flutter 的 Dart 生态与现有 Web 生态割裂，Px 可复用 PHP 生态
- Flutter 在桌面端尚不成熟 (Windows/Mac 支持 2022 年才 stable)
- Flutter 应用体积 > 6MB，Px 可做到 < 1MB
- Flutter 学习曲线陡峭 (Dart + Widget 组合)，Px 的 Vue 3 SFC 语法学习成本低

**关键策略**: 不追求在所有维度与 Flutter 正面竞争。聚焦 **Mid-Range Desktop Applications**——Flutter 不太适合的利基市场（小型工具、内部系统、工业控制）。

### 4.3 核心差异化定位

```
Px Framework = PHP 的 Vue 3 开发体验 + Electron 的桌面能力 + Flutter 的渲染精度
             - Chromium 的重量
             - Dart 的学习成本
             - Web 的限制
```

---

## 五、风险评估

| 风险 | 等级 | 缓解措施 |
|------|------|---------|
| Swoole Compiler 商业授权变更 | 高 | 保持开源 GPL/LGPL 兼容；储备 LLVM 编译路径 |
| PHP 生态对桌面开发动力不足 | 中 | 将开发者体验作为核心卖点；建设中文社区 |
| Skia D3D11 后端复杂度超预期 | 中 | 保持 GDI 降级路径；分阶段交付 (先 CPU 再 GPU) |
| 跨平台后维护成本激增 | 中 | 核心逻辑 PHP 统一，平台差异 C++ 抽象；CI 三平台自动测试 |
| AOT 编译限制了 PHP 动态特性 | 高 | 与 Swoole Compiler 团队协作；开放 AOT 兼容性白名单 |
| Flutter 桌面版快速成熟挤压空间 | 低 | 聚焦 Px 独特价值 (PHP 生态 + 极小体积 + Vue 语法) |
