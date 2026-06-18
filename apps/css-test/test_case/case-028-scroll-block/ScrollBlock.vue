<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative">

    <div style="margin-bottom:10px;font-size:18px;font-weight:700;color:#1565c0;">
      Scroll Block (default flow)
    </div>
    <div style="margin-bottom:14px;font-size:12px;color:#555;line-height:1.6;border-left:3px solid #1565c0;padding:10px 14px;background:#e3f2fd;border-radius:4px;">
      <b>Layout:</b> div with overflow-y:auto (display:block, no flex parent).<br>
      <b>Container:</b> 300px visible, 30 items x 36px = 1080px content.<br>
      <b>Expected:</b> Wheel + drag scroll both stop at bottom without empty space. contentHeight = 1080+ padding.
    </div>

    <div class="scroll-box" style="height:300px;overflow-y:auto;border:2px solid #1565c0;border-radius:6px;background:#fafafa;">
      <div v-for="item in items"
           style="height:36px;display:flex;align-items:center;padding:0 14px;font-size:13px;border-bottom:1px solid #e0e0e0;"
           :style="'background:' + item.bg">
        <span style="display:inline-block;width:36px;font-weight:700;color:#1565c0;">{{ item.id }}.</span>
        <span style="color:#37474f;">Block Item #{{ item.id }}</span>
        <span style="margin-left:10px;font-size:11px;color:#aaa;">36px</span>
      </div>
    </div>

    <div style="margin-top:14px;font-size:11px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:10px;">
      case-028-scroll-block &middot; {{ count }} items &middot; display:block + overflow-y:auto
    </div>
  <div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div><div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent
{
    public array $items = [];
    public string $count = '0';

    public function onMount(): void
    {
        $colors = ['#e3f2fd', '#ffffff', '#f5f5f5', '#e8eaf6', '#e0f2f1'];
        $items = [];
        for ($i = 1; $i <= 30; $i++) {
            $items[] = ['id' => (string)$i, 'bg' => $colors[$i % count($colors)]];
        }
        $this->items = $items;
        $this->count = '30';
    }
}
</script>
