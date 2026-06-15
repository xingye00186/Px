<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative">
    
    <div style="margin-bottom:10px;font-size:18px;font-weight:700;color:#c62828;">
      Scrollbar Diagnostic
    </div>
    <div style="margin-bottom:14px;font-size:12px;color:#555;line-height:1.6;border-left:3px solid #ff9800;padding:10px 14px;background:#fff8e1;border-radius:4px;">
      <b>Bug:</b> Drag scrollbar thumb down — top items disappear (directRender path).<br>
      <b>Wheel:</b> Scroll wheel works correctly (requestRender path).<br>
      <b>Container:</b> 300px visible, 30 items x 36px = 1080px content.<br>
      <b>Expected:</b> Smooth scrolling, all items reachable, no clipping at top.
    </div>
    
    <div class="scroll-box" style="height:300px;overflow-y:auto;border:2px solid #455a64;border-radius:6px;background:#fafafa;">
      <div v-for="item in items" 
           style="height:36px;display:flex;align-items:center;padding:0 14px;font-size:13px;border-bottom:1px solid #e0e0e0;"
           :style="'background:' + item.bg">
        <span style="display:inline-block;width:36px;font-weight:700;color:#78909c;">{{ item.id }}.</span>
        <span style="color:#37474f;">Scroll Item #{{ item.id }}</span>
        <span style="margin-left:10px;font-size:11px;color:#aaa;">36px</span>
      </div>
    </div>

    <div style="margin-top:14px;font-size:11px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:10px;">
      case-027-scroll-diagnostic &middot; {{ count }} items
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
        $colors = ['#f5f7ff', '#ffffff', '#fff8e1', '#e8f5e9', '#fce4ec', '#f3e5f5'];
        $items = [];
        for ($i = 1; $i <= 30; $i++) {
            $items[] = ['id' => (string)$i, 'bg' => $colors[$i % count($colors)]];
        }
        $this->items = $items;
        $this->count = '30';
    }
}
</script>
