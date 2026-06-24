<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 文本 ・ Direction RTL 方向测试
    </div>
    <div class="dr-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">LTR 默认方向</div>
        <div style="font-size:18px;color:#333;direction:ltr;">Hello World - 从左到右</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">RTL 右到左方向</div>
        <div style="font-size:18px;color:#c62828;direction:rtl;unicode-bidi:embed;">مرحبا بالعالم - 从右到左</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">RTL + 数字混排</div>
        <div style="font-size:18px;color:#0f3460;direction:rtl;unicode-bidi:embed;">السعر: 99.99 $ - 文本与数字</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">text-align: start 受 direction 影响</div>
        <div style="font-size:18px;color:#333;direction:rtl;text-align:start;unicode-bidi:embed;">这个文本在 RTL 下右对齐</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-037: Text ― Direction RTL
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
