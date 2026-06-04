<template>
  <div style="width:1440px;height:56px;background:#FFFFFF;display:flex;flex-direction:row;align-items:center;padding:0 24px;border-bottom:1px solid #E3E5E7">
    <!-- Logo -->
    <div style="display:flex;align-items:center;gap:8px;margin-right:40px">
      <div style="width:32px;height:32px;background:#FB7299;border-radius:6px;display:flex;align-items:center;justify-content:center">
        <span style="font-size:16px;font-weight:bold;color:#FFFFFF">B</span>
      </div>
      <span style="font-size:20px;font-weight:bold;color:#18191C">哔哩哔哩</span>
    </div>

    <!-- 搜索框 -->
    <div style="flex:1;max-width:400px;height:36px;background:#F6F7F8;border:1px solid #E3E5E7;border-radius:6px;display:flex;align-items:center;padding:0 12px">
      <span style="font-size:14px;color:#C0C4CC;margin-right:8px">🔍</span>
      <span style="font-size:14px;color:#C0C4CC;flex:1">搜索视频、UP主、分区...</span>
    </div>

    <!-- 右侧操作区 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:16px;margin-left:auto">
      <!-- 投稿按钮 -->
      <div style="width:80px;height:32px;background:#FB7299;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer">
        <span style="font-size:12px;color:#FFFFFF;font-weight:500">投稿</span>
      </div>
      <!-- 通知 -->
      <div style="width:20px;height:20px;font-size:18px;text-align:center;cursor:pointer">🔔</div>
      <!-- 历史 -->
      <div style="width:20px;height:20px;font-size:18px;text-align:center;cursor:pointer">🕐</div>
      <!-- 用户头像 -->
      <div style="width:32px;height:32px;background:#E3E5E7;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer">
        <span style="font-size:14px;color:#9499A0">👤</span>
      </div>
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
