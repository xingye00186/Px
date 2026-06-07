<template>
  <div style="width:1920px;height:1000px;display:flex;flex-direction:column;background:#0B0B0E">
    <!-- Hero Section -->
    <div style="height:200px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:linear-gradient(180deg,#12121A 0%,#0B0B0E 100%);border-bottom:1px solid #1E1E24">
      <span style="font-size:26px;font-weight:700;color:#FFFFFF;letter-spacing:1px">Px Framework</span>
      <span style="margin-top:6px;font-size:12px;color:#71717A">PHP → Native Desktop GUI Framework — AOT 编译 · Win32 GDI · Vue 3 SFC</span>
      <div style="margin-top:16px;display:flex;flex-direction:row;gap:8px">
        <div style="padding:4px 12px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:12px;font-size:10px;color:#818CF8;font-weight:500">AOT 编译</div>
        <div style="padding:4px 12px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:12px;font-size:10px;color:#6EE7B7;font-weight:500">Win32 GDI</div>
        <div style="padding:4px 12px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:12px;font-size:10px;color:#FCD34D;font-weight:500">Vue 3 SFC</div>
        <div style="padding:4px 12px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:12px;font-size:10px;color:#FCA5A5;font-weight:500">CSS Flex/Grid</div>
        <div style="padding:4px 12px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:12px;font-size:10px;color:#A5B4FC;font-weight:500">动画系统</div>
      </div>
    </div>

    <!-- Stats Grid -->
    <div style="height:60px;display:flex;flex-direction:row;background:#121215;border-bottom:1px solid #1E1E24">
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:18px;font-weight:700;color:#818CF8">7</span>
        <span style="margin-top:2px;font-size:9px;color:#52525B">布局模式</span>
      </div>
      <div style="width:1px;height:36px;margin-top:12px;background:#1E1E24"></div>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:18px;font-weight:700;color:#6EE7B7">60</span>
        <span style="margin-top:2px;font-size:9px;color:#52525B">UI 组件</span>
      </div>
      <div style="width:1px;height:36px;margin-top:12px;background:#1E1E24"></div>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:18px;font-weight:700;color:#FCD34D">22</span>
        <span style="margin-top:2px;font-size:9px;color:#52525B">缓动函数</span>
      </div>
      <div style="width:1px;height:36px;margin-top:12px;background:#1E1E24"></div>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:18px;font-weight:700;color:#FCA5A5">8</span>
        <span style="margin-top:2px;font-size:9px;color:#52525B">动画类型</span>
      </div>
      <div style="width:1px;height:36px;margin-top:12px;background:#1E1E24"></div>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
        <span style="font-size:18px;font-weight:700;color:#A5B4FC">5</span>
        <span style="margin-top:2px;font-size:9px;color:#52525B">渲染后端</span>
      </div>
    </div>

    <!-- Interactive Demos Section -->
    <div style="display:flex;flex-direction:column;gap:0;padding:16px 24px">

      <!-- Section 1: v-show Demo -->
      <div style="background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px;display:flex;flex-direction:column">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">v-show 指令</span>
          <span style="font-size:9px;color:#52525B">条件可见性切换</span>
          <div style="flex:1"></div>
          <div style="width:4px;height:4px;border-radius:2px;background:#818CF8"></div>
          <span style="font-size:9px;color:#52525B">{{ showDetail ? '显示' : '隐藏' }}</span>
        </div>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:10px;align-items:center">
          <button style="padding:6px 16px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="toggleDetail" click-arg="">切换显示</button>
          <div style="padding:6px 14px;border-radius:6px;font-size:11px;font-weight:500;transition:all 0.3s ease" :class="showDetail ? 'badge-demo-on' : 'badge-demo-off'">
            {{ showDetail ? '✨ 我目前可见' : '👻 我目前隐藏' }}
          </div>
        </div>
      </div>

      <!-- Section 2: :class Dynamic Binding -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px;display:flex;flex-direction:column">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">:class 动态绑定</span>
          <span style="font-size:9px;color:#52525B">多种状态样式切换</span>
          <div style="flex:1"></div>
          <span style="font-size:9px;color:#71717A">当前: {{ statusLabel }}</span>
        </div>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:6px;align-items:center">
          <button style="padding:5px 12px;background:#374151;color:#D1D5DB;border:none;border-radius:6px;font-size:10px;cursor:pointer" @click="setStatus" click-arg="default">默认</button>
          <button style="padding:5px 12px;background:#374151;color:#D1D5DB;border:none;border-radius:6px;font-size:10px;cursor:pointer" @click="setStatus" click-arg="success">成功</button>
          <button style="padding:5px 12px;background:#374151;color:#D1D5DB;border:none;border-radius:6px;font-size:10px;cursor:pointer" @click="setStatus" click-arg="warning">警告</button>
          <button style="padding:5px 12px;background:#374151;color:#D1D5DB;border:none;border-radius:6px;font-size:10px;cursor:pointer" @click="setStatus" click-arg="danger">危险</button>
          <button style="padding:5px 12px;background:#374151;color:#D1D5DB;border:none;border-radius:6px;font-size:10px;cursor:pointer" @click="setStatus" click-arg="info">信息</button>
          <div style="margin-left:8px;padding:5px 16px;border-radius:14px;font-size:11px;font-weight:500" :class="'badge-' . activeStatus">{{ statusLabel }}</div>
        </div>
      </div>

      <!-- Section 3: :bind Counter with Progress Bar -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px;display:flex;flex-direction:column">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">:bind 双向绑定</span>
          <span style="font-size:9px;color:#52525B">计数器 + 可视化进度条</span>
          <div style="flex:1"></div>
          <span style="font-size:9px;color:#6366F1">:bind="counter"</span>
        </div>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:8px;align-items:center">
          <button style="width:32px;height:32px;background:#374151;color:#FFFFFF;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer" @click="decrement" click-arg="">−</button>
          <span style="font-size:22px;font-weight:700;color:#818CF8;min-width:48px;text-align:center" :bind="counter">{{ counter }}</span>
          <button style="width:32px;height:32px;background:#6366F1;color:#FFFFFF;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer" @click="increment" click-arg="">+</button>
          <button style="padding:5px 12px;background:#374151;color:#9CA3AF;border:none;border-radius:6px;font-size:10px;cursor:pointer" @click="resetCounter" click-arg="">重置</button>
          <!-- Progress Bar Visual -->
          <div style="margin-left:8px;width:120px;height:6px;background:#1E1E24;border-radius:3px">
            <div style="height:6px;border-radius:3px;background:#818CF8" :style="'width:' . counterPct . 'px'"></div>
          </div>
          <span style="font-size:9px;color:#71717A">{{ counterPct }}%</span>
        </div>
      </div>

      <!-- Section 4: v-for Tag Cloud -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px;display:flex;flex-direction:column">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">v-for 列表渲染</span>
          <span style="font-size:9px;color:#52525B">技术栈标签云（点击选中高亮）</span>
          <div style="flex:1"></div>
          <span style="font-size:9px;color:#71717A">{{ selectedTag }}</span>
        </div>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
          <template v-for="item in techTags" :key="item">
            <div style="padding:5px 12px;border-radius:14px;font-size:10px;font-weight:500;cursor:pointer" :class="selectedTag === item ? 'tag-highlight' : 'tag-normal'" @click="selectTag" click-arg="{{ item }}">{{ item }}</div>
          </template>
        </div>
      </div>

      <!-- Section 5: Interactive Button Group -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px;display:flex;flex-direction:column">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">交互按钮组</span>
          <span style="font-size:9px;color:#52525B">不同风格按钮点击测试</span>
          <div style="flex:1"></div>
          <span style="font-size:9px;color:#71717A">{{ clickCount }} 次点击</span>
        </div>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:8px;align-items:center">
          <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickAction" click-arg="">Primary</button>
          <button style="padding:6px 14px;background:#10B981;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickAction" click-arg="">Success</button>
          <button style="padding:6px 14px;background:#F59E0B;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickAction" click-arg="">Warning</button>
          <button style="padding:6px 14px;background:#EF4444;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickAction" click-arg="">Danger</button>
          <button style="padding:6px 14px;background:#374151;color:#FFFFFF;border:1px solid #52525B;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickAction" click-arg="">Outline</button>
          <span style="margin-left:8px;font-size:10px;color:#71717A" v-show="showMsg">{{ lastAction }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">
class OverviewComponent extends ReactiveComponent
{
    public bool $showDetail = false;
    public string $activeStatus = 'default';
    public string $statusLabel = '默认';
    public string $counter = '0';
    public string $selectedTag = 'PHP 8.x';
    public int $clickCount = 0;
    public bool $showMsg = false;
    public string $lastAction = '';

    public array $techTags = ['PHP 8.x', 'Win32 GDI', 'Vue 3 SFC', 'AOT 编译', 'CSS Flex', 'CSS Grid', 'v-for/v-if', '动画系统', '滚动容器', '事件冒泡', 'Skia 后端', '主题系统'];

    public function toggleDetail(string $arg): void
    {
        $this->showDetail = !$this->showDetail;
        $this->markDirty();
    }

    public function setStatus(string $status): void
    {
        $this->activeStatus = $status;
        $labels = ['default' => '默认', 'success' => '成功', 'warning' => '警告', 'danger' => '危险', 'info' => '信息'];
        $this->statusLabel = $labels[$status] ?? '默认';
        $this->markDirty();
    }

    public function increment(string $arg): void
    {
        $val = (int)$this->counter;
        if ($val < 100) $val++;
        $this->counter = (string)$val;
        $this->markDirty();
    }

    public function decrement(string $arg): void
    {
        $val = (int)$this->counter;
        if ($val > 0) $val--;
        $this->counter = (string)$val;
        $this->markDirty();
    }

    public function resetCounter(string $arg): void
    {
        $this->counter = '0';
        $this->markDirty();
    }

    public function getCounterPct(): int
    {
        return (int)$this->counter;
    }

    public function selectTag(string $tag): void
    {
        $this->selectedTag = $tag;
        $this->markDirty();
    }

    public function clickAction(string $arg): void
    {
        $this->clickCount++;
        $this->showMsg = true;
        $this->lastAction = '✅ 按钮被点击 (' . $this->clickCount . ')';
        $this->markDirty();
    }
}
</script>

<style>
.badge-demo-on {
  background: #065F46;
  color: #6EE7B7;
  border: 1px solid #10B981;
}
.badge-demo-off {
  background: #27272A;
  color: #71717A;
  border: 1px solid #3F3F46;
}
.badge-default {
  background: #374151;
  color: #D1D5DB;
  border: 1px solid #52525B;
}
.badge-success {
  background: #065F46;
  color: #6EE7B7;
  border: 1px solid #10B981;
}
.badge-warning {
  background: #78350F;
  color: #FCD34D;
  border: 1px solid #F59E0B;
}
.badge-danger {
  background: #7F1D1D;
  color: #FCA5A5;
  border: 1px solid #EF4444;
}
.badge-info {
  background: #1E3A5F;
  color: #93C5FD;
  border: 1px solid #3B82F6;
}
.tag-normal {
  background: #18181B;
  color: #A1A1AA;
  border: 1px solid #27272A;
}
.tag-highlight {
  background: #1E1F2E;
  color: #A5B4FC;
  border: 1px solid #6366F1;
}
</style>
