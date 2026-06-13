<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
  <div style="font-size:18px;font-weight:600;margin-bottom:16px;color:#333;">
    Typography Test
  </div>

  <div style="font-size:24px;font-weight:700;color:#1a1a1a;margin-bottom:8px;">
    Heading 1 — Large Bold
  </div>
  <div style="font-size:20px;font-weight:600;color:#333;margin-bottom:8px;">
    Heading 2 — Medium Semibold
  </div>
  <div style="font-size:16px;font-weight:600;color:#555;margin-bottom:12px;">
    Heading 3 — Small Bold
  </div>

  <div style="font-size:14px;color:#666;line-height:1.8;margin-bottom:16px;">
    This is a paragraph with line-height 1.8. The quick brown fox jumps over the lazy dog. 
    Px 框架的布局引擎应该能够正确处理文字换行和行间距，确保可读性良好。多行文本渲染是布局引擎的基础能力。
  </div>

  <div style="font-size:12px;color:#999;margin-bottom:8px;">
    Caption text — 12px, gray (#999)
  </div>
  <div style="font-size:14px;color:#1565c0;font-weight:600;margin-bottom:8px;">
    Colored link text — blue, semibold
  </div>
  <div style="font-size:13px;color:#2e7d32;font-family:Consolas,monospace;background:#f5f5f5;padding:12px 16px;border-radius:4px;border:1px solid #e0e0e0;">
    Code block — monospace, green text, light background
  </div>

    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class Typography extends ReactiveComponent
{
}
</script>
