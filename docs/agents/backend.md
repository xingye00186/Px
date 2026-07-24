# 渲染后端系统（Backend）

> **何时加载**：修改渲染后端、Paint 层、或需要理解后端选择/故障降级机制时加载此文档。

---

## 架构概览

```
Application::initRenderer()
    │
    ├─ Stage 1: Platform::init() 创建窗口 + 默认 RenderContext
    ├─ Stage 2: RuntimeBackendSelector::select()
    │   ├─ collectCandidates() ← BackendRegistry（6 个候选，按优先级排序）
    │   ├─ 逐一 probe() 检测运行时可用性
    │   ├─ 第一个 probe+initialize 成功的 → 选定
    │   └─ 全部失败 → 抛 \RuntimeException
    └─ Stage 3: 包裹 ResilientRenderContext（运行时自动降级代理）
        └─ VNodeRenderer 通过此代理调用绘制原语
```

## 后端列表

| 后端 | 名称 | 优先级 | 阶段 | 说明 |
|------|------|--------|------|------|
| Skia-Graphite-Dawn | `skia-dawn` | 100 | 五 | GPU (Dawn)，待实现 |
| Skia-Ganesh-D3D11 | `skia-d3d11` | 90 | 四 | GPU (D3D11)，待实现 |
| Skia-Ganesh-WGL | `skia-wgl` | 80 | 四 | GPU (OpenGL)，待实现 |
| Skia-CPU | `skia-cpu` | 60 | 三 | CPU Skia（当前可用） |
| GDI-Direct2D | `gdi-d2d` | 50 | 四 | GDI D2D，待实现 |
| GDI-Legacy | `gdi-legacy` | 10 | 一二 | 原生 GDI（永远可用） |

> **GDI-Legacy 永远可用**：Windows + user32/gdi32 存在即可。

## BackendRegistry（framework/Paint/Backend/BackendRegistry.php）

```php
BackendRegistry::CANDIDATES            // 全部候选（按优先级降序硬编码）
BackendRegistry::getCandidatesSorted() // 返回候选列表
BackendRegistry::getForcedBackend()    // 读取环境变量 PX_RENDERER
BackendRegistry::isVerbose()           // 读取 PX_RENDERER_VERBOSE
```

**环境变量覆盖**：

| 变量 | 说明 | 示例 |
|------|------|------|
| `PX_RENDERER` | 强制指定后端 | `skia-cpu`、`gdi-legacy` |
| `PX_RENDERER_VERBOSE` | 打印探测详情 | `1` |

## IRenderBackend 接口

```php
interface IRenderBackend {
    public function getName(): string;
    public static function getPriority(): int;
    public function probe(): BackendCapability;      // 无副作用探测
    public function initialize(int $hwnd, int $w, int $h);
    public function getContext(): RenderContext;
    public function shutdown(): void;
}
```

**生命周期**：`probe() → [available] → initialize → getContext → [use] → shutdown`

## RuntimeBackendSelector

```php
select(hwnd, w, h)      // 启动选择
selectNext(hwnd, w, h)  // 降级
markFailed(backend)     // 标记失败
getCurrent()            // 获取当前
```

**探测流程**：
```
foreach (candidates as cls) {
    backend = new cls()
    if (!backend->probe()->available) continue
    try { backend->initialize(hwnd, w, h); return backend }
    catch (BackendInitException) { continue }
}
throw \RuntimeException('No render backend available')
```

## ResilientRenderContext（故障降级代理）

- 继承 RenderContext，对 VNodeRenderer 透明
- delegate 抛 `RenderBackendFailedException` 时计数
- **同一后端连续失败 3 次** → 触发降级
- 降级后用新 delegate 重试当前调用

## 各阶段状态

| 阶段 | 后端 | 状态 |
|------|------|------|
| 阶段一（POC） | GDI-Legacy | ✅ 已通过 |
| 阶段二（GDI 兼容层） | GDI-Legacy | ✅ 已通过 |
| 阶段三（真 Skia） | Skia-CPU | ✅ spike 通过 |
| 阶段四五（GPU） | D3D11/WGL/Dawn/D2D | 待实现 |

## 已知问题

1. `g_skHwnd/g_skHdc/g_skSurface` 全局变量冲突 → Phase 6 `php::Box` 重构
2. 文本回退限制（Skia-CPU）→ 阶段三 DirectWrite 集成
3. MSVC 17.10+ STL stubs → `_MSC_VER` 条件编译
4. `/MT` 静态 CRT → Skia 预期
5. GPU 后端未启用 → Phase 4-5

## 关键文件

| 文件 | 说明 |
|------|------|
| `framework/Paint/Backend/IRenderBackend.php` | 后端接口 |
| `framework/Paint/Backend/BackendRegistry.php` | 注册表 |
| `framework/Paint/Backend/RuntimeBackendSelector.php` | 选择器 |
| `framework/Paint/Backend/ResilientRenderContext.php` | 故障降级 |
| `framework/Paint/Backend/Gdi*.php` | GDI 后端 |
| `framework/Paint/Backend/Skia*.php` | Skia 后端 |
| `cpp/skia_render.cc` | C++ 原生层 |
| `stub/skia.stub.php` | stub 声明 |
| `framework/Paint/RenderContext.php` | 抽象基类 |
| `framework/Paint/GdiRenderContext.php` | GDI 实现 |

---

## 十六、test_pipeline 改进

### 已修复问题（2026-06-24）

| 问题 | 修复 |
|------|------|
| PHP CLI 拒绝访问 | 入口 taskkill php-cgi.exe |
| 构建缓存不感知变更 | computeHash 递归扫描 framework |
| Edge 挂起 | `--virtual-time-budget=30000` |
| build.bat 拒绝访问 | 头部 taskkill cl.exe/link.exe |
| stale ref 数据 | `_html_hash` 校验跳过 |
| 增量编译一致性 | `Px_clear_compilation_cache: true` |

### 已知约束

- PowerShell 管道中断：Sandbox 中 `| Select-String` 偶发进入多行输入模式。应使用文件重定向替代管道。
