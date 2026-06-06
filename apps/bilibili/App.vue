<template>
  <div style="width:1440px;height:900px;background:#FFFFFF;display:flex;flex-direction:column">
    <!-- 顶部导航栏 -->
    <nav-bar />

    <!-- 分类标签 -->
    <category-tabs />

    <!-- 主内容区 -->
    <main-content />

    <!-- 浮动按钮 -->
    <floating-button />
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>

<style>
</style>
