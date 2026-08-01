# 事件系统与滚动系统

> **何时加载**：修改事件处理（点击/键盘）或滚动逻辑（ScrollManager/滚动条/滚轮）时加载此文档。

---

## 五、事件系统

### 5.1 点击事件处理链

```
鼠标按下 → hitTest(x, y) 反序遍历子节点
    → 检查 @click 属性
    → resolveComponent(node) 通过 groupId 查找组件
    → component->dispatchClick(handler, arg)
    → 组件内 match 分发
    → 最匹配的 handler → parent::dispatchClick 冒泡
```

### 5.2 组件自定义事件处理器

在 `.vue` 的 `<script>` 中定义方法，SFC 编译器自动生成对应的 `dispatchClick`：

```php
// App.vue <script>
public function deleteItem(string $id): void {
    unset($this->todoItems[$id]);
    $this->markDirty();  // SFC 编译器会自动注入此行
}

// 编译器生成的 dispatchClick：
public function dispatchClick(string $handler, ?string $arg = null): void {
    switch ($handler) {
        case 'deleteItem': $this->deleteItem($arg); break;
        default:
            if ($this->parent !== null) {
                $this->parent->dispatchClick($handler, $arg);
            }
    }
}
```

### 5.3 键盘事件

当前仅支持聚焦 input 元素的 @keydown / @keyup / @enter。

---

## 六、滚动系统

### 6.1 职责架构

```
滚动事件 → Application::handleMouseEvent (路由)
         → ScrollManager (状态管理 + 逻辑)
              ├─ handleScrollWheel()      滚轮
              ├─ hitTestScrollbar()       命中测试（垂直条 + 水平条）
              ├─ handleScrollbarDown()    拖拽开始
              ├─ handleScrollbarDrag()    拖拽中
              ├─ handleMouseUp()          拖拽释放
              ├─ applyScrollTop()         垂直滚动
              └─ applyScrollLeft()        水平滚动
```

> 滚动状态（target、start 坐标、start scroll 位置、isHorizontal）全部在 ScrollManager 中。
> Application 只负责将事件路由给 ScrollManager，不再直接持有滚动状态。

### 6.2 使容器可滚动

在 `.vue` 模板中：
```html
<!-- 仅垂直滚动 -->
<div style="overflow-y:auto;left:10px;top:50px;width:380px;height:400px"
     :scroll-top="scrollTop">
  <template v-for="item in items" :key="item.id">
    <div @click="deleteItem(item.id)">{{ item.text }}</div>
  </template>
</div>

<!-- 水平+纵向滚动（overflow:auto 同时支持两轴） -->
<div style="overflow:auto;left:10px;top:50px;width:390px;height:570px"
     :scroll-top="scrollTop"
     :scroll-left="scrollLeft">
  <div style="left:0;top:0;width:800px;height:36px">宽内容</div>
</div>
```

组件中：
```php
public string $scrollTop = "0";   // 垂直滚动位置
public string $scrollLeft = "0";  // 水平滚动位置（仅水平容器需要）
```

**水平滚动交互**：`Shift + 滚轮` 触发水平滚动。水平滚动条位于容器底部 12px 区域。

### 6.3 滚动交互流程

```
滚轮 → ScrollManager::handleScrollWheel (当 Shift 按下时 → 水平)
     → applyScrollTop / applyScrollLeft (persist=true)
     → setBindValue → markDirty → requestRender

轨道点击 → ScrollManager::hitTestScrollbar (返回 {scrollNode, type, isHorizontal})
       → handleScrollbarDown → applyScroll*(jumped_value, persist=true)

滑块拖拽 → hitTestScrollbar → handleScrollbarDown(type='thumb')
       → handleScrollbarDrag (高速) → applyScroll*(persist=false) → directRender
       → 鼠标释放 → applyScroll*(persist=true) → requestRender
```

### 6.4 核心机制

1. **Bind 同步**：`resolveVNodeBindings` 在每次 rebuild 时将组件 `scrollTop`/`scrollLeft` 值写入 `VNode`
2. **布局偏移**：LayoutOrchestrator 用 `childOffsetY = node.y - scrollTop` 和 `childOffsetX = node.x - scrollLeft` 定位子节点
3. **自动 clamp**：auto-stack 后若 `scrollTop > maxScroll`，LayoutOrchestrator 自动修正并重定位子节点
4. **拖拽优化**：拖拽过程中使用 `directRender`，跳过 VNode 树重建
5. **水平滚动检测**：`overflow-x:auto` / `overflow-x:scroll` 或 `overflow:auto` 继承两轴

### 6.5 多滚动容器注意事项

- 滚轮事件使用**鼠标下方最深**的滚动容器
- 滚动条拖拽时只**操作同一个**容器
- 拖拽状态由 ScrollManager 持有，拖拽过程中**不会**触发树重建
