<template>
  <div style="width:420px;height:720px;background:#1C1C1E">
    <!-- Header -->
    <div style="left:0px;top:0px;width:420px;height:100px;text-align:center">
      <h1 style="left:0px;top:20px;width:420px;font-size:24px;font-weight:600;color:#FFFFFF">Px Design Guide</h1>
      <p style="left:0px;top:56px;width:420px;font-size:14px;color:#6B7280">Modern Design System Components</p>
    </div>

    <!-- Tab Navigation -->
    <div style="left:0px;top:100px;width:420px;height:48px;display:flex;flex-direction:row;gap:4px;padding:0 16px;box-sizing:border-box">
      <div style="flex:1" :class="activeTab === 'colors' ? 'tab-active' : ''" @click="setTab" click-arg="colors">
        <span>Colors</span>
      </div>
      <div style="flex:1" :class="activeTab === 'typography' ? 'tab-active' : ''" @click="setTab" click-arg="typography">
        <span>Type</span>
      </div>
      <div style="flex:1" :class="activeTab === 'buttons' ? 'tab-active' : ''" @click="setTab" click-arg="buttons">
        <span>Buttons</span>
      </div>
      <div style="flex:1" :class="activeTab === 'cards' ? 'tab-active' : ''" @click="setTab" click-arg="cards">
        <span>Cards</span>
      </div>
      <div style="flex:1" :class="activeTab === 'layout' ? 'tab-active' : ''" @click="setTab" click-arg="layout">
        <span>Layout</span>
      </div>
    </div>

    <!-- Content Area -->
    <div style="left:0px;top:148px;width:420px;height:572px;overflow-y:auto">
      <color-palette v-if="activeTab === 'colors'" style="left:0px;top:0px" />
      <typography-showcase v-if="activeTab === 'typography'" style="left:0px;top:0px" />
      <button-showcase v-if="activeTab === 'buttons'" style="left:0px;top:0px" />
      <card-showcase v-if="activeTab === 'cards'" style="left:0px;top:0px" />
      <layout-demo v-if="activeTab === 'layout'" style="left:0px;top:0px" />
    </div>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $activeTab = 'colors';

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->dirty = true;
    }
}
</script>

<style>
.tab-active {
  color: #6366F1;
  border-bottom: 2px solid #6366F1;
}
</style>