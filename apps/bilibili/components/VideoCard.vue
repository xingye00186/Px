<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column;gap:6px;cursor:pointer;overflow:hidden;border-radius:6px">
    <!-- 封面区 -->
    <div style="width:100%;height:140px;border-radius:6px;background-size:cover;background-position:center" :style="'background:' . coverBg">
      <!-- 时长标 -->
      <div style="position:absolute;right:6px;bottom:6px;background:rgba(0,0,0,0.6);border-radius:3px;padding:1px 5px">
        <span style="font-size:11px;color:#FFFFFF">03:28</span>
      </div>
    </div>
    <!-- 标题 -->
    <div style="width:100%;height:20px;overflow:hidden">
      <span style="font-size:14px;color:#18191C;font-weight:500;text-overflow:ellipsis" container-w="100%">{{ title }}</span>
    </div>
    <!-- 播放量 + 评论数 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:12px">
      <span style="font-size:12px;color:#9499A0">▶ {{ playCount }}</span>
      <span style="font-size:12px;color:#9499A0">💬 {{ commentCount }}</span>
    </div>
    <!-- UP主 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:4px">
      <div style="width:18px;height:18px;background:#E3E5E7;border-radius:50%;display:flex;align-items:center;justify-content:center">
        <span style="font-size:10px;color:#9499A0">👤</span>
      </div>
      <span style="font-size:12px;color:#9499A0">{{ upName }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 封面背景色 */
    public string $coverBg = '#CCCCCC';

    /** 标题 */
    public string $title = '视频标题';

    /** 播放量 */
    public string $playCount = '0';

    /** 评论数 */
    public string $commentCount = '0';

    /** UP主名称 */
    public string $upName = 'UP主';
</script>

<style>
</style>
