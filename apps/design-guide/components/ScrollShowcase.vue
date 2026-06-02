<template>
  <div style="left:0px;top:0px;width:1920px;display:flex;flex-direction:column;gap:0px">

    <!-- Vertical Scroll -->
    <div style="margin:16px 16px 0 16px;height:240px;width:1888px">
      <div style="display:flex;flex-direction:row;justify-content:space-between;align-items:center">
        <span style="font-size:13px;font-weight:600;color:#FFFFFF">垂直滚动 (overflow-y:auto)</span>
        <span style="font-size:10px;color:#6366F1">滚动位置: {{ scrollTop }}</span>
      </div>
      <div style="margin-top:8px;height:200px;background:#1F1F23;border-radius:8px;overflow-y:auto" :scroll-top="scrollTop">
        <template v-for="item in verticalItems" :key="item">
          <div style="padding:8px 12px;border-bottom:1px solid #2A2A2E;display:flex;flex-direction:row;align-items:center">
            <div style="width:6px;height:6px;border-radius:3px;background:#6366F1;margin-right:8px"></div>
            <span style="font-size:12px;color:#D1D5DB">Item {{ item }}</span>
          </div>
        </template>
      </div>
    </div>

    <!-- Add Item to List -->
    <div style="margin:12px 16px 0 16px;height:36px;width:1888px">
      <div style="display:flex;flex-direction:row;gap:6px;align-items:center">
        <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="addScrollItem" click-arg="">添加项目</button>
        <button style="padding:6px 14px;background:#EF4444;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="clearScrollItems" click-arg="">清空</button>
        <span style="font-size:10px;color:#6B7280">共 {{ verticalItemCount }} 项</span>
      </div>
    </div>

    <!-- Horizontal Scroll -->
    <div style="margin:16px 16px 0 16px;height:110px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">水平滚动 (overflow-x:auto)</div>
      <div style="margin-top:8px;height:80px;background:#1F1F23;border-radius:8px;overflow-x:auto">
        <div style="width:800px;height:80px;display:flex;flex-direction:row;gap:8px;padding:12px">
          <div style="flex:1;background:#2A2A3E;border-radius:6px;display:flex;align-items:center;justify-content:center;min-width:80px">
            <span style="font-size:11px;color:#D1D5DB">Cell 1</span>
          </div>
          <div style="flex:1;background:#2A2A3E;border-radius:6px;display:flex;align-items:center;justify-content:center;min-width:80px">
            <span style="font-size:11px;color:#D1D5DB">Cell 2</span>
          </div>
          <div style="flex:1;background:#2A2A3E;border-radius:6px;display:flex;align-items:center;justify-content:center;min-width:80px">
            <span style="font-size:11px;color:#D1D5DB">Cell 3</span>
          </div>
          <div style="flex:1;background:#2A2A3E;border-radius:6px;display:flex;align-items:center;justify-content:center;min-width:80px">
            <span style="font-size:11px;color:#D1D5DB">Cell 4</span>
          </div>
          <div style="flex:1;background:#2A2A3E;border-radius:6px;display:flex;align-items:center;justify-content:center;min-width:80px">
            <span style="font-size:11px;color:#D1D5DB">Cell 5</span>
          </div>
          <div style="flex:1;background:#2A2A3E;border-radius:6px;display:flex;align-items:center;justify-content:center;min-width:80px">
            <span style="font-size:11px;color:#D1D5DB">Cell 6</span>
          </div>
          <div style="flex:1;background:#2A2A3E;border-radius:6px;display:flex;align-items:center;justify-content:center;min-width:80px">
            <span style="font-size:11px;color:#D1D5DB">Cell 7</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Scroll Info -->
    <div style="margin:12px 16px 0 16px;height:80px;width:1888px">
      <div style="padding:10px;background:#1F1F23;border-radius:6px">
        <span style="font-size:11px;color:#9CA3AF">
          Px 框架支持 overflow-y:auto / overflow-x:auto 滚动容器，
          通过 :scroll-top / :scroll-left 绑定实现滚动位置跟踪，
          支持滚轮滚动、滚动条拖拽、Shift+滚轮水平滚动。
        </span>
      </div>
    </div>

    <!-- Spacer -->
    <div style="height:16px"></div>
  </div>
</template>

<script lang="php">
class ScrollShowcaseComponent extends ReactiveComponent
{
    public string $scrollTop = '0';
    public array $scrollItems = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];

    public function getVerticalItems(): array
    {
        return $this->scrollItems;
    }

    public function getVerticalItemCount(): int
    {
        return count($this->scrollItems);
    }

    public function addScrollItem(string $arg): void
    {
        $this->scrollItems[] = count($this->scrollItems) + 1;
        $this->markDirty();
    }

    public function clearScrollItems(string $arg): void
    {
        $this->scrollItems = [1, 2, 3, 4, 5];
        $this->markDirty();
    }
}
</script>
