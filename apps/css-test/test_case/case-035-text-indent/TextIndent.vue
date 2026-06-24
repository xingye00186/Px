<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 文本 ・ Text-Indent 缩进与 Transform 转换测试
    </div>
    <div class="ti-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:16px;color:#333;text-indent:0;">无缩进：这是测试段落文字，用于验证 text-indent 属性的渲染效果。</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:16px;color:#333;text-indent:2em;">缩进2em：这是测试段落文字，text-indent 设置为 2em，首行应缩进两个字符宽度。</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:16px;color:#333;text-indent:40px;">缩进40px：这是测试段落文字，验证固定像素值缩进。</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <span style="font-size:18px;color:#333;text-transform:uppercase;">uppercase 大写转换</span>
        <br>
        <span style="font-size:18px;color:#333;text-transform:lowercase;">LOWERCASE 小写转换</span>
        <br>
        <span style="font-size:18px;color:#333;text-transform:capitalize;">capitalize each word 首字母大写</span>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-035: Text ― Text-Indent &amp; Transform
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
