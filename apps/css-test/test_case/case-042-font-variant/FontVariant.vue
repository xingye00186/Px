<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 字体 ・ Font-Variant Stretch 测试
    </div>
    <div class="fv-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">font-variant: normal（默认）</div>
        <div style="font-size:20px;color:#333;">Hello World 正常文字</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">font-variant: small-caps</div>
        <div style="font-size:20px;color:#c62828;font-variant:small-caps;">Hello World Small Caps</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">font-variant: all-small-caps</div>
        <div style="font-size:20px;color:#0f3460;font-variant:all-small-caps;">Hello World All Small Caps</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">font-stretch: condensed</div>
        <div style="font-size:20px;color:#2e7d32;font-stretch:condensed;">Condensed Text 压缩文字</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">font-stretch: expanded</div>
        <div style="font-size:20px;color:#6a1b9a;font-stretch:expanded;">Expanded Text 扩展文字</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-042: Font ― Font-Variant &amp; Stretch
    </div>
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
