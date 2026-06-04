<template>
  <div style="width:400px;height:300px;background:#1E88E5;display:flex;flex-direction:column;padding:20px;gap:20px">
    <div style="height:40px;background:#FFFFFF;display:flex;align-items:center;padding:0 10px">
      <span style="font-size:18px;color:#1E88E5;font-weight:bold">Skia 字体测试</span>
    </div>
    <div style="height:30px;background:#FFC107;display:flex;align-items:center;padding:0 10px">
      <span style="font-size:14px;color:#000000">Noto Sans SC 简体中文 ABC 123</span>
    </div>
    <div style="display:flex;gap:20px">
      <div style="flex:1;height:50px;background:#4CAF50;display:flex;align-items:center;justify-content:center">
        <span style="font-size:16px;color:#FFFFFF;font-weight:bold">按钮</span>
      </div>
      <div style="flex:1;height:50px;background:#F44336;display:flex;align-items:center;justify-content:center">
        <span style="font-size:16px;color:#FFFFFF;font-weight:bold">确认</span>
      </div>
    </div>
    <div style="flex:1;background:#212121;padding:8px 10px;display:flex;flex-direction:column;gap:14px">
      <span style="font-size:13px;color:#FFFFFF">中文：你好世界 Px 框架 Skia 渲染</span>
      <span style="font-size:13px;color:#FFD54F">English: Hello World Px Skia Render</span>
    </div>
  </div>
</template>

<script lang="php">
    public function init(): void {}
</script>
