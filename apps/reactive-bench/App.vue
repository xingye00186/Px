<template>
  <div style="width:100%;height:100%;display:flex;flex-direction:column;background:#1C1C1E">
    <div v-if="currentCase === 'SimpleCounter'" style="padding:10px">
      <span style="font-size:16px;color:#FFFFFF">Simple Counter: {{ counter }}</span>
      <button style="margin-left:10px;padding:5px 15px;background:#FF9F0A" @click="increment">+1</button>
    </div>

    <div v-else-if="currentCase === 'ManyProps'" style="padding:10px">
      <span style="font-size:16px;color:#FFFFFF">ManyProps: tracked={{ trackedProp }} others={{ otherCount }}</span>
      <button style="margin-left:10px;padding:5px 15px;background:#FF9F0A" @click="changeTracked">Change Tracked</button>
      <button style="margin-left:10px;padding:5px 15px;background:#505050" @click="changeUntracked">Change Untracked</button>
    </div>

    <div v-else-if="currentCase === 'DeepTree'" style="display:flex;flex-direction:column">
      <deep-tree-node style="margin:2px" :depth="5" :label="'root'" />
    </div>

    <div v-else-if="currentCase === 'MixedWorkload'" style="display:flex;flex-direction:column">
      <div style="padding:10px;border-bottom:1px solid #333">
        <span style="font-size:14px;color:#FFFFFF">Cycle: {{ cycleCount }} Render: {{ totalRenders }}</span>
      </div>
      <div style="flex:1;overflow-y:scroll;padding:10px" :scroll-top="scrollPos">
        <div v-for="item in items" :key="item.id" style="height:30px;display:flex;align-items:center;border-bottom:1px solid #444;font-size:13px;color:#CCC">
          <span>{{ item.label }}</span>
          <span :style="'margin-left:auto;color:' + (item.active ? '#FF9F0A' : '#666')">{{ item.value }}</span>
        </div>
      </div>
    </div>

    <div v-else style="padding:20px">
      <span style="font-size:18px;color:#FF4444">Unknown case: {{ currentCase }}</span>
    </div>
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $currentCase = 'SimpleCounter';

    // ── SimpleCounter ──
    #[Reactive]
    public int $counter = 0;

    public function increment(): void
    {
        $this->counter++;
    }

    // ── ManyProps ──
    #[Reactive]
    public int $trackedProp = 0;

    #[Reactive]
    public array $untrackedProps = [];

    public int $otherCount = 999;

    public function changeTracked(): void
    {
        $this->trackedProp++;
    }

    public function changeUntracked(): void
    {
        // 修改未被 render 读取的属性 —— 零调度开销
        $this->untrackedProps[] = time();
    }

    // ── MixedWorkload ──
    #[Reactive]
    public int $cycleCount = 0;
    public int $totalRenders = 0;
    #[Reactive]
    public int $scrollPos = 0;

    #[Reactive]
    public array $items = [];

    public function prepareItems(): void
    {
        $this->items = [];
        for ($i = 0; $i < 100; $i++) {
            $this->items[] = ['id' => (string)$i, 'label' => 'Item ' . $i, 'value' => (string)($i * 10), 'active' => ($i % 2 === 0)];
        }
    }

    public function runWorkloadCycle(): void
    {
        $this->cycleCount++;
        $this->scrollPos = ($this->scrollPos + 50) % 3000;

        // 随机更新几个 item
        $idx = $this->cycleCount % 100;
        $items = $this->items;
        if (isset($items[$idx])) {
            $items[$idx]['value'] = (string)((int)$items[$idx]['value'] + 1);
            $items[$idx]['active'] = !$items[$idx]['active'];
        }
        $this->items = $items;
    }

    // ── 动态组件切换 ──
    public function selectCase(string $case): void
    {
        $this->currentCase = $case;
        if ($case === 'ManyProps') {
            $this->otherCount = 999;
            $this->untrackedProps = [];
            for ($i = 0; $i < 999; $i++) {
                $this->untrackedProps[] = $i;
            }
        }
        if ($case === 'MixedWorkload') {
            $this->prepareItems();
        }
    }
</script>
