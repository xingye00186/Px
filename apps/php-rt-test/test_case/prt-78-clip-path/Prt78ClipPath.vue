<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:12px;color:#999;margin-bottom:8px;">clip-path shape on element rendering</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;"><div style="width:100px;height:100px;background:#e3f2fd;border:1px solid #1565c0;clip-path:circle(50%);"></div><div style="width:100px;height:100px;background:#fce4ec;border:1px solid #c62828;clip-path:polygon(50% 0%,100% 50%,50% 100%,0% 50%);"></div><div style="width:100px;height:100px;background:#e8f5e9;border:1px solid #2e7d32;clip-path:inset(10px 20px 30px 40px);"></div></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt78ClipPath extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
