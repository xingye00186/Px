<template>
  <div style="width:auto;height:32px" class="pagination-wrapper">
    <!-- 上一页 -->
    <span style="width:32px;height:32px" class="page-btn" @click="onPrev">‹</span>
    <!-- 页码按钮 -->
    <span v-for="p in pageButtons" :key="p" :style="'width:32px;height:32px'" :class="pageBtnClass(p)" @click="onGo(p)">{{ p }}</span>
    <!-- 下一页 -->
    <span style="width:32px;height:32px" class="page-btn" @click="onNext">›</span>
    <!-- 总数 -->
    <span style="font-size:14px;color:#606266" class="page-total">Total: {{ total }}</span>
  </div>
</template>

<script lang="php">

    /** 当前页 (v-model) */
    public string $current = '1';

    /** 总条数 */
    public string $total = '0';

    /** 每页条数 */
    public string $pageSize = '10';

    /**
     * 获取总页数
     */
    public function getTotalPages(): int
    {
        $t = max(0, (int)$this->total);
        $s = max(1, (int)$this->pageSize);
        if ($t <= 0) return 1;
        return (int)ceil($t / $s);
    }

    /**
     * 获取页码按钮数组
     */
    public function getPageButtons(): array
    {
        $total = $this->getTotalPages();
        $current = max(1, min($total, (int)$this->current));
        if ($total <= 7) {
            $arr = range(1, max(1, $total));
            return $arr;
        }
        $arr = [1];
        if ($current > 3) $arr[] = '...';
        for ($i = max(2, $current - 1); $i <= min($total - 1, $current + 1); $i++) {
            $arr[] = $i;
        }
        if ($current < $total - 2) $arr[] = '...';
        $arr[] = $total;
        return $arr;
    }

    /**
     * 获取页码按钮 class
     */
    public function getPageBtnClass(string $p): string
    {
        $cur = (int)$this->current;
        if ((string)(int)$p === $p && (int)$p === $cur) {
            return 'page-btn page-btn-active';
        }
        return 'page-btn';
    }

    /**
     * 上一页
     */
    public function onPrev(): void
    {
        $cur = (int)$this->current;
        if ($cur > 1) {
            $this->current = (string)($cur - 1);
        }
    }

    /**
     * 下一页
     */
    public function onNext(): void
    {
        $cur = (int)$this->current;
        $total = $this->getTotalPages();
        if ($cur < $total) {
            $this->current = (string)($cur + 1);
        }
    }

    /**
     * 跳转到指定页
     */
    public function onGo(string $p): void
    {
        $v = (int)$p;
        $total = $this->getTotalPages();
        if ($v >= 1 && $v <= $total) {
            $this->current = (string)$v;
        }
    }
</script>

<style>
.pagination-wrapper { background: transparent; }
.page-btn { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #DCDFE6; text-align: center; }
.page-btn-active { background: #409EFF; color: #FFFFFF; border: 1px solid #409EFF; }
.page-total { color: #909399; font-size: 14px; }
</style>