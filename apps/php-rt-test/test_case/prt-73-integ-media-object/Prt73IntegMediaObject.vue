<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:8px;">Media Object Pattern</div>
    <div style="display:flex;gap:12px;width:580px;background:#f5f5f5;border:1px solid #ccc;padding:12px;margin-bottom:8px;">
      <div style="width:60px;height:60px;background:#e3f2fd;border:1px solid #1565c0;border-radius:8px;flex-shrink:0;"></div>
      <div style="flex:1;">
        <div style="font-weight:600;font-size:14px;color:#333;margin-bottom:4px;">Media Title</div>
        <div style="font-size:13px;color:#666;">Media description text that wraps naturally beside the media object image placeholder element.</div>
      </div>
    </div>
    <div style="display:flex;gap:12px;width:580px;background:#f5f5f5;border:1px solid #ccc;padding:12px;flex-direction:row-reverse;">
      <div style="width:60px;height:60px;background:#fce4ec;border:1px solid #c62828;border-radius:8px;flex-shrink:0;"></div>
      <div style="flex:1;">
        <div style="font-weight:600;font-size:14px;color:#333;margin-bottom:4px;">Reversed Media</div>
        <div style="font-size:13px;color:#666;">Media object with image on the right side using flex-direction row-reverse.</div>
      </div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt73IntegMediaObject extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
