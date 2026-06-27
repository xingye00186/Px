<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:12px;color:#999;margin-bottom:8px;">column-span:all across multi-column layout</div>
    <div style="width:500px;column-count:3;column-gap:16px;column-rule:1px solid #ccc;background:#f5f5f5;border:1px solid #ccc;padding:12px;"><div style="font-size:13px;color:#666;">Column 1 content here.</div><div style="column-span:all;margin:8px 0;padding:8px;background:#e3f2fd;border:1px solid #1565c0;font-size:14px;font-weight:600;">Span All Columns</div><div style="font-size:13px;color:#666;">More content below spanned section.</div></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt102ColumnSpan extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
