<template>
  <div style="position:relative;width:100%;height:auto;min-height:100px" class="infinite-scroll-wrapper">
    <div style="position:absolute;left:0px;top:0px;width:100%;height:auto" class="scroll-content">
      <slot></slot>
    </div>
    <!-- 加载中提示 -->
    <div v-if="loading === '1'" style="position:absolute;left:0px;top:0px;width:100%;height:32px" class="loading-tip">
      <span style="position:absolute;left:0px;top:8px;width:100%;height:16px;font-size:12px;color:#909399;text-align:center">Loading more...</span>
    </div>
    <!-- 没有更多提示 -->
    <div v-if="hasMore === '0'" style="position:absolute;left:0px;top:0px;width:100%;height:32px" class="no-more-tip">
      <span style="position:absolute;left:0px;top:8px;width:100%;height:16px;font-size:12px;color:#C0C4CC;text-align:center">No more data</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 是否正在加载 */
    public string $loading = '';

    /** 是否还有更多 */
    public string $hasMore = '1';

    /** 加载距离阈值 (px) */
    public string $threshold = '100';

    /** 触发加载回调 */
    public string $onLoadMore = '';

    /**
     * 检查是否触底
     * 需要父组件绑定 scroll-top 并在滚动时调用此方法
     */
    public function checkLoadMore(string $scrollTop, string $clientHeight, string $scrollHeight): void
    {
        $top = (int)$scrollTop;
        $ch = (int)$clientHeight;
        $sh = (int)$scrollHeight;
        $thresh = (int)$this->threshold;
        if ($top + $ch >= $sh - $thresh && $this->hasMore === '1' && $this->loading !== '1') {
            $this->loading = '1';
        }
    }

    /**
     * 加载完成
     */
    public function onLoadComplete(): void
    {
        $this->loading = '';
    }

    /**
     * 设置没有更多
     */
    public function setNoMore(): void
    {
        $this->hasMore = '0';
    }
</script>

<style>
.infinite-scroll-wrapper { background: transparent; }
.scroll-content { background: transparent; }
.loading-tip { background: transparent; }
.no-more-tip { background: transparent; }
</style>