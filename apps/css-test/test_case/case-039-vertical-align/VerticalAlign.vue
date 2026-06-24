<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 文本 ・ Vertical-Align 垂直对齐测试
    </div>
    <div class="va-row" style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;margin-bottom:16px;">
      <div style="font-size:14px;color:#666;margin-bottom:8px;">不同 vertical-align 值的效果：</div>
      <div style="font-size:24px;background:#fff;padding:8px;border:1px solid #eee;">
        文本
        <span style="font-size:16px;vertical-align:baseline;background:#e3f2fd;">baseline</span>
        <span style="font-size:16px;vertical-align:sub;background:#fff3e0;">sub</span>
        <span style="font-size:16px;vertical-align:super;background:#e8f5e9;">super</span>
        <span style="font-size:16px;vertical-align:top;background:#fce4ec;">top</span>
        <span style="font-size:16px;vertical-align:middle;background:#f3e5f5;">middle</span>
        <span style="font-size:16px;vertical-align:bottom;background:#e0f7fa;">bottom</span>
      </div>
    </div>
    <div class="va-row2" style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;margin-bottom:16px;">
      <div style="font-size:14px;color:#666;margin-bottom:8px;">vertical-align: text-top / text-bottom</div>
      <div style="font-size:30px;background:#fff;padding:8px;border:1px solid #eee;">
        大号文字
        <span style="font-size:14px;vertical-align:text-top;background:#e3f2fd;">text-top</span>
        <span style="font-size:14px;vertical-align:text-bottom;background:#fff3e0;">text-bottom</span>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-039: Text ― Vertical-Align
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
