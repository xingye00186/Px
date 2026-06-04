<template>
  <div class="container-100" style="position:relative">
    <vc-row style="left:0px;top:0px;width:100%;height:48px;position:absolute">
      <vc-col style="left:0px;top:0px;position:absolute" :span="24">{{ header }}</vc-col>
    </vc-row>
    <vc-row style="left:0px;top:48px;width:200px;height:100%;position:absolute">
      <vc-col style="left:0px;top:0px;position:absolute" :span="24">{{ sidebar }}</vc-col>
    </vc-row>
    <vc-row style="left:200px;top:48px;width:calc(100% - 200px);height:calc(100% - 96px);position:absolute">
      <vc-col style="left:0px;top:0px;position:absolute" :span="24">{{ main }}</vc-col>
    </vc-row>
    <vc-row style="left:0px;top:calc(100% - 48px);width:100%;height:48px;position:absolute">
      <vc-col style="left:0px;top:0px;position:absolute" :span="24">{{ footer }}</vc-col>
    </vc-row>
  </div>
</template>

<script lang="php">

    /** 页头内容 */
    public string $header = 'Header';

    /** 侧边栏内容 */
    public string $sidebar = 'Sidebar';

    /** 主内容区 */
    public string $main = 'Main Content';

    /** 页脚内容 */
    public string $footer = 'Footer';
</script>

<style>
.container-100 { width: 100%; height: 100%; }
</style>
