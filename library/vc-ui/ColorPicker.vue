<template>
  <div style="position:relative;width:240px;height:36px" class="colorpicker-wrapper">
    <div style="position:absolute;left:0px;top:0px;width:240px;height:36px" class="colorpicker-trigger" @click="toggleOpen">
      <div style="position:absolute;left:8px;top:6px;width:24px;height:24px" class="color-swatch" :style="'background:' + modelValue"></div>
      <span style="position:absolute;left:40px;top:8px;width:180px;height:20px;font-size:14px" class="colorpicker-value">{{ modelValue }}</span>
      <span style="position:absolute;right:8px;top:10px;width:16px;height:16px" class="colorpicker-arrow">▼</span>
    </div>
    <div v-if="isOpen === '1'" style="position:absolute;left:0px;top:40px;width:240px;height:220px" class="colorpicker-panel">
      <!-- 预定义色块 -->
      <div style="position:absolute;left:0px;top:0px;width:240px;height:160px" class="color-grid">
        <div v-for="c in presetColors" :key="c.hex" style="position:absolute;left:0px;top:0px;width:40px;height:30px" :style="'background:' + c.hex + ';left:' + c.x + 'px;top:' + c.y + 'px'" class="color-preset" @click="selectColor(c.hex)"></div>
      </div>
      <!-- 当前颜色 -->
      <div style="position:absolute;left:0px;top:160px;width:240px;height:60px" class="color-preview">
        <div style="position:absolute;left:12px;top:12px;width:60px;height:36px" class="preview-swatch" :style="'background:' + modelValue"></div>
        <span style="position:absolute;left:80px;top:16px;width:140px;height:16px;font-size:12px;color:#606266">{{ modelValue }}</span>
        <div style="position:absolute;left:12px;top:36px;width:216px;height:20px" class="preview-hex">
          <span style="position:absolute;left:0px;top:2px;width:80px;height:16px;font-size:12px;color:#909399">HEX:</span>
          <span style="position:absolute;left:40px;top:2px;width:176px;height:16px;font-size:12px;color:#303133">{{ modelValue }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中颜色 (v-model, HEX) */
    public string $modelValue = '#409EFF';

    /** 是否展开 */
    public string $isOpen = '';

    /** 预定义颜色列表 */
    public array $presetColors = ['#FFFFFF','#F2F2F2','#D8D8D8','#BFBFBF','#999999','#666666','#333333','#000000','#FF0000','#FF7F00','#FFFF00','#00FF00','#00FFFF','#0000FF','#8B00FF','#FF00FF','#FFF0F5','#F0FFF0','#F0F8FF','#FAF0E6'];

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
        $this->markDirty();
    }

    /**
     * 选择颜色
     */
    public function selectColor(string $hex): void
    {
        $this->modelValue = $hex;
        $this->isOpen = '';
        $this->markDirty();
    }

    /**
     * 获取预定义颜色网格
     */
    public function getPresetColors(): array
    {
        $colors = $this->presetColors;
        $result = [];
        $col = 0;
        $row = 0;
        foreach ($colors as $hex) {
            $result[] = [
                'hex' => $hex,
                'x' => $col * 40,
                'y' => $row * 30
            ];
            $col++;
            if ($col >= 6) {
                $col = 0;
                $row++;
            }
        }
        return $result;
    }
</script>

<style>
.colorpicker-wrapper { background: transparent; }
.colorpicker-trigger { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #DCDFE6; }
.color-swatch { border: 1px solid #DCDFE6; }
.colorpicker-value { color: #606266; font-size: 14px; }
.colorpicker-arrow { color: #C0C4CC; font-size: 10px; }
.colorpicker-panel { background: #FFFFFF; border: 1px solid #DCDFE6; box-shadow: 0 2px 12px rgba(0,0,0,0.15); z-index: 1000; position: absolute; }
.color-grid { position: relative; background: #FFFFFF; }
.color-preset { border: 1px solid #E8E8E8; cursor: pointer; }
.color-preview { background: #F5F7FA; border-top: 1px solid #E8E8E8; }
.preview-swatch { border: 1px solid #DCDFE6; }
</style>