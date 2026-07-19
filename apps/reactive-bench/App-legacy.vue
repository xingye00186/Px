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

    <div v-else-if="currentCase === 'FormDashboard'" style="display:flex;flex-direction:column;padding:8px;gap:4px">
      <div style="display:flex;justify-content:space-between;padding:4px 0">
        <span style="font-size:14px;color:#FFF">Dashboard v{{ version }}</span>
      </div>
      <div v-for="fd in formFields" :key="fd.id" style="padding:3px 0;border-bottom:1px solid #333">
        <span style="font-size:12px;color:#8E8E93">{{ fd.label }}:</span>
        <span :style="'font-size:13px;color:' + (fd.error ? '#FF4444' : '#FFF')">{{ fd.value }}</span>
        <span v-if="fd.error" style="margin-left:8px;font-size:11px;color:#FF4444">{{ fd.error }}</span>
      </div>
      <div style="display:flex;gap:6px;margin-top:4px">
        <span v-for="st in stats" :key="st.id" style="flex:1;padding:4px;background:#2C2C2E;text-align:center">
          <span :style="'font-size:16px;font-weight:bold;color:' + st.color">{{ st.val }}</span>
        </span>
      </div>
    </div>

    <div v-else-if="currentCase === 'ChatStream'" style="display:flex;flex-direction:column;padding:8px">
      <div style="font-size:12px;color:#8E8E93;padding:4px 0">Messages: {{ msgCount }}</div>
      <div v-for="msg in messages" :key="msg.id" style="padding:4px 0;border-bottom:1px solid #2C2C2E;font-size:13px">
        <span style="color:#FF9F0A">{{ msg.author }}:</span>
        <span style="color:#FFF;margin-left:6px">{{ msg.text }}</span>
      </div>
    </div>

    <!-- HoverGrid -->
    <div v-else-if="currentCase === 'HoverGrid'" style="display:flex;flex-direction:column;padding:8px;gap:4px">
      <span style="font-size:12px;color:#8E8E93">HoverGrid: cycle={{ hoverCycle }} idx={{ hoveredIdx }}</span>
      <div style="display:grid;grid-template-columns:repeat(10,1fr);gap:3px;margin-top:4px">
        <div v-for="cell in hoverCells" :key="cell.id"
          :style="'height:18px;border-radius:3px;background:' + (cell.idx === (int)$this->hoveredIdx ? '#FF9F0A' : '#2C2C2E') + ';cursor:pointer'">
        </div>
      </div>
    </div>

    <!-- DynamicList -->
    <div v-else-if="currentCase === 'DynamicList'" style="display:flex;flex-direction:column;padding:8px;gap:2px">
      <span style="font-size:12px;color:#8E8E93">DynamicList: count={{ dynaCount }}</span>
      <div v-for="wd in dynaWidgets" :key="wd.id"
        style="height:14px;display:flex;align-items:center;padding:0 4px;background:#2C2C2E;font-size:11px;color:#CCC">
        <span>{{ wd.label }}</span>
      </div>
    </div>

    <div v-else style="padding:20px">
      <span style="font-size:18px;color:#FF4444">Unknown case: {{ currentCase }}</span>
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
    public array $untrackedProps = [];
    public string $otherCount = '999';
    public function changeTracked(): void
    {
        $this->trackedProp = (string)((int)$this->trackedProp + 1);
        $this->markDirty();
    }
    public function changeUntracked(): void
    {
        $this->untrackedProps[] = time();
        $this->markDirty();
    }
    public int $changeCount = 0;

    // ── MixedWorkload ──
    public string $cycleCount = '0';
    public string $totalRenders = '0';
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
        $this->cycleCount = (string)((int)$this->cycleCount + 1);
        $this->scrollPos = (string)(((int)$this->scrollPos + 50) % 3000);
        $idx = (int)$this->cycleCount % 100;
        $items = $this->items;
        if (isset($items[$idx])) {
            $items[$idx]['value'] = (string)((int)$items[$idx]['value'] + 1);
            $items[$idx]['active'] = !$items[$idx]['active'];
        }
        $this->items = $items;
        $this->markDirty();
    }

    // ── FormDashboard ──
    public string $version = '1.0';
    public array $formFields = [];
    public array $stats = [];

    public function initFormDashboard(): void
    {
        $labels = ['Name','Email','Phone','Age','City','Score','Level','Status','Dept','Role'];
        $fields = [];
        for ($i = 0; $i < 10; $i++) {
            $fields[] = ['id' => (string)$i, 'label' => $labels[$i], 'value' => 'val_' . $i, 'error' => ''];
        }
        $this->formFields = $fields;
        $this->stats = [
            ['id' => 's1', 'val' => '0', 'color' => '#FF9F0A'],
            ['id' => 's2', 'val' => '0', 'color' => '#30D158'],
            ['id' => 's3', 'val' => '0', 'color' => '#FF4444'],
        ];
        $this->version = '1.0';
        $this->markDirty();
    }

    public function runDashboardCycle(): void
    {
        $fields = $this->formFields;
        for ($i = 0; $i < 3; $i++) {
            $idx = abs(crc32((string)((int)$this->cycleCount * 10 + $i))) % count($fields);
            $fields[$idx]['value'] = 'u_' . $this->cycleCount;
            $fields[$idx]['error'] = ((int)$this->cycleCount % 5 === $i) ? 'err' : '';
        }
        $this->formFields = $fields;
        $this->version = (string)((int)$this->version + 1);
        $this->markDirty();
    }

    // ── ChatStream ──
    public array $messages = [];
    public string $msgCount = '0';

    public function initChatStream(): void
    {
        $this->messages = [];
        $this->msgCount = '0';
        $this->markDirty();
    }

    public function runChatCycle(): void
    {
        $msgs = $this->messages;
        $authors = ['Alice','Bob','Charlie','Diana','Eve'];
        for ($i = 0; $i < 3; $i++) {
            $id = (string)(int)$this->msgCount;
            $author = $authors[abs(crc32($id)) % 5];
            $msgs[] = ['id' => $id, 'author' => $author, 'text' => 'msg #' . $id];
            $this->msgCount = (string)((int)$this->msgCount + 1);
        }
        $this->messages = $msgs;
        $this->markDirty();
    }

    // ── HoverGrid ──
    public string $hoveredIdx = '-1';
    public string $hoverCycle = '0';
    public array $hoverCells = [];

    public function initHoverGrid(): void
    {
        $cells = [];
        for ($i = 0; $i < 100; $i++) {
            $cells[] = ['id' => 'h' . $i, 'idx' => $i];
        }
        $this->hoverCells = $cells;
        $this->hoveredIdx = '-1';
        $this->hoverCycle = '0';
        $this->markDirty();
    }

    public function runHoverCycle(): void
    {
        $this->hoverCycle = (string)((int)$this->hoverCycle + 1);
        $this->hoveredIdx = (string)((int)$this->hoverCycle % 100);
        $this->markDirty();
    }

    // ── DynamicList ──
    public array $dynaWidgets = [];
    public string $dynaCount = '0';

    public function initDynamicList(): void
    {
        $w = [];
        for ($i = 0; $i < 50; $i++) {
            $w[] = ['id' => 'dl-' . $i, 'label' => 'W' . $i];
        }
        $this->dynaWidgets = $w;
        $this->dynaCount = '50';
        $this->markDirty();
    }

    public function runDynamicCycle(): void
    {
        $w = $this->dynaWidgets;
        array_shift($w);
        $idx = (int)$this->dynaCount;
        $w[] = ['id' => 'dl-' . $idx, 'label' => 'W' . $idx];
        $this->dynaCount = (string)($idx + 1);
        $this->dynaWidgets = $w;
        $this->markDirty();
    }

    // ── Case switching ──
    public function selectCase(string $case): void
    {
        $this->currentCase = $case;
        if ($case === 'ManyProps') {
            $this->otherCount = '999';
            $this->untrackedProps = [];
            $this->changeCount = 0;
            for ($i = 0; $i < 999; $i++) { $this->untrackedProps[] = $i; }
        }
        if ($case === 'MixedWorkload') { $this->prepareItems(); }
        if ($case === 'FormDashboard') { $this->initFormDashboard(); }
        if ($case === 'ChatStream') { $this->initChatStream(); }
        if ($case === 'HoverGrid') { $this->initHoverGrid(); }
        if ($case === 'DynamicList') { $this->initDynamicList(); }
        $this->markDirty();
    }
</script>
