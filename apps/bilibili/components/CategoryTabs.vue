<template>
  <div style="width:1440px;height:auto;background:#FFFFFF;border-bottom:1px solid #E3E5E7">
    <!-- 第一行 -->
    <div style="height:36px;display:flex;flex-direction:row;align-items:center;padding:0 24px;overflow-x:auto;gap:0">
      <div v-for="(cat, idx) in row1" :key="'r1_'+idx"
           style="height:34px;padding:0 14px;display:flex;align-items:center;cursor:pointer;white-space:nowrap;flex-shrink:0"
           @click="switchCategory" :click-arg="'r1_' + idx">
        <span style="font-size:14px" :style="getItemColor('r1_' + idx)">{{ cat.label }}</span>
        <span v-if="cat.more !== ''" style="font-size:10px;color:#9499A0;margin-left:2px">▾</span>
      </div>
    </div>
    <!-- 第二行 -->
    <div style="height:36px;display:flex;flex-direction:row;align-items:center;padding:0 24px;overflow-x:auto;gap:0;border-top:1px solid #F1F2F3">
      <div v-for="(cat, idx) in row2" :key="'r2_'+idx"
           style="height:34px;padding:0 14px;display:flex;align-items:center;cursor:pointer;white-space:nowrap;flex-shrink:0"
           @click="switchCategory" :click-arg="'r2_' + idx">
        <span style="font-size:14px" :style="getItemColor('r2_' + idx)">{{ cat.label }}</span>
        <span v-if="cat.more !== ''" style="font-size:10px;color:#9499A0;margin-left:2px">▾</span>
      </div>
    </div>
    <!-- 选中下划线 -->
    <div style="height:2px;background:#FB7299;width:40px;margin-left:24px;margin-top:-1px" :style="'margin-left:' . activeUnderlineLeft . 'px;width:' . activeUnderlineWidth . 'px'"></div>
  </div>
</template>

<script lang="php">

    /** 当前选中分类 */
    public string $activeKey = '';

    /** 下划线偏移 */
    public string $activeUnderlineLeft = '24';

    /** 下划线宽度 */
    public string $activeUnderlineWidth = '40';

    /** 第一行分类 */
    public array $row1 = [
        ['label' => '番剧', 'more' => ''],
        ['label' => '国创', 'more' => ''],
        ['label' => '综艺', 'more' => ''],
        ['label' => '动画', 'more' => ''],
        ['label' => '鬼畜', 'more' => ''],
        ['label' => '舞蹈', 'more' => ''],
        ['label' => '娱乐', 'more' => ''],
        ['label' => '科技数码', 'more' => ''],
        ['label' => '美食', 'more' => ''],
        ['label' => '汽车', 'more' => ''],
        ['label' => '体育运动', 'more' => ''],
        ['label' => '更多', 'more' => '1'],
    ];

    /** 第二行分类 */
    public array $row2 = [
        ['label' => '电影', 'more' => ''],
        ['label' => '电视剧', 'more' => ''],
        ['label' => '纪录片', 'more' => ''],
        ['label' => '游戏', 'more' => ''],
        ['label' => '音乐', 'more' => ''],
        ['label' => '知识', 'more' => ''],
        ['label' => '资讯', 'more' => ''],
        ['label' => '小剧场', 'more' => ''],
        ['label' => '时尚美妆', 'more' => ''],
        ['label' => '更多', 'more' => '1'],
    ];

    /** 获取分类文字颜色 */
    public function getItemColor(string $key): string
    {
        if ($key === $this->activeKey) {
            return 'color:#FB7299;font-weight:500';
        }
        return 'color:#18191C';
    }

    /** 切换分类 */
    public function switchCategory(string $key): void
    {
        $this->activeKey = $key;
        $this->markDirty();
    }
</script>

<style>
</style>
