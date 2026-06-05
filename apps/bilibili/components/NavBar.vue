<template>
  <div style="width:1440px;height:56px;background:#FFFFFF;display:flex;flex-direction:row;align-items:center;padding:0 24px;border-bottom:1px solid #E3E5E7">
    <!-- Logo + Dropdown -->
    <div style="display:flex;align-items:center;gap:8px;margin-right:40px">
      <div style="width:32px;height:32px;background:#FB7299;border-radius:6px;display:flex;align-items:center;justify-content:center">
        <span style="font-size:16px;font-weight:bold;color:#FFFFFF">B</span>
      </div>
      <span style="font-size:20px;font-weight:bold;color:#18191C">bilibili</span>
      <vc-dropdown :items="dropItems" placement="bottomLeft">
        <span style="font-size:10px;color:#9499A0">▾</span>
      </vc-dropdown>
    </div>

    <!-- 搜索框 -->
    <div style="flex:1;max-width:400px;margin-left:40px">
      <vc-input prefix-icon="search" placeholder="搜索视频、UP主、分区..." />
    </div>

    <!-- 右侧操作区 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:16px;margin-left:auto">
      <!-- 投稿按钮 -->
      <vc-button type="primary" bg="#FB7299" size="small" text="投稿" />
      <!-- 通知 Badge（内联实现） -->
      <div style="position:relative;display:inline-flex;align-items:center">
        <div style="width:20px;height:20px;font-size:18px;text-align:center;cursor:pointer">🔔</div>
        <div style="position:absolute;right:-8px;top:-6px;min-width:18px;height:18px;background:#F56C6C;color:#FFFFFF;font-size:11px;border-radius:8px;text-align:center;line-height:18px;padding:0 4px">4</div>
      </div>
      <!-- 历史 -->
      <div style="width:20px;height:20px;font-size:18px;text-align:center;cursor:pointer">🕐</div>
      <!-- 用户头像 -->
      <vc-avatar size="32" text="U" />
    </div>
  </div>
</template>

<script lang="php">

class NavBarComponent extends ReactiveComponent
{
    /** 下拉菜单项 */
    public array $dropItems = [
        ['key' => 'home', 'label' => '首页'],
        ['key' => 'channel', 'label' => '频道'],
        ['key' => 'dynamic', 'label' => '动态'],
        ['key' => 'favorite', 'label' => '收藏'],
        ['key' => 'history', 'label' => '历史'],
    ];
}
</script>

<style>
</style>
