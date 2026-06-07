<template>
  <div style="width:1920px;height:1000px;display:flex;flex-direction:column;background:#0B0B0E">

    <!-- Page Header -->
    <div style="padding:20px 24px 12px 24px;border-bottom:1px solid #1E1E24">
      <span style="font-size:16px;font-weight:600;color:#FFFFFF">滚动系统</span>
      <span style="margin-left:10px;font-size:10px;color:#52525B">Scroll — overflow-y · overflow-x · :scroll-top · :scroll-left</span>
    </div>

    <div style="display:flex;flex-direction:column;gap:0;padding:16px 24px">

      <!-- Vertical Scroll + + Horizontal Scroll Row -->
      <div style="display:flex;flex-direction:row;gap:8px">

        <!-- Vertical Scroll -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <div style="display:flex;flex-direction:row;justify-content:space-between;align-items:center">
            <span style="font-size:11px;font-weight:600;color:#FFFFFF">垂直滚动</span>
            <span style="font-size:9px;color:#6366F1">位置: {{ scrollTop }}</span>
          </div>
          <span style="margin-top:2px;font-size:9px;color:#52525B">overflow-y:auto · 滚动条拖拽</span>
          <div style="margin-top:8px;height:260px;background:#18181B;border-radius:8px;overflow-y:auto" :scroll-top="scrollTop">
            <template v-for="item in verticalItems" :key="item">
              <div style="padding:8px 12px;border-bottom:1px solid #27272A;display:flex;flex-direction:row;align-items:center">
                <div style="width:6px;height:6px;border-radius:3px;background:#6366F1;margin-right:10px"></div>
                <span style="font-size:11px;color:#A1A1AA">列表项目 {{ item }}</span>
              </div>
            </template>
          </div>
          <div style="margin-top:8px;display:flex;flex-direction:row;gap:6px;align-items:center">
            <button style="padding:4px 12px;background:#6366F1;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="addScrollItem" click-arg="">添加</button>
            <button style="padding:4px 12px;background:#EF4444;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="clearScrollItems" click-arg="">清空</button>
            <span style="font-size:9px;color:#71717A">{{ verticalItemCount }} 项</span>
          </div>
        </div>

        <!-- Horizontal Scroll -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">水平滚动</span>
          <span style="margin-top:2px;font-size:9px;color:#52525B">overflow-x:auto · Shift+滚轮可水平滚动</span>
          <div style="margin-top:8px;height:80px;background:#18181B;border-radius:8px;overflow-x:auto">
            <div style="width:1200px;height:80px;display:flex;flex-direction:row;gap:8px;padding:12px">
              <div style="min-width:80px;background:#27272A;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;color:#A1A1AA">Cell 1</span>
              </div>
              <div style="min-width:80px;background:#6366F1;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;font-weight:500;color:#FFFFFF">Cell 2</span>
              </div>
              <div style="min-width:80px;background:#27272A;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;color:#A1A1AA">Cell 3</span>
              </div>
              <div style="min-width:80px;background:#10B981;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;font-weight:500;color:#FFFFFF">Cell 4</span>
              </div>
              <div style="min-width:80px;background:#27272A;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;color:#A1A1AA">Cell 5</span>
              </div>
              <div style="min-width:80px;background:#F59E0B;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;font-weight:500;color:#FFFFFF">Cell 6</span>
              </div>
              <div style="min-width:80px;background:#27272A;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;color:#A1A1AA">Cell 7</span>
              </div>
              <div style="min-width:80px;background:#EF4444;border-radius:6px;display:flex;align-items:center;justify-content:center">
                <span style="font-size:10px;font-weight:500;color:#FFFFFF">Cell 8</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Dual-axis Scroll -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <div style="display:flex;flex-direction:row;justify-content:space-between;align-items:center">
          <div>
            <span style="font-size:11px;font-weight:600;color:#FFFFFF">双向滚动</span>
            <span style="margin-left:8px;font-size:9px;color:#52525B">overflow:auto 双轴滚动容器</span>
          </div>
          <span style="font-size:9px;color:#6366F1">垂直: {{ scrollTop }} | Shift+滚轮水平</span>
        </div>
        <div style="margin-top:8px;height:200px;background:#18181B;border-radius:8px;overflow:auto;padding:0" :scroll-top="scrollTop">
          <!-- Header Row -->
          <div style="display:flex;flex-direction:row;background:#1E1E24;border-bottom:1px solid #27272A;position:sticky;top:0">
            <div style="width:120px;padding:8px 12px;border-right:1px solid #27272A">
              <span style="font-size:10px;font-weight:600;color:#D1D5DB">名称</span>
            </div>
            <div style="width:100px;padding:8px 12px;border-right:1px solid #27272A">
              <span style="font-size:10px;font-weight:600;color:#D1D5DB">类型</span>
            </div>
            <div style="width:100px;padding:8px 12px;border-right:1px solid #27272A">
              <span style="font-size:10px;font-weight:600;color:#D1D5DB">状态</span>
            </div>
            <div style="width:120px;padding:8px 12px">
              <span style="font-size:10px;font-weight:600;color:#D1D5DB">操作</span>
            </div>
          </div>
          <!-- Data Rows -->
          <template v-for="row in scrollRows" :key="row.id">
            <div style="display:flex;flex-direction:row;border-bottom:1px solid #27272A">
              <div style="width:120px;padding:7px 12px;border-right:1px solid #27272A">
                <span style="font-size:10px;color:#D1D5DB">{{ row.name }}</span>
              </div>
              <div style="width:100px;padding:7px 12px;border-right:1px solid #27272A">
                <span style="font-size:10px;color:#A1A1AA">{{ row.type }}</span>
              </div>
              <div style="width:100px;padding:7px 12px;border-right:1px solid #27272A">
                <div style="padding:1px 8px;border-radius:8px;display:inline-block;font-size:9px" :class="row.status === 'active' ? 'st-active' : 'st-inactive'">{{ row.status }}</div>
              </div>
              <div style="width:120px;padding:7px 12px">
                <span style="font-size:9px;color:#6366F1;cursor:pointer" @click="rowAction" click-arg="{{ row.id }}">编辑</span>
                <span style="margin:0 6px;color:#27272A">|</span>
                <span style="font-size:9px;color:#EF4444;cursor:pointer" @click="rowAction" click-arg="{{ row.id }}">删除</span>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Scroll Info -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">滚动能力说明</span>
        <div style="margin-top:6px;padding:10px;background:#18181B;border-radius:6px">
          <span style="font-size:10px;color:#A1A1AA;line-height:1.6">
            Px 框架支持完整的桌面滚动交互：overflow-y:auto / overflow-x:auto / overflow:auto 双轴滚动容器；
            :scroll-top / :scroll-left 双向绑定跟踪滚动位置；鼠标滚轮滚动（含 Shift 修饰键水平滚动）；
            滚动条拖拽（垂直/水平滑块）；轨道点击跳转；滚动位置自动 clamp 边界。
          </span>
        </div>
      </div>

      <!-- Spacer -->
      <div style="height:24px"></div>
    </div>
  </div>
</template>

<script lang="php">
class ScrollShowcaseComponent extends ReactiveComponent
{
    public string $scrollTop = '0';
    public array $scrollItems = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    public array $dataRows = [
        ['id' => 1, 'name' => '项目 Alpha', 'type' => 'Web', 'status' => 'active'],
        ['id' => 2, 'name' => '数据服务', 'type' => 'API', 'status' => 'active'],
        ['id' => 3, 'name' => '控制面板', 'type' => 'GUI', 'status' => 'inactive'],
        ['id' => 4, 'name' => '用户管理', 'type' => 'Web', 'status' => 'active'],
        ['id' => 5, 'name' => '日志系统', 'type' => 'API', 'status' => 'inactive'],
        ['id' => 6, 'name' => '任务调度', 'type' => 'Core', 'status' => 'active'],
    ];

    public function getVerticalItems(): array
    {
        return $this->scrollItems;
    }

    public function getVerticalItemCount(): int
    {
        return count($this->scrollItems);
    }

    public function getScrollRows(): array
    {
        return $this->dataRows;
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

    public function rowAction(string $arg): void
    {
        $this->markDirty();
    }
}
</script>

<style>
.st-active {
  background: #053321;
  color: #6EE7B7;
}
.st-inactive {
  background: #27272A;
  color: #71717A;
}
</style>
