<template>
  <div style="display:flex;flex-direction:column;gap:4px;padding:8px;width:100%">
    <!-- Header -->
    <div style="display:flex;justify-content:space-between;padding:4px 0">
      <span style="font-size:14px;color:#FFF">Profile Dashboard</span>
      <span style="font-size:12px;color:#FF9F0A">Version: {{ version }}</span>
    </div>

    <!-- Form fields -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <div v-for="field in fields" :key="field.id" style="display:flex;flex-direction:column;gap:2px;padding:4px;background:#2C2C2E">
        <span style="font-size:11px;color:#8E8E93">{{ field.label }}</span>
        <div :style="'height:24px;border:1px solid ' + (field.error ? '#FF4444' : '#505050') + ';border-radius:3px;display:flex;align-items:center;padding:0 6px'">
          <span :style="'font-size:13px;color:' + (field.error ? '#FF4444' : '#FFF')">{{ field.value }}</span>
        </div>
        <span v-if="field.error" style="font-size:10px;color:#FF4444">{{ field.error }}</span>
      </div>
    </div>

    <!-- Stats row -->
    <div style="display:flex;gap:6px;margin-top:4px">
      <div v-for="stat in stats" :key="stat.id" style="flex:1;padding:6px;background:#1C1C1E;text-align:center">
        <span :style="'font-size:18px;font-weight:bold;color:' + stat.color">{{ stat.value }}</span>
        <span style="display:block;font-size:10px;color:#8E8E93">{{ stat.label }}</span>
      </div>
    </div>

    <!-- Update button -->
    <div style="display:flex;gap:6px;margin-top:4px">
      <button v-for="btn in actionBtns" :key="btn.id" :style="'flex:1;height:28px;border:none;border-radius:4px;background:' + btn.bg + ';color:#FFF;font-size:12px;cursor:pointer'" @click="btn.action">{{ btn.label }}</button>
    </div>
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $version = '1.0';

    #[Reactive]
    public array $fields = [];

    #[Reactive]
    public array $stats = [];

    public array $actionBtns = [];

    public function initDashboard(): void
    {
        $this->fields = [];
        $labels = ['Username', 'Email', 'Phone', 'Age', 'Address', 'City', 'Country', 'Score', 'Level', 'Status'];
        for ($i = 0; $i < 10; $i++) {
            $this->fields[] = ['id' => (string)$i, 'label' => $labels[$i] ?? 'Field' . $i, 'value' => 'val_' . $i, 'error' => ''];
        }
        $this->stats = [
            ['id' => 's1', 'label' => 'Total', 'value' => '0', 'color' => '#FF9F0A'],
            ['id' => 's2', 'label' => 'Active', 'value' => '0', 'color' => '#30D158'],
            ['id' => 's3', 'label' => 'Errors', 'value' => '0', 'color' => '#FF4444'],
        ];
        $this->actionBtns = [
            ['id' => 'b1', 'label' => 'Validate', 'bg' => '#FF9F0A', 'action' => 'validateAll'],
            ['id' => 'b2', 'label' => 'Refresh', 'bg' => '#505050', 'action' => 'refreshAll'],
        ];
        $this->version = '1.0.' . time();
    }

    public function simulateUpdate(): void
    {
        // 模拟 5 个字段的值变化
        $fields = $this->fields;
        for ($i = 0; $i < 5; $i++) {
            $idx = mt_rand(0, count($fields) - 1);
            if (isset($fields[$idx])) {
                $fields[$idx]['value'] = 'v_' . mt_rand(100, 999);
                // 随机产生错误
                $fields[$idx]['error'] = (mt_rand(0, 4) === 0) ? 'Invalid' : '';
            }
        }
        $this->fields = $fields;

        // 更新统计
        $stats = $this->stats;
        $total = (int)$stats[0]['value'] + 1;
        $stats[0]['value'] = (string)$total;
        $stats[1]['value'] = (string)floor($total * 0.7);
        $errCount = 0;
        foreach ($this->fields as $f) { if ($f['error'] !== '') $errCount++; }
        $stats[2]['value'] = (string)$errCount;
        $this->stats = $stats;

        $this->version = '1.0.' . $total;
    }
</script>
