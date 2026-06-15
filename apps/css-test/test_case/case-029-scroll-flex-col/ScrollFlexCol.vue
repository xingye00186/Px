<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative">

    <div style="margin-bottom:10px;font-size:18px;font-weight:700;color:#e65100;">
      Scroll Flex Column
    </div>
    <div style="margin-bottom:14px;font-size:12px;color:#555;line-height:1.6;border-left:3px solid #e65100;padding:10px 14px;background:#fff3e0;border-radius:4px;">
      <b>Layout:</b> Flex container (column) → flex:1 child with overflow-y:auto.<br>
      <b>This matches the .case-list scenario — the exact bug pattern.</b><br>
      <b>Container:</b> flex container 250px, header+footer 52px each, flex:1 child fills remainder (~146px).<br>
      <b>Items:</b> 30 items x 36px = 1080px content.<br>
      <b>Expected:</b> Wheel + drag scroll stop at bottom. No bounce-back on drag release.
    </div>

    <div class="flex-host" style="height:250px;display:flex;flex-direction:column;border:2px solid #e65100;border-radius:6px;background:#fafafa;">
      <div style="height:52px;flex-shrink:0;display:flex;align-items:center;padding:0 16px;background:#fff3e0;border-bottom:1px solid #e65100;font-size:13px;font-weight:600;color:#e65100;">
        Flex Header (52px fixed)
      </div>
      <div class="scroll-area" style="flex:1;overflow-y:auto;min-height:0;padding:4px 0;background:#fafafa;">
        <div v-for="item in items"
             style="height:36px;display:flex;align-items:center;padding:0 14px;font-size:13px;border-bottom:1px solid #e0e0e0;"
             :style="'background:' + item.bg">
          <span style="display:inline-block;width:36px;font-weight:700;color:#e65100;">{{ item.id }}.</span>
          <span style="color:#37474f;">FlexCol Item #{{ item.id }}</span>
          <span style="margin-left:10px;font-size:11px;color:#aaa;">36px</span>
        </div>
      </div>
      <div style="height:52px;flex-shrink:0;display:flex;align-items:center;padding:0 16px;background:#fff3e0;border-top:1px solid #e65100;font-size:13px;font-weight:600;color:#e65100;">
        Flex Footer (52px fixed)
      </div>
    </div>

    <div style="margin-top:14px;font-size:11px;color:#aaa;text-align:center;border-top:1px solid #eee;padding-top:10px;">
      case-029-scroll-flex-col &middot; {{ count }} items &middot; flex:1 + overflow-y:auto
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
        $colors = ['#fff3e0', '#ffffff', '#f5f5f5', '#fbe9e7', '#fff8e1'];
        $items = [];
        for ($i = 1; $i <= 30; $i++) {
            $items[] = ['id' => (string)$i, 'bg' => $colors[$i % count($colors)]];
        }
        $this->items = $items;
        $this->count = '30';
    }
}
</script>
