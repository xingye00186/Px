<template>
  <div style="width:1440px;height:40px;background:#FFFFFF;display:flex;flex-direction:row;align-items:center;padding:0 24px;border-bottom:1px solid #E3E5E7;overflow-x:auto;gap:0">
    <div v-for="(cat, idx) in categories" :key="idx"
         style="height:38px;padding:0 16px;display:flex;align-items:center;cursor:pointer;white-space:nowrap;flex-shrink:0"
         :style="idx === activeIndex ? 'border-bottom:2px solid #FB7299' : ''"
         @click="switchCategory" :click-arg="idx">
      <span style="font-size:14px" :style="idx === activeIndex ? 'color:#FB7299;font-weight:500' : 'color:#18191C'">{{ cat }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 当前选中分类索引 */
    public string $activeIndex = '0';

    /** 分类列表 */
    public array $categories = ['推荐','热门','娱乐','游戏','音乐','知识','动画','影视','生活','科技','舞蹈','鬼畜','美食','时尚','运动'];

    /** 获取分类列表 */
    public function getCategoryList(): array
    {
        return $this->categories;
    }

    /** 切换分类 */
    public function switchCategory(string $idx): void
    {
        $this->activeIndex = $idx;
        $this->markDirty();
    }
</script>

<style>
</style>
