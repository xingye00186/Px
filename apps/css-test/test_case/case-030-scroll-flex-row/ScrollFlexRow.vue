<template>
<div style="padding:20px;">
  <div class="card" style="width:800px;margin:0 auto;box-sizing:border-box;background:#fff;border-radius:12px;padding:24px;border:1px solid #e0e0e0;position:relative;box-shadow:0 2px 12px rgba(0,0,0,.08);">

    <div style="margin-bottom:10px;font-size:18px;font-weight:700;color:#2e7d32;">
      Scroll Flex Row (horizontal)
    </div>
    <div style="margin-bottom:14px;font-size:12px;color:#555;line-height:1.6;border-left:3px solid #2e7d32;padding:10px 14px;background:#e8f5e9;border-radius:4px;">
      <b>Layout:</b> Flex container (row) → flex:1 child with overflow-x:auto.<br>
      <b>Container:</b> flex container 700px wide, side panels 60px each, flex:1 child fills remainder (~580px).<br>
      <b>Items:</b> 15 items x 100px wide = 1500px content (horizontal scroll).<br>
      <b>Expected:</b> Horizontal wheel/drag scroll stops at right edge. scrollLeft clamped correctly.
    </div>

    <div class="flex-host" style="width:100%;height:200px;display:flex;flex-direction:row;border:2px solid #2e7d32;border-radius:6px;background:#fafafa;">
      <div style="width:60px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#e8f5e9;border-right:1px solid #2e7d32;font-size:12px;color:#2e7d32;writing-mode:vertical-lr;">
        Left
      </div>
      <div class="scroll-area" style="flex:1;overflow-x:auto;min-width:0;padding:60px 0 0 0;background:#fafafa;">
        <div style="display:flex;flex-direction:row;gap:8px;padding:0 8px;">
          <div v-for="item in items"
               style="width:100px;height:80px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;border-radius:6px;border:1px solid #c8e6c9;"
               :style="'background:' + item.bg">
            <span style="font-weight:700;color:#2e7d32;">#{{ item.id }}</span>
          </div>
        </div>
      </div>
      <div style="width:60px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#e8f5e9;border-left:1px solid #2e7d32;font-size:12px;color:#2e7d32;writing-mode:vertical-lr;">
        Right
      </div>
    </div>

    <div style="margin-top:14px;font-size:11px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:10px;">
      case-030-scroll-flex-row &middot; {{ count }} items &middot; flex:1 + overflow-x:auto (horizontal)
    </div>
  <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div><div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent
{
    public array $items = [];
    public string $count = '0';

    public function onMount(): void
    {
        $colors = ['#e8f5e9', '#ffffff', '#f1f8e9', '#e0f2f1'];
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = ['id' => (string)$i, 'bg' => $colors[$i % count($colors)]];
        }
        $this->items = $items;
        $this->count = '15';
    }
}
</script>
