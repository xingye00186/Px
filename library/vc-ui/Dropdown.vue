<template>
  <div style="position:relative;display:inline-flex;flex-direction:column">
    <!-- 触发区域 -->
    <div style="cursor:pointer;display:flex;align-items:center" @click="toggleDropdown">
      <slot />
      <span style="font-size:10px;margin-left:4px;color:#9499A0">▾</span>
    </div>
    <!-- 下拉面板 -->
    <div v-if="isVisible === '1'"
         style="position:absolute;top:100%;left:0;min-width:120px;background:#FFFFFF;border:1px solid #E4E7ED;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.12);z-index:1000;padding:4px 0"
         :style="'left:' . dropLeft . 'px'">
      <div v-for="(item, idx) in menuItems" :key="idx"
           :style="getItemStyle(item)"
           @click="selectItem" :click-arg="item.key">
        <span>{{ item.label }}</span>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 触发方式: click / hover */
    public string $trigger = 'click';

    /** 菜单项数组: [['key'=>'home','label'=>'首页'], ...] */
    public array $items = [];

    /** 对齐方向: bottomLeft / bottomRight */
    public string $placement = 'bottomLeft';

    /** 是否可见 */
    public string $isVisible = '';

    /** 左偏移（用于 bottomRight 对齐） */
    public string $dropLeft = '0';

    /**
     * 获取渲染用的菜单项（过滤空值）
     */
    public function getMenuItems(): array
    {
        $result = [];
        foreach ($this->items as $item) {
            if (isset($item['divider']) && $item['divider'] === true) {
                $result[] = ['key' => '', 'label' => '', 'divider' => true];
            } else {
                $result[] = [
                    'key' => $item['key'] ?? '',
                    'label' => $item['label'] ?? '',
                    'divider' => false,
                ];
            }
        }
        return $result;
    }

    /**
     * 获取单个菜单项样式
     */
    public function getItemStyle(array $item): string
    {
        if (isset($item['divider']) && $item['divider'] === true) {
            return 'height:1px;background:#E4E7ED;margin:4px 0;padding:0';
        }
        return 'padding:6px 16px;font-size:13px;color:#18191C;cursor:pointer';
    }

    /**
     * 切换下拉菜单显示
     */
    public function toggleDropdown(): void
    {
        $this->isVisible = $this->isVisible === '1' ? '' : '1';
        if ($this->isVisible === '1' && $this->placement === 'bottomRight') {
            // bottomRight 对齐：计算宽度
            $this->dropLeft = '-80';
        } else {
            $this->dropLeft = '0';
        }
        $this->markDirty();
    }

    /**
     * 选择菜单项
     */
    public function selectItem(string $key): void
    {
        if ($key === '') return; // 分隔线
        $this->emit('select', $key);
        $this->isVisible = '';
        $this->markDirty();
    }
</script>

<style>
</style>
