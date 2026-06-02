<template>
  <div style="left:0px;top:0px;width:1920px;height:970px;display:flex;flex-direction:column;gap:4px;background:#0F0F11">
    <!-- Welcome -->
    <div style="left:0px;top:0px;width:420px;height:110px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#1A1A2E">
      <span style="font-size:22px;font-weight:600;color:#FFFFFF">Px Framework</span>
      <span style="margin-top:8px;font-size:12px;color:#9CA3AF">PHP &rarr; Native Desktop GUI Framework</span>
      <div style="margin-top:10px;display:flex;flex-direction:row;gap:8px">
        <div style="padding:4px 10px;background:#6366F1;border-radius:4px;font-size:10px;color:#FFFFFF">AOT 编译</div>
        <div style="padding:4px 10px;background:#374151;border-radius:4px;font-size:10px;color:#D1D5DB">Win32 GDI</div>
        <div style="padding:4px 10px;background:#374151;border-radius:4px;font-size:10px;color:#D1D5DB">Vue 语法</div>
      </div>
    </div>

    <!-- Stats Overview -->
    <div style="left:0px;top:0px;width:1920px;height:56px;display:flex;flex-direction:row;background:#16161A">
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:20px;font-weight:700;color:#6366F1">7</span>
        <span style="margin-top:2px;font-size:10px;color:#6B7280">布局类型</span>
      </div>
      <div style="width:1px;height:36px;margin-top:10px;background:#2A2A2E"></div>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:20px;font-weight:700;color:#10B981">22</span>
        <span style="margin-top:2px;font-size:10px;color:#6B7280">缓动函数</span>
      </div>
      <div style="width:1px;height:36px;margin-top:10px;background:#2A2A2E"></div>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:20px;font-weight:700;color:#F59E0B">8</span>
        <span style="margin-top:2px;font-size:10px;color:#6B7280">动画类型</span>
      </div>
      <div style="width:1px;height:36px;margin-top:10px;background:#2A2A2E"></div>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:20px;font-weight:700;color:#EF4444">49</span>
        <span style="margin-top:2px;font-size:10px;color:#6B7280">源码文件</span>
      </div>
    </div>

    <!-- Section 1: v-show & @click -->
    <div style="margin:0 10px;height:85px;display:flex;flex-direction:column;background:#16161A;border-radius:6px;padding:10px">
      <span style="font-size:13px;font-weight:600;color:#FFFFFF">v-show 与事件交互</span>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="toggleDetail" click-arg="">切换详情</button>
        <span v-show="showDetail" style="font-size:12px;color:#D1D5DB">切换我显示/隐藏 &#x2728;</span>
      </div>
    </div>

    <!-- Section 2: :class -->
    <div style="margin:0 10px;height:85px;display:flex;flex-direction:column;background:#16161A;border-radius:6px;padding:10px">
      <span style="font-size:13px;font-weight:600;color:#FFFFFF">:class 动态绑定</span>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="padding:6px 14px;background:#374151;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="toggleActive" click-arg="">切换状态</button>
        <div :class="isActive ? 'badge-active' : 'badge-inactive'" style="padding:4px 12px;border-radius:12px;font-size:11px;font-weight:500">
          {{ badgeText }}
        </div>
      </div>
    </div>

    <!-- Section 3: :bind -->
    <div style="margin:0 10px;height:105px;display:flex;flex-direction:column;background:#16161A;border-radius:6px;padding:10px">
      <span style="font-size:13px;font-weight:600;color:#FFFFFF">:bind 双向绑定</span>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="increment" click-arg="">+1</button>
        <button style="padding:6px 14px;background:#EF4444;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="decrement" click-arg="">-1</button>
        <span style="font-size:22px;font-weight:700;color:#6366F1;min-width:40px;text-align:center" :bind="counter">{{ counter }}</span>
        <span style="font-size:11px;color:#6B7280">:bind="counter"</span>
      </div>
    </div>

    <!-- Section 4: v-for -->
    <div style="margin:0 10px;height:85px;display:flex;flex-direction:column;background:#16161A;border-radius:6px;padding:10px">
      <span style="font-size:13px;font-weight:600;color:#FFFFFF">v-for 列表渲染</span>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:4px;flex-wrap:wrap">
        <template v-for="item in techStack" :key="item">
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB;border:1px solid #3A3A4E">{{ item }}</div>
        </template>
      </div>
    </div>
  </div>
</template>

<script lang="php">
class OverviewComponent extends ReactiveComponent
{
    public bool $showDetail = false;
    public bool $isActive = false;
    public string $badgeText = 'Inactive';
    public string $counter = '0';
    public array $techStack = ['PHP 8.x', 'Win32 GDI', 'Vue 3 SFC', 'AOT 编译', 'CSS Flex', 'CSS Grid', 'v-for/v-if', '动画系统', '滚动容器', '事件冒泡'];

    public function toggleDetail(string $arg): void
    {
        $this->showDetail = !$this->showDetail;
    }

    public function toggleActive(string $arg): void
    {
        $this->isActive = !$this->isActive;
        $this->badgeText = $this->isActive ? 'Active' : 'Inactive';
    }

    public function increment(string $arg): void
    {
        $this->counter = (string)((int)$this->counter + 1);
    }

    public function decrement(string $arg): void
    {
        $this->counter = (string)((int)$this->counter - 1);
    }
}
</script>

<style>
.badge-active {
  background: #065F46;
  color: #6EE7B7;
  border: 1px solid #10B981;
}
.badge-inactive {
  background: #374151;
  color: #9CA3AF;
  border: 1px solid #4B5563;
}
</style>
