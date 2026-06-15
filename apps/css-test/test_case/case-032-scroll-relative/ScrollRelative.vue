<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative">

    <div style="margin-bottom:10px;font-size:18px;font-weight:700;color:#c62828;">
      Scroll + Absolute Children
    </div>
    <div style="margin-bottom:14px;font-size:12px;color:#555;line-height:1.6;border-left:3px solid #c62828;padding:10px 14px;background:#ffebee;border-radius:4px;">
      <b>Layout:</b> position:relative scroll container with both normal-flow and absolute-positioned children.<br>
      <b>Container:</b> 300px visible, overflow-y:auto. Contains 20 static items + absolute overlay badge.<br>
      <b>Expected:</b> Static items scroll correctly. Absolute badge stays fixed relative to container.<br>
      <b>Edge case:</b> LayoutResolver must ignore absolute children in auto-stack (they don't affect contentHeight).
    </div>

    <div class="scroll-box" style="height:300px;overflow-y:auto;border:2px solid #c62828;border-radius:6px;background:#fafafa;position:relative;">
      <div style="position:absolute;top:8px;right:8px;background:#c62828;color:#fff;font-size:10px;padding:2px 8px;border-radius:4px;z-index:10;">
        ABSOLUTE BADGE
      </div>
      <div v-for="item in items"
           style="height:36px;display:flex;align-items:center;padding:0 14px;font-size:13px;border-bottom:1px solid #e0e0e0;"
           :style="'background:' + item.bg">
        <span style="display:inline-block;width:36px;font-weight:700;color:#c62828;">{{ item.id }}.</span>
        <span style="color:#37474f;">Scroll Item #{{ item.id }}</span>
        <span style="margin-left:10px;font-size:11px;color:#aaa;">36px</span>
      </div>
      <div style="position:absolute;bottom:8px;right:8px;background:#1565c0;color:#fff;font-size:10px;padding:2px 8px;border-radius:4px;z-index:10;">
        FIXED BADGE
      </div>
    </div>

    <div style="margin-top:14px;font-size:11px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:10px;">
      case-032-scroll-relative &middot; {{ count }} items &middot; position:relative + overflow-y:auto + absolute children
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
        $colors = ['#ffebee', '#ffffff', '#f5f5f5', '#fce4ec'];
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = ['id' => (string)$i, 'bg' => $colors[$i % count($colors)]];
        }
        $this->items = $items;
        $this->count = '20';
    }
}
</script>
