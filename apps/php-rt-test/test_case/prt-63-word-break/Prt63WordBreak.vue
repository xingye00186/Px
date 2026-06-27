<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:8px;">Word Break</div>
    <div style="width:120px;background:#e3f2fd;border:1px solid #1565c0;margin-bottom:4px;word-break:break-all;padding:4px;">
      <span style="font-size:14px;">BreakAllText</span></div>
    <div style="width:120px;background:#fce4ec;border:1px solid #c62828;word-break:keep-all;padding:4px;">
      <span style="font-size:14px;">KeepAllText</span></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt63WordBreak extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
