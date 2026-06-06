<template>
  <div style="width:1440px;height:56px;background:#FFFFFF;display:flex;flex-direction:row;align-items:center;padding:0 24px;border-bottom:1px solid #E3E5E7">
    <!-- Logo + 导航链接 -->
    <div style="display:flex;align-items:center;gap:20px;margin-right:40px">
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:32px;height:32px;background:#FB7299;border-radius:6px;display:flex;align-items:center;justify-content:center">
          <span style="font-size:16px;font-weight:bold;color:#FFFFFF">B</span>
        </div>
        <span style="font-size:20px;font-weight:bold;color:#18191C">bilibili</span>
      </div>
      <!-- 顶部导航链接 -->
      <div style="display:flex;flex-direction:row;align-items:center;gap:16px">
        <span style="font-size:14px;color:#18191C;font-weight:500;cursor:pointer">首页</span>
        <span style="font-size:14px;color:#61666D;cursor:pointer">番剧</span>
        <span style="font-size:14px;color:#61666D;cursor:pointer">直播</span>
        <span style="font-size:14px;color:#61666D;cursor:pointer">游戏中心</span>
        <span style="font-size:14px;color:#61666D;cursor:pointer">会员购</span>
        <span style="font-size:14px;color:#61666D;cursor:pointer">漫画</span>
        <span style="font-size:14px;color:#61666D;cursor:pointer">赛事</span>
        <span style="font-size:14px;color:#61666D;cursor:pointer">下载客户端</span>
      </div>
    </div>

    <!-- 搜索框 -->
    <div style="flex:1;max-width:400px;margin-left:40px">
      <vc-input prefix-icon="search" placeholder="搜索视频、UP主、分区..." />
    </div>

    <!-- 右侧操作区 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:12px;margin-left:auto">
      <span style="font-size:13px;color:#FB7299;font-weight:500;cursor:pointer">大会员</span>
      <!-- 通知铃铛 -->
      <div style="position:relative;display:inline-flex;align-items:center;cursor:pointer">
        <span style="font-size:16px;color:#61666D">🔔</span>
        <div style="position:absolute;right:-10px;top:-4px;min-width:16px;height:16px;background:#F56C6C;color:#FFFFFF;font-size:10px;border-radius:8px;text-align:center;line-height:16px;padding:0 4px">4</div>
      </div>
      <!-- 私信 -->
      <div style="position:relative;display:inline-flex;align-items:center;cursor:pointer">
        <span style="font-size:16px;color:#61666D">✉</span>
        <div style="position:absolute;right:-10px;top:-4px;min-width:16px;height:16px;background:#F56C6C;color:#FFFFFF;font-size:10px;border-radius:8px;text-align:center;line-height:16px;padding:0 4px">19</div>
      </div>
      <span style="font-size:13px;color:#61666D;cursor:pointer">动态</span>
      <span style="font-size:13px;color:#61666D;cursor:pointer">收藏</span>
      <span style="font-size:13px;color:#61666D;cursor:pointer">历史</span>
      <span style="font-size:13px;color:#61666D;cursor:pointer">创作中心</span>
      <!-- 投稿按钮 -->
      <vc-button type="primary" bg="#FB7299" size="small" text="投稿" />
      <!-- 用户头像 -->
      <vc-avatar size="32" text="U" />
    </div>
  </div>
</template>

<script lang="php">

class NavBarComponent extends ReactiveComponent
{
}
</script>

<style>
</style>
