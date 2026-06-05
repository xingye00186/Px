<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column;gap:6px;cursor:pointer;background:#FFFFFF;border-radius:6px;overflow:hidden">
    <!-- 封面区 -->
    <div style="width:100%;height:140px;border-radius:6px;background-size:cover;background-position:center;position:relative" :style="'background:' . coverBg">
      <!-- 时长标 -->
      <div style="position:absolute;right:6px;bottom:6px;background:rgba(0,0,0,0.7);border-radius:4px;padding:2px 6px">
        <span style="font-size:12px;color:#FFFFFF">{{ duration }}</span>
      </div>
    </div>
    <!-- 标题（最多2行） -->
    <div style="width:100%;overflow:hidden">
      <span style="font-size:14px;color:#18191C;font-weight:500;text-overflow:ellipsis;-webkit-line-clamp:2;line-height:20px" container-w="100%">{{ title }}</span>
    </div>
    <!-- 播放量 + 点赞数 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:12px">
      <span style="font-size:12px;color:#9499A0">▶ {{ playCount }}</span>
      <span style="font-size:12px;color:#9499A0">👍 {{ likeCount }}</span>
    </div>
    <!-- UP主 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:4px">
      <div style="width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center" :style="'background:' . upAvatar">
        <span style="font-size:10px;color:#FFFFFF">{{ upInitial }}</span>
      </div>
      <span style="font-size:12px;color:#9499A0">{{ upName }}</span>
    </div>
    <!-- 上传日期 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:6px">
      <span style="font-size:12px;color:#C0C4CC">{{ date }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 封面背景色 */
    public string $coverBg = '#CCCCCC';

    /** 标题 */
    public string $title = '视频标题';

    /** 时长 */
    public string $duration = '03:28';

    /** 播放量 */
    public string $playCount = '0';

    /** 点赞/弹幕数 */
    public string $likeCount = '0';

    /** UP主名称 */
    public string $upName = 'UP主';

    /** UP主头像背景色 */
    public string $upAvatar = '#409EFF';

    /** 上传日期 */
    public string $date = '刚刚';

    /** 获取首字符 */
    public function getUpInitial(): string
    {
        $name = $this->upName;
        if ($name === '') return 'U';
        return mb_substr($name, 0, 1);
    }
</script>

<style>
</style>
