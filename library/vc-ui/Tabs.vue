<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column">
    <!-- Tab 标签导航栏 -->
    <div style="width:100%;height:36px;display:flex;flex-direction:row;border-bottom:1px solid #E4E7ED" class="tabs-header">
      <div v-for="(tab, idx) in tabItems" :key="idx"
           style="height:35px;padding:0 16px;display:flex;align-items:center;cursor:pointer"
           :class="(tab.name === activeKey || '' === activeKey && idx === 0) ? 'tab-item-active' : 'tab-item'"
           @click="switchTab" :click-arg="tab.name">
        <span style="font-size:14px">{{ tab.label }}</span>
      </div>
      <!-- 下划线指示器 -->
      <div style="left:0px;bottom:-1px;height:2px" class="tab-ink-bar" :style="'width:' . inkWidth . 'px;left:' . inkLeft . 'px'"></div>
    </div>
    <!-- 内容区域 -->
    <div style="width:100%;height:auto;padding:12px 0">
      <span style="font-size:14px;color:#606266">{{ activeContent }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 当前激活的 tab key */
    public string $activeKey = '';

    /** Tab 数据: [['name'=>'tab1','label'=>'Tab 1'], ...] */
    public array $tabItems = [['name'=>'tab1','label'=>'Tab 1'],['name'=>'tab2','label'=>'Tab 2']];

    /** Tab 内容映射: ['tab1'=>'内容1', 'tab2'=>'内容2'] */
    public array $tabContents = [];

    /** Tabs 类型: line / card */
    public string $type = 'line';

    /** 下划线宽度 (px) */
    public string $inkWidth = '0';

    /** 下划线偏移 (px) */
    public string $inkLeft = '0';

    /**
     * 获取当前激活内容的 key
     */
    public function getActiveContent(): string
    {
        $key = $this->activeKey;
        if ($key === '' && count($this->tabItems) > 0) {
            $key = $this->tabItems[0]['name'];
        }
        return $this->tabContents[$key] ?? '';
    }

    /**
     * 切换 Tab
     */
    public function switchTab(string $name): void
    {
        $this->activeKey = $name;
        $this->markDirty();
    }
</script>

<style>
.tabs-header { background: #FFFFFF; position: relative; }
.tab-item { color: #606266; border-bottom: 2px solid transparent; }
.tab-item:hover { color: #409EFF; }
.tab-item-active { color: #409EFF; border-bottom: 2px solid #409EFF; font-weight: 600; }
.tab-ink-bar { position: absolute; background: #409EFF; transition: left 0.3s ease, width 0.3s ease; }
</style>
