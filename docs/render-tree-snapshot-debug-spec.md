# 渲染快照与调试信息输出系统规格

## Context

当前排查布局问题时只能靠阅读源码 + 脑内模拟 LayoutResolver 的运算，无法直接看到渲染后的 VNode 树坐标、CSS 计算值、和触发状态变更的事件序列。需要一个可开关的调试机制，dump 渲染树快照 + 最近交互事件，帮助快速定位布局/样式问题。

## 设计方案

### 1. px_debug.yml 配置（每个项目独立）

**文件位置**: `apps/<项目名>/px_debug.yml`

**不放在框架级 config.yml 中**，每个应用独立控制，互不影响。

```yaml
# 应用级 Debug 配置 (与 main.php 同级)
snapshot_enabled: true       # 是否启用渲染快照
snapshot_max_events: 5       # 快照中包含的最近事件数
snapshot_detail: normal      # minimal | normal | verbose（后续扩展）
```

**输出目录**: 自动写入 `{APP_DIR}/debug/` 目录（如 `apps/bilibili/debug/`），无需在配置中指定。
`snapshot_file` 配置项移除，改由 Config 自动拼接 `{APP_DIR}/debug/_snapshot.log`。

### 2. 运行时配置读取

**新文件**: `framework/Core/Config.php`

```php
class Config
{
    private static ?array $cache = null;
    private static string $configPath = '';

    /** 初始化配置路径（由 Application 在 run() 前设置） */
    public static function init(string $appDir): void

    /** 读取配置，如 Config::get('snapshot_enabled', false) */
    public static function get(string $key, mixed $default = null): mixed
}
```

**配置查找路径**:
- 在 `Application::mount()` 时，由 main.php 传入应用目录
- 策略：在 main.php 中定义 `define('APP_DIR', __DIR__)`，Application 初始化时传给 Config
- Config 读取 `{APP_DIR}/px_debug.yml`，文件不存在则所有 get() 返回默认值

实现一个极简 YAML 解析器，只处理本框架需要的一级 scalar。

AOT 兼容性要求：
- 不使用闭包/递归
- 只处理固定键名
- 返回标量值

### 3. 事件环形缓冲区

**修改**: `framework/Core/Application.php`

```php
// 新增字段
private array $eventRingBuffer = [];      // 事件环形缓冲区
private int $eventBufferSize = 5;         // 缓冲区大小（从 config.yml 读取）
private int $eventBufferIndex = 0;        // 当前写入位置

// 在 handleMouseEvent / handleKeyboardEvent 中记录
private function recordEvent(string $summary): void {
    $this->eventRingBuffer[$this->eventBufferIndex % $this->eventBufferSize]
        = ['seq' => $this->eventBufferIndex, 'time' => time(), 'desc' => $summary];
    $this->eventBufferIndex++;
}
```

记录的事件格式：
- `mouse:down x=320 y=180 button=0@handlerName` — 含 @click 绑定的 handler 名
- `mouse:up x=321 y=182`
- `mouse:wheel x=320 y=180 delta=120 scrolled=CategoryTabs_3` — 触发滚动的容器 groupId
- `mouse:move x=320 y=180 hover=CategoryTabs_3` — 仅记录 hover 变化的 move 事件
- `key:char code=13@enterHandler`

### 4. RenderNode 树快照生成

**修改**: `framework/Rendering/RenderTreeManager.php`

新增方法 `dumpRenderTree(int $frame, array $recentEvents): string`，递归遍历 RenderNode 树生成文本快照：

```
===== RenderNode Snapshot [frame=42] =====
Recent events: (3 events)
  [1] mouse:down x=320 y=180 @换一换
  [2] mouse:up x=321 y=182
  [3] mouse:wheel x=320 y=180 delta=120

Tree:
#root [0,19,1440,881] layer=0 groupId=app
├─ MainContent#root [0,19,1440,881] layer=0 groupId=app
│  ├─ MainContent_2 [0,0,240,881] display=flex flexGrow=0 flexShrink=0 w=240 pos=absolute layer=0 groupId=MainContent_1
│  │  └─ VideoCard_3 [10,10,220,160] display=flex layer=1 groupId=MainContent_3
│  │     ├─ Cover [10,10,220,100] bg=#E8A87C layer=2
│  │     └─ Title [10,110,216,40] textOverflow=ellipsis layer=2
│  └─ MainContent_3 [240,0,1200,881] display=flex flexGrow=1 layer=0 groupId=MainContent_1
└─ SidebarWidget [1200,0,240,881] display=flex layer=0 groupId=SidebarWidget_1
```

**信息密度分级**（通过 `snapshot_detail` 控制）：
- **minimal**: type, groupId, x/y/w/h, layer（定位问题快速定位）
- **normal**（默认）: 在 minimal 基础上加 `display`, `flexGrow`, `position`, `scrollTop`/`scrollLeft`, `key`
- **verbose**: 在 normal 基础上加完整 style 数组、`layoutDirty` 标记、`contentHeight`/`contentWidth`、`positioningAncestor` 状态

### 5. 集成到渲染流水线

**修改**: `framework/Core/Application.php`

在 `render()` 方法中，LayoutResolver 执行完毕后、VNodeRenderer 渲染前，插入快照输出：

```php
private function render(): void
{
    // ... 原有 VNode 树重建 + updateFromVNode ...

    $this->layoutResolver->resolve($rootRenderNode);

    // ── 调试快照 ──
    if (Config::get('snapshot_enabled', false)) {
        $snapshot = $this->renderTreeManager->dumpRenderTree(
            $this->frameCounter,
            $this->eventRingBuffer
        );
        $this->outputSnapshot($snapshot);
    }

    // 原有渲染
    $this->renderer->render($rootRenderNode);
}

private function outputSnapshot(string $snapshot): void
{
    $file = Config::getOutputPath() . '/_snapshot.log';
    @mkdir(Config::getAppDir() . '/debug', 0777, true);
    file_put_contents($file, $snapshot . "\n\n", FILE_APPEND);

    // 同时输出到 stdout（AOT 下写入控制台）
    echo $snapshot . "\n";
}
```

注意：`echo` 在 AOT 模式下输出到控制台窗口（Debug 模式的控制台），或者通过 `OutputDebugString`
输出到 Windows debug log。需要确认 AOT 下 `echo` 的行为。

### 6. 帧计数器

修改 `Application` 增加 `private int $frameCounter = 0;`，在每次 `render()` 递增。

### 7. 额外调试辅助

除了树快照 + 事件，再提供以下辅助输出（在 verbose 模式下）：

- **组件注册表状态**: 当前 active 的组件实例数量、groupId 列表
- **定时器状态**: Scheduler 当前 pending 微任务/宏任务数
- **布局耗时**: PerfCounter 中 LayoutResolver 耗时
- **滚动状态**: 全局 ScrollManager 的拖拽状态

这些信息都在 `Application`/`Scheduler`/`ScrollManager` 中已有，只需在 dump 前收集。

---

### 限制与注意事项

1. **AOT 兼容性**:
   - `time()` 在 AOT 下可用
   - `file_put_contents` 可用
   - `echo` 在 AOT 下写 stdout（需要确认 Windows GUI 程序是否有 console）
   - 如果 GUI 无 console，可改用 `OutputDebugStringA` 写入调试器输出
   - 或者直接写到文件：`file_put_contents('_render_snapshot.log', ..., FILE_APPEND)`

2. **性能影响**: 快照生成在启用时才执行，默认关闭，不影响正常渲染性能

3. **快照循环输出**: 每次渲染都输出会刷屏，可考虑仅在点击事件后第一次渲染时输出
   （通过 `frameDirty` 标记控制），或者加 `snapshot_interval` 配置项。

4. **文件安全性**: 日志文件写入框架根目录，注意 `.gitignore` 添加 `_snapshot.log`

---

## 实施步骤

### Step 1: 新建 `framework/Core/Config.php`
- 实现 `Config` 类，从 `{app_dir}/px_debug.yml` 读取配置
- 实现极简 YAML 一级解析（仅处理 `key: value` 和 `key: true/false/number/string`）
- 提供 `init(string $appDir)` 和 `get(string $key, mixed $default)`
- AOT 兼容：静态缓存，无闭包

### Step 2: 修改 `apps/bilibili/main.php`
- 增加 `define('APP_DIR', __DIR__)` 常量，供 Config 定位 px_debug.yml

### Step 3: 修改 `framework/Core/Application.php`
- 接收 `$appDir` 参数（通过 mount 或 run 传入）
- 在 `mount()` 或 `run()` 中调用 `Config::init($appDir)`
- 新增 `eventRingBuffer`, `eventBufferSize`, `eventBufferIndex`, `frameCounter`
- 新增 `recordEvent()` 方法
- 在 `handleMouseEvent` 各分支调用 `recordEvent()`
- 在 `handleKeyboardEvent` 各分支调用 `recordEvent()`
- 新增 `outputSnapshot()` 方法
- 在 `render()` 中 LayoutResolver 之后插入快照逻辑

### Step 4: 新增 `framework/Rendering/RenderTreeManager::dumpRenderTree()`
- 递归遍历 RenderNode 树
- 按信息密度分级输出
- 使用树形字符（`├─`, `│`, `└─`）表示层次

### Step 5: 创建 `apps/bilibili/px_debug.yml`
- 独立调试配置文件，不修改 config.yml

### Step 6: 构建验证

---

## 验证标准

| 检查项 | 通过标准 |
|--------|---------|
| 配置读取 | `Config::get('snapshot_enabled')` 返回 `true` |
| 事件记录 | 在控制台/文件中看到最近鼠标/键盘事件 |
| 树输出 | 能看到完整的树层次结构 + 坐标信息 |
| 开关有效 | `snapshot_enabled: false` 时无任何输出 |
| 性能无退化 | `snapshot_enabled: false` 时渲染帧率未下降 |
| AOT 编译通过 | `build.bat bilibili` 成功 |
