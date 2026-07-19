<template>
  <div style="width:100%;height:100%;display:flex;flex-direction:column;background:#1C1C1E">
    <!-- SimpleCounter -->
    <div v-if="currentCase === 'SimpleCounter'" style="padding:10px">
      <span style="font-size:16px;color:#FFFFFF">Simple Counter: {{ counter }}</span>
    </div>

    <!-- ManyProps -->
    <div v-else-if="currentCase === 'ManyProps'" style="padding:10px">
      <span style="font-size:16px;color:#FFFFFF">ManyProps: tracked={{ trackedProp }}</span>
    </div>

    <!-- DeepTree -->
    <div v-else-if="currentCase === 'DeepTree'" style="display:flex;flex-direction:column">
      <deep-tree-node style="margin:2px" :depth="5" :label="'root'" />
    </div>

    <!-- MixedWorkload -->
    <div v-else-if="currentCase === 'MixedWorkload'" style="display:flex;flex-direction:column">
      <div style="flex:1;overflow-y:scroll;padding:10px" :scroll-top="scrollPos">
        <div v-for="item in items" :key="item.id" style="height:30px;display:flex;align-items:center;border-bottom:1px solid #444;font-size:13px;color:#CCC">
          <span>{{ item.label }}</span>
          <span :style="'margin-left:auto;color:' + (item.active ? '#FF9F0A' : '#666')">{{ item.value }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">
    public string $currentCase = 'SimpleCounter';

    // ── SimpleCounter ──
    public string $counter = '0';
    public function increment(): void
    {
        $this->counter = (string)((int)$this->counter + 1);
        $this->markDirty();
    }

    // ── ManyProps ──
    public string $trackedProp = '0';
    public int $changeCount = 0;
    public function changeTracked(): void
    {
        $this->trackedProp = (string)((int)$this->trackedProp + 1);
        $this->changeCount++;
        $this->markDirty();
    }

    public function changeUntracked(): void
    {
        $this->changeCount++;
        $this->markDirty();
    }

    // ── MixedWorkload ──
    public string $scrollPos = '0';
    public array $items = [];

    public function prepareItems(): void
    {
        $this->items = [];
        for ($i = 0; $i < 100; $i++) {
            $this->items[] = ['id' => (string)$i, 'label' => 'Item ' . $i, 'value' => (string)($i * 10), 'active' => ($i % 2 === 0)];
        }
        $this->markDirty();
    }

    public function runWorkloadCycle(): void
    {
        $this->scrollPos = (string)(((int)$this->scrollPos + 50) % 3000);
        $idx = $this->cycleCount % 100;
        $this->cycleCount++;
        $items = $this->items;
        if (isset($items[$idx])) {
            $items[$idx]['value'] = (string)((int)$items[$idx]['value'] + 1);
            $items[$idx]['active'] = !$items[$idx]['active'];
        }
        $this->items = $items;
        $this->markDirty();
    }
    public int $cycleCount = 0;

    // ── 切换 ──
    public function selectCase(string $case): void
    {
        $this->currentCase = $case;
        if ($case === 'ManyProps') {
            $this->trackedProp = '0';
            $this->changeCount = 0;
        }
        if ($case === 'MixedWorkload') {
            $this->prepareItems();
        }
        $this->markDirty();
    }
</script>
