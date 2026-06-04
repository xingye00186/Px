<template>
  <div style="width:1440px;height:900px;background:#FFFFFF;display:flex;flex-direction:column">
    <!-- 顶部导航栏 -->
    <nav-bar />

    <!-- 分类标签 -->
    <category-tabs />

    <!-- 主内容区 -->
    <div style="flex:1;display:flex;padding:20px 24px 0 24px;overflow-y:auto">
      <!-- 左侧内容 -->
      <div style="display:flex;flex-direction:column;gap:20px;flex:1;min-width:0">
        <banner-carousel />
        <video-grid />
      </div>
      <!-- 右侧边栏 -->
      <sidebar-widget />
    </div>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>

<style>
</style>
