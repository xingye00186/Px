<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative">

    <div style="margin-bottom:10px;font-size:18px;font-weight:700;color:#6a1b9a;">
      Scroll Grid
    </div>
    <div style="margin-bottom:14px;font-size:12px;color:#555;line-height:1.6;border-left:3px solid #6a1b9a;padding:10px 14px;background:#f3e5f5;border-radius:4px;">
      <b>Layout:</b> Grid container with 3 columns. The main content area (col 2) has overflow-y:auto.<br>
      <b>Container:</b> Grid 700px x 350px. Scroll area in middle column ~420px wide x 350px tall.<br>
      <b>Items:</b> 25 items x 36px = 900px content.<br>
      <b>Expected:</b> Content in grid cell scrolls correctly. Other grid cells stay fixed.
    </div>

    <div class="grid-host" style="width:100%;height:350px;display:grid;grid-template-columns:120px 1fr 120px;border:2px solid #6a1b9a;border-radius:6px;background:#fafafa;">
      <div style="display:flex;align-items:center;justify-content:center;background:#f3e5f5;border-right:1px solid #6a1b9a;font-size:12px;color:#6a1b9a;padding:8px;">
        Sidebar Left
      </div>
      <div class="scroll-area" style="overflow-y:auto;padding:4px 0;background:#fafafa;">
        <div v-for="item in items"
             style="height:36px;display:flex;align-items:center;padding:0 12px;font-size:13px;border-bottom:1px solid #e0e0e0;"
             :style="'background:' + item.bg">
          <span style="display:inline-block;width:32px;font-weight:700;color:#6a1b9a;">{{ item.id }}.</span>
          <span style="color:#37474f;">Grid Item #{{ item.id }}</span>
          <span style="margin-left:10px;font-size:11px;color:#aaa;">36px</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;justify-content:center;background:#f3e5f5;border-left:1px solid #6a1b9a;font-size:12px;color:#6a1b9a;padding:8px;">
        Sidebar Right
      </div>
    </div>

    <div style="margin-top:14px;font-size:11px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:10px;">
      case-031-scroll-grid &middot; {{ count }} items &middot; display:grid + overflow-y:auto in grid cell
    </div>
  <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div><div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent
{
    public array $items = [];
    public string $count = '0';

    public function onMount(): void
    {
        $colors = ['#f3e5f5', '#ffffff', '#f5f5f5', '#ede7f6'];
        $items = [];
        for ($i = 1; $i <= 25; $i++) {
            $items[] = ['id' => (string)$i, 'bg' => $colors[$i % count($colors)]];
        }
        $this->items = $items;
        $this->count = '25';
    }
}
</script>
