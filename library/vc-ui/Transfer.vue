<template>
  <div style="width:480px;height:240px" class="transfer-wrapper">
    <!-- 源列表 -->
    <div style="left:0px;top:0px;width:200px;height:240px" class="transfer-panel">
      <div style="left:0px;top:0px;width:200px;height:32px" class="panel-header">
        <span style="left:12px;top:8px;width:160px;height:16px;font-size:14px;font-weight:bold;color:#303133">{{ leftTitle }}</span>
      </div>
      <div style="left:0px;top:32px;width:200px;height:208px" class="panel-list">
        <div v-for="item in leftItems" :key="item.value" style="left:0px;top:0px;width:200px;height:32px" class="panel-item" @click="moveToRight(item)">
          <span style="left:8px;top:6px;width:16px;height:20px;font-size:12px;color:#C0C4CC">{{ item.checked ? '☑' : '☐' }}</span>
          <span style="left:32px;top:6px;width:160px;height:20px;font-size:13px;color:#606266">{{ item.label }}</span>
        </div>
      </div>
    </div>
    <!-- 操作按钮 -->
    <div style="left:200px;top:0px;width:80px;height:240px" class="transfer-buttons">
      <div style="left:20px;top:90px;width:40px;height:28px" class="btn-add" @click="addAll">→</div>
      <div style="left:20px;top:124px;width:40px;height:28px" class="btn-remove" @click="removeAll">←</div>
    </div>
    <!-- 目标列表 -->
    <div style="left:280px;top:0px;width:200px;height:240px" class="transfer-panel">
      <div style="left:0px;top:0px;width:200px;height:32px" class="panel-header">
        <span style="left:12px;top:8px;width:160px;height:16px;font-size:14px;font-weight:bold;color:#303133">{{ rightTitle }}</span>
      </div>
      <div style="left:0px;top:32px;width:200px;height:208px" class="panel-list">
        <div v-for="item in rightItems" :key="item.value" style="left:0px;top:0px;width:200px;height:32px" class="panel-item" @click="moveToLeft(item)">
          <span style="left:8px;top:6px;width:16px;height:20px;font-size:12px;color:#C0C4CC">☐</span>
          <span style="left:32px;top:6px;width:160px;height:20px;font-size:13px;color:#606266">{{ item.label }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 可选数据 (JSON) */
    public string $data = '[{"label":"Option 1","value":"1"},{"label":"Option 2","value":"2"},{"label":"Option 3","value":"3"},{"label":"Option 4","value":"4"}]';

    /** 选中值 (v-model, JSON 数组) */
    public string $modelValue = '[]';

    /** 左列表标题 */
    public string $leftTitle = 'Source';

    /** 右列表标题 */
    public string $rightTitle = 'Target';

    /** 是否可搜索 */
    public string $filterable = '';

    /**
     * 移动到右侧
     */
    public function moveToRight(array $item): void
    {
        $vals = json_decode($this->modelValue, true);
        if (!is_array($vals)) $vals = [];
        $vals[] = $item['value'] ?? '';
        $this->modelValue = json_encode(array_values(array_unique($vals)));
    }

    /**
     * 移动到左侧
     */
    public function moveToLeft(array $item): void
    {
        $vals = json_decode($this->modelValue, true);
        if (!is_array($vals)) $vals = [];
        $vals = array_filter($vals, fn($v) => $v !== ($item['value'] ?? ''));
        $this->modelValue = json_encode(array_values($vals));
    }

    /**
     * 全加
     */
    public function addAll(): void
    {
        $allData = json_decode($this->data, true);
        if (!is_array($allData)) return;
        $vals = array_column($allData, 'value');
        $this->modelValue = json_encode(array_values(array_unique($vals)));
    }

    /**
     * 全移除
     */
    public function removeAll(): void
    {
        $this->modelValue = '[]';
    }

    /**
     * 获取左侧列表
     */
    public function getLeftItems(): array
    {
        $allData = json_decode($this->data, true);
        $vals = json_decode($this->modelValue, true);
        if (!is_array($allData)) return [];
        if (!is_array($vals)) $vals = [];
        $result = [];
        foreach ($allData as $item) {
            if (!in_array($item['value'] ?? '', $vals)) {
                $result[] = ['value' => ($item['value'] ?? ''), 'label' => ($item['label'] ?? ''), 'checked' => false];
            }
        }
        return $result;
    }

    /**
     * 获取右侧列表
     */
    public function getRightItems(): array
    {
        $allData = json_decode($this->data, true);
        $vals = json_decode($this->modelValue, true);
        if (!is_array($allData) || !is_array($vals)) return [];
        $result = [];
        foreach ($allData as $item) {
            if (in_array($item['value'] ?? '', $vals)) {
                $result[] = ['value' => ($item['value'] ?? ''), 'label' => ($item['label'] ?? '')];
            }
        }
        return $result;
    }
</script>

<style>
.transfer-wrapper { background: transparent; display: flex; }
.transfer-panel { background: #FFFFFF; border: 1px solid #DCDFE6; }
.panel-header { background: #F5F7FA; border-bottom: 1px solid #E8E8E8; }
.panel-list { overflow-y: auto; background: #FFFFFF; }
.panel-item { cursor: pointer; background: #FFFFFF; border-bottom: 1px solid #F0F0F0; }
.panel-item:hover { background: #ECF5FF; }
.transfer-buttons { display: flex; flex-direction: column; justify-content: center; background: transparent; }
.btn-add { background: #409EFF; color: #FFFFFF; font-size: 14px; text-align: center; line-height: 28px; cursor: pointer; }
.btn-add:hover { background: #66B1FF; }
.btn-remove { background: #F56C6C; color: #FFFFFF; font-size: 14px; text-align: center; line-height: 28px; cursor: pointer; }
.btn-remove:hover { background: #F78989; }
</style>