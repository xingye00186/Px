<template>
  <div class="test-sandbox" style="width:1600px;height:800px;background:#f5f5f5;overflow-y:auto;">
    <div class="sandbox-header" style="height:40px;background:#fff;border-bottom:1px solid #ddd;padding:0 20px;display:flex;align-items:center;font-size:14px;color:#666;">
      CSS Test Sandbox — <span style="color:#333;font-weight:bold;">Test Case</span>
    </div>
    <div style="padding:20px;">
      <div style="position:relative;">
        <!-- TL 锚点 (洋红 #FF00FF) → 测试内容外边框左上角，截图对齐基准 -->
        <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
        <test-content />
        <!-- BR 锚点 (青色 #00FFFF) → 测试内容外边框右下角，截图对齐基准 -->
        <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div>
      </div>
    </div>
  </div>
</template>
<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>
