# 渲染管线优化路线图（Paint + Composite）

## 背景

当前 Px 渲染管线：

```
Layout  →  VNodeRenderer::collectElements  →  drawElement (逐元素绘制)
              (遍历 RenderNode 树，按 layer 分组)        (直接调用 RenderContext)
```

与浏览器 Pipeline（Layout → Paint → Composite）相比，Px 缺少：
- **Paint 缓存层**：没有绘制指令录制/回放机制，每次 render 全量遍历生成元素描述
- **Compositing 层**：`layer` 只是 z-order 编号，不是独立离屏 surface，无法独立合成
- **脏区域追踪**：全屏清除 + 全量重绘，没有增量脏区域

以下优化按优先级排列。

---

## 一、滚动容器离屏位图缓存（最高优先级）

### 现状

滚动触发 `directRender()` → 完整遍历整棵树 → 所有 `drawElement` 重新调用。
每次滚动都是 O(n) 复杂度，对长列表场景性能影响显著。

### 方案

对 `overflow: auto/scroll` 容器，将内容预渲染到 Skia 离屏 Surface，滚动时只做位图 blit。

```
首次渲染:
  创建 SkSurface(containerW, contentH)
  子节点绘制到离屏 Surface
  提取 SkImage 缓存

滚动时:
  drawImage(缓存的 SkImage, dx, dy)   ← O(1)，只需一次 drawElement

内容变化时:
  markScrollCacheDirty() → 下次 render 重建缓存
```

### 实现要点

- `VNodeRenderer` 管理 `$scrollSurfaceCache` 映射表（key = RenderNode 对象 ID）
- 在 `collectElements` 处理滚动容器时判断缓存是否有效：
  - 容器内容无 dirty && 缓存存在 → 绘制缓存图，不遍历子节点
  - 容器内容 dirty → 递归遍历子节点到离屏 Surface → 更新缓存
- 缓存淘汰：容器尺寸变化、子节点布局变化、滚动条显隐变化时失效
- 需要 Skia 扩展：`sk_create_surface(w, h)`, `sk_surface_get_image()`, `sk_draw_image(canvas, image, x, y)`
- GDI 后端不支持离屏缓存，此优化仅在 Skia 后端生效

### 收益

- 滚动场景 O(n) → O(1)，对长列表/Bilibili 这类应用提升显著
- 实现集中在 VNodeRenderer + SkiaRenderContext，不涉及 LayoutResolver
- 滚动容器概念已经存在（`RenderNode::$isScrollContainer`），基础设施就绪

---

## 二、合成层（Compositing Layer）（高优先级）

### 现状

`opacity: 0.5` 的容器，所有子节点全量绘制到主画布后整体混 alpha。
`layer` 只是 z-order 排序号，没有独立离屏 surface。

### 方案

对特定条件触发合成层（Compositing Layer），子节点先渲染到独立 SkSurface，再合成到主画布。

```
触发条件（参考浏览器 will-change / 隐式合成）：
  - opacity != 1.0
  - transform 存在（rotate, scale, translate）
  - border-radius clip（子节点超出圆角区域需要裁切）
  - overflow: hidden（同上）

实现：
  collectElements 遇到合成层节点时：
    创建临时 SkSurface(subtreeW, subtreeH)
    递归收集子节点 → 绘制到临时 Surface
    将 SkImage 作为 'type' => 'composited' 元素插入当前层

  opacity 合成：
    drawImage(cachedImage, alpha=opacity)

  transform 合成：
    save → translate/rotate/scale → drawImage → restore
    变换不触发重绘，只改变合成参数
```

### 收益

- `opacity` 动画：只需重新合成，子树无需重绘
- `transform` 动画：纯 GPU 操作，零 PHP 遍历
- `border-radius` clip：利用 Skia clipRRect，准确高效

### 注意事项

- 合成层会增加显存/内存消耗（每层一个 offscreen surface）
- 需要平衡收益与成本：小区域/短动画不适合合成层
- 参考浏览器合成层触发策略，避免过度合成

---

## 三、绘制指令缓存（SkPicture / Display List）（中优先级）

### 现状

每次 `render()` 全新走 `collectElements`，生成元素描述数组，然后逐元素 `drawElement`。
即使子树没有任何变化，PHP 层仍要完整遍历。

### 方案

利用 Skia 的 SkPicture 录制/回放机制，缓存静态子树的绘制指令。

```
当前流程（每次 render）:
  collectElements(遍历 node) → element[] → drawElement 逐个调用

优化后:
  子树 no dirty:
    playback = cache[subtreeKey]  ← SkPicture
    canvas->drawPicture(playback)  ← 绕过 PHP 元素数组遍历

  子树 dirty:
    canvas->beginRecording() → 正常 drawElement → endRecording()
    cache[subtreeKey] = SkPicture
```

### 实现要点

- 缓存粒度为 `directRender()` 中不变的子树
- 需要 `RenderContext` 接口扩展：
  - `beginRecording(?int $w, ?int $h): SkPictureRecorder`
  - `endRecording(): SkPicture`
  - `drawPicture(SkPicture $pic)`
- SkPicture 内存消耗与绘制指令数量成正比，只缓存较大且稳定的子树

---

## 四、脏区域追踪（Dirty Rect）（低优先级）

### 现状

`beginFrame()` 内部全屏清除 → 全量重绘。Skia backend 每帧清除 `beginFrame(rc=1600x800)`。

### 方案

追踪脏矩形，只清除并重绘变化的区域。

```
节点标记 dirty → 计算节点屏幕区域 → 合并到 accumulatedDirtyRect
beginFrame(dirtyRect) → canvas->clipRect(dirtyRect) + clear(dirtyRect)
endFrame → 只 flush dirty 区域
```

### 局限性

- GDI 后端不支持局部脏区域
- Skia 后端支持 `clipRect`，但窗口 BitBlt 到屏幕仍是全屏刷新
- 收益远低于滚动缓存和合成层

---

## 五、后端能力分级

| 优化项 | Skia CPU | Skia GPU | GDI |
|--------|----------|----------|-----|
| 滚动缓存 | ✅ 可实现 | ✅ 可实现 | ❌ 不支持 |
| 合成层 | ✅ SkSurface | ✅ GPU texture | ❌ 不支持 |
| SkPicture | ✅ 原生支持 | ✅ 原生支持 | ❌ 不支持 |
| 脏区域 | ✅ clipRect | ✅ scissor | ❌ 不支持 |

GDI 后端仅支持最基本的 drawElement，所有进阶优化依赖 Skia 后端。

---

## 实施路线建议

```
Phase 1（当前）:  布局-渲染分离原则 → 删除渲染阶段布局调整（已完成）
Phase 2（下一步）: 滚动容器离屏缓存 ← 收益最高，建议优先
Phase 3:            合成层（opacity/transform）
Phase 4:            绘制指令缓存（SkPicture）
Phase 5:            脏区域追踪（可选）
```

每阶段的实施流程：
1. C++ 层新增 Skia 绑定函数（sk_surface/sk_picture 等）
2. PHP 层 RenderContext / SkiaRenderContext 扩展接口
3. VNodeRenderer 增加缓存逻辑
4. 测试覆盖（布局 dump 一致性 + 性能基准对比）
