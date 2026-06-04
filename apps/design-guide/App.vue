<template>
  <div style="width:1920px;height:1080px;background:#0F0F11;display:flex;flex-direction:column">
    <!-- Header -->
    <div style="width:420px;height:48px;display:flex;align-items:center;background:#16161A;border-bottom:1px solid #2A2A2E">
      <span style="margin-left:16px;font-size:16px;font-weight:600;color:#FFFFFF">Px Design Guide</span>
    </div>

    <!-- Tab Bar -->
    <div style="height:36px;display:flex;background:#1A1A1E;border-bottom:1px solid #2A2A2E">
      <div style="flex:1;display:flex;align-items:center;justify-content:center" :class="activeTab === 'overview' ? 'tab-active' : ''" @click="switchTab" click-arg="overview">
        <span style="font-size:11px;color:#D1D5DB">概览</span>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center" :class="activeTab === 'colors' ? 'tab-active' : ''" @click="switchTab" click-arg="colors">
        <span style="font-size:11px;color:#D1D5DB">色彩</span>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center" :class="activeTab === 'typography' ? 'tab-active' : ''" @click="switchTab" click-arg="typography">
        <span style="font-size:11px;color:#D1D5DB">字体</span>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center" :class="activeTab === 'components' ? 'tab-active' : ''" @click="switchTab" click-arg="components">
        <span style="font-size:11px;color:#D1D5DB">组件</span>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center" :class="activeTab === 'layout' ? 'tab-active' : ''" @click="switchTab" click-arg="layout">
        <span style="font-size:11px;color:#D1D5DB">布局</span>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center" :class="activeTab === 'scrolling' ? 'tab-active' : ''" @click="switchTab" click-arg="scrolling">
        <span style="font-size:11px;color:#D1D5DB">滚动</span>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center" :class="activeTab === 'animation' ? 'tab-active' : ''" @click="switchTab" click-arg="animation">
        <span style="font-size:11px;color:#D1D5DB">动画</span>
      </div>
    </div>

    <!-- Content Area -->
    <div style="flex:1;overflow-y:auto" :scroll-top="scrollTop">
      <overview v-if="activeTab === 'overview'" />
      <color-palette v-if="activeTab === 'colors'" />
      <typography-showcase v-if="activeTab === 'typography'" />
      <div v-if="activeTab === 'components'" style="display:flex;flex-direction:column">
        <button-showcase />
        <card-showcase />
      </div>
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

    public string $counter = '';
    public string $item = '';
    public string $scrollTop = '';

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->markDirty();
    }
}
</script>

<style>
.tab-active {
  background: #2A2A3E;
  border-bottom: 2px solid #6366F1;
}
.tab-active span {
  color: #FFFFFF !important;
}
</style>
