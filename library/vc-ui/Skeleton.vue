<template>
  <div style="width:100%;height:100%">
    <!-- 骨架方块列表 -->
    <div v-for="i in itemCount" :key="i" :style="'width:' . ($widthArr[$i] ?? 100) . 'px;height:' . $itemHeight . 'px'" class="skeleton-item"></div>
  </div>
</template>

<script lang="php">

    /** 骨架项数量 */
    public string $rows = '5';

    /** 每项高度 */
    public string $itemHeight = '16';

    /** 每项宽度数组 */
    public array $widthArrJson = [100, 80, 60, 100, 70];

    /**
     * 获取骨架项数量
     */
    public function getItemCount(): int
    {
        $n = (int)$this->rows;
        if ($n <= 0) $n = 5;
        if ($n > 20) $n = 20;
        return $n;
    }

    /**
     * 获取宽度数组
     */
    public function getWidthArr(): array
    {
        return $this->widthArrJson;
    }
</script>

<style>
.skeleton-item { background: #F2F6FC; margin-bottom: 8px; }
</style>