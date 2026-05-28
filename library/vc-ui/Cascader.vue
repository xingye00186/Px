<template>
  <div style="width:240px;height:36px" class="cascader-wrapper">
    <div style="left:0px;top:0px;width:240px;height:36px" class="cascader-trigger" @click="toggleOpen">
      <span style="left:12px;top:8px;width:200px;height:20px;font-size:14px" class="cascader-value">{{ displayText }}</span>
      <span style="right:8px;top:10px;width:16px;height:16px" class="cascader-arrow">▼</span>
    </div>
    <div v-if="isOpen === '1'" style="left:0px;top:36px;width:400px;height:auto;min-height:40px" class="cascader-panel">
      <div style="left:0px;top:0px;width:400px;height:200px" class="cascader-menus">
        <div style="left:0px;top:0px;width:120px;height:200px" class="cascader-menu">
          <div v-for="opt in level0Options" :key="opt.value" style="left:0px;top:0px;width:120px;height:32px" :class="opt.cls" @click="selectL0(opt)">{{ opt.label }}</div>
        </div>
        <div v-if="selectedL0 !== ''" style="left:120px;top:0px;width:120px;height:200px" class="cascader-menu">
          <div v-for="opt in level1Options" :key="opt.value" style="left:0px;top:0px;width:120px;height:32px" :class="opt.cls" @click="selectL1(opt)">{{ opt.label }}</div>
        </div>
        <div v-if="selectedL1 !== ''" style="left:240px;top:0px;width:160px;height:200px" class="cascader-menu">
          <div v-for="opt in level2Options" :key="opt.value" style="left:0px;top:0px;width:160px;height:32px" :class="opt.cls" @click="selectL2(opt)">{{ opt.label }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中值 (v-model, JSON 数组) */
    public string $modelValue = '';

    /** 选项数据 (树形结构) */
    public array $options = [["label"=>"Province","value"=>"p","children"=>[["label"=>"City","value"=>"c","children"=>[["label"=>"District","value"=>"d"]]]]]];

    /** 占位符 */
    public string $placeholder = 'Please select...';

    /** 是否可清空 */
    public string $clearable = '1';

    /** 是否展开 */
    public string $isOpen = '';

    /** 已选一级 */
    public string $selectedL0 = '';

    /** 已选二级 */
    public string $selectedL1 = '';

    /**
     * 切换展开
     */
    public function toggleOpen(): void
    {
        if ($this->isOpen === '1') {
            $this->isOpen = '';
        } else {
            $this->isOpen = '1';
        }
    }

    /**
     * 选择一级
     */
    public function selectL0(array $opt): void
    {
        $this->selectedL0 = $opt['value'] ?? '';
        $this->selectedL1 = '';
        $this->modelValue = json_encode([$this->selectedL0]);
    }

    /**
     * 选择二级
     */
    public function selectL1(array $opt): void
    {
        $this->selectedL1 = $opt['value'] ?? '';
        $this->modelValue = json_encode([$this->selectedL0, $this->selectedL1]);
    }

    /**
     * 选择三级
     */
    public function selectL2(array $opt): void
    {
        $vals = json_decode($this->modelValue, true);
        if (!is_array($vals)) $vals = [];
        $vals[] = $opt['value'] ?? '';
        $this->modelValue = json_encode($vals);
        $this->isOpen = '';
    }

    /**
     * 获取显示文字
     */
    public function getDisplayText(): string
    {
        $vals = json_decode($this->modelValue, true);
        if (!is_array($vals) || count($vals) === 0) {
            return $this->placeholder;
        }
        $opts = $this->options;
        $labels = [];
        $current = $opts;
        foreach ($vals as $v) {
            $found = false;
            if (is_array($current)) {
                foreach ($current as $item) {
                    if (($item['value'] ?? '') === $v) {
                        $labels[] = $item['label'] ?? '';
                        $current = $item['children'] ?? [];
                        $found = true;
                        break;
                    }
                }
            }
            if (!$found) break;
        }
        return implode(' / ', $labels) ?: $this->placeholder;
    }

    /**
     * 获取一级选项
     */
    public function getLevel0Options(): array
    {
        $opts = $this->options;
        $result = [];
        foreach ($opts as $item) {
            $v = $item['value'] ?? '';
            $cls = ($v === $this->selectedL0) ? 'cascader-item cascader-item-active' : 'cascader-item';
            $result[] = ['value' => $v, 'label' => ($item['label'] ?? ''), 'cls' => $cls];
        }
        return $result;
    }

    /**
     * 获取二级选项
     */
    public function getLevel1Options(): array
    {
        $opts = $this->options;
        $result = [];
        foreach ($opts as $item) {
            if (($item['value'] ?? '') === $this->selectedL0) {
                $children = $item['children'] ?? [];
                if (is_array($children)) {
                    foreach ($children as $child) {
                        $v = $child['value'] ?? '';
                        $cls = ($v === $this->selectedL1) ? 'cascader-item cascader-item-active' : 'cascader-item';
                        $result[] = ['value' => $v, 'label' => ($child['label'] ?? ''), 'cls' => $cls];
                    }
                }
                break;
            }
        }
        return $result;
    }

    /**
     * 获取三级选项
     */
    public function getLevel2Options(): array
    {
        $opts = $this->options;
        $result = [];
        foreach ($opts as $item) {
            if (($item['value'] ?? '') === $this->selectedL0) {
                $children = $item['children'] ?? [];
                if (is_array($children)) {
                    foreach ($children as $child) {
                        if (($child['value'] ?? '') === $this->selectedL1) {
                            $grandchildren = $child['children'] ?? [];
                            if (is_array($grandchildren)) {
                                foreach ($grandchildren as $gc) {
                                    $v = $gc['value'] ?? '';
                                    $cls = 'cascader-item';
                                    $result[] = ['value' => $v, 'label' => ($gc['label'] ?? ''), 'cls' => $cls];
                                }
                            }
                        }
                    }
                }
                break;
            }
        }
        return $result;
    }
</script>

<style>
.cascader-wrapper { background: transparent; }
.cascader-trigger { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #DCDFE6; }
.cascader-value { color: #606266; font-size: 14px; }
.cascader-arrow { color: #C0C4CC; font-size: 10px; }
.cascader-panel { background: #FFFFFF; border: 1px solid #DCDFE6; box-shadow: 0 2px 12px rgba(0,0,0,0.15); z-index: 1000; position: absolute; }
.cascader-menus { display: flex; }
.cascader-menu { background: #FFFFFF; border-right: 1px solid #E8E8E8; overflow-y: auto; }
.cascader-item { color: #606266; font-size: 14px; line-height: 32px; padding-left: 12px; background: #FFFFFF; }
.cascader-item:hover { background: #F5F7FA; color: #409EFF; }
.cascader-item-active { background: #ECF5FF; color: #409EFF; font-weight: bold; }
</style>