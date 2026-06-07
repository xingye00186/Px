<template>
  <div style="width:1920px;height:1080px;background:#0B0B0E;display:flex;flex-direction:column">
    <!-- Header -->
    <div style="height:52px;display:flex;align-items:center;background:#121215;border-bottom:1px solid #1E1E24;padding:0 24px">
      <span style="font-size:15px;font-weight:700;color:#FFFFFF;letter-spacing:0.5px">Px Design System</span>
      <span style="margin-left:10px;font-size:9px;color:#52525B;padding:2px 8px;background:#1A1A20;border-radius:4px">v2.0</span>
      <div style="flex:1"></div>
      <div style="width:6px;height:6px;border-radius:3px;background:#6366F1;margin-right:6px"></div>
      <span style="font-size:10px;color:#52525B">Framework v7</span>
    </div>

    <!-- Tab Bar -->
    <div style="height:38px;display:flex;background:#121215;border-bottom:1px solid #1E1E24;padding:0 4px">
      <div style="padding:0 22px;display:flex;align-items:center;cursor:pointer;position:relative" :class="activeTab === 'overview' ? 'tab-active' : ''" @click="switchTab" click-arg="overview">
        <span style="font-size:11px;font-weight:500;color:#A1A1AA" :class="activeTab === 'overview' ? 'tab-label-active' : ''">概览</span>
      </div>
      <div style="padding:0 22px;display:flex;align-items:center;cursor:pointer;position:relative" :class="activeTab === 'colors' ? 'tab-active' : ''" @click="switchTab" click-arg="colors">
        <span style="font-size:11px;font-weight:500;color:#A1A1AA" :class="activeTab === 'colors' ? 'tab-label-active' : ''">色彩</span>
      </div>
      <div style="padding:0 22px;display:flex;align-items:center;cursor:pointer;position:relative" :class="activeTab === 'components' ? 'tab-active' : ''" @click="switchTab" click-arg="components">
        <span style="font-size:11px;font-weight:500;color:#A1A1AA" :class="activeTab === 'components' ? 'tab-label-active' : ''">组件</span>
      </div>
      <div style="padding:0 22px;display:flex;align-items:center;cursor:pointer;position:relative" :class="activeTab === 'layout' ? 'tab-active' : ''" @click="switchTab" click-arg="layout">
        <span style="font-size:11px;font-weight:500;color:#A1A1AA" :class="activeTab === 'layout' ? 'tab-label-active' : ''">布局</span>
      </div>
      <div style="padding:0 22px;display:flex;align-items:center;cursor:pointer;position:relative" :class="activeTab === 'scrolling' ? 'tab-active' : ''" @click="switchTab" click-arg="scrolling">
        <span style="font-size:11px;font-weight:500;color:#A1A1AA" :class="activeTab === 'scrolling' ? 'tab-label-active' : ''">滚动</span>
      </div>
      <div style="padding:0 22px;display:flex;align-items:center;cursor:pointer;position:relative" :class="activeTab === 'animation' ? 'tab-active' : ''" @click="switchTab" click-arg="animation">
        <span style="font-size:11px;font-weight:500;color:#A1A1AA" :class="activeTab === 'animation' ? 'tab-label-active' : ''">动画</span>
      </div>
    </div>

    <!-- Content Area -->
    <div style="flex:1;overflow-y:auto" :scroll-top="scrollTop">
      <overview v-if="activeTab === 'overview'" />
      <color-palette v-if="activeTab === 'colors'" />
      <components-showcase v-if="activeTab === 'components'" />
      <layout-demo v-if="activeTab === 'layout'" />
      <scroll-showcase v-if="activeTab === 'scrolling'" />
      <animation-showcase v-if="activeTab === 'animation'" />
    </div>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $activeTab = 'overview';
    public string $scrollTop = '0';

    public string $badgeText = '';
    public string $counter = '';
    public string $item = '';

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->markDirty();
    }

    // setBindValue/getBindValue 由 SFC 编译器自动生成
}
</script>

<style>
.tab-active {
  border-bottom: 2px solid #6366F1;
}
.tab-label-active {
  color: #FFFFFF !important;
}
</style>
