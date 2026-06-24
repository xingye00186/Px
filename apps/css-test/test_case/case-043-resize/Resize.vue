<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 界面 ・ Resize 与 Outline-Offset 测试
    </div>
    <div class="ui-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">resize: both（可双向调整大小）</div>
        <textarea style="width:200px;height:60px;resize:both;border:1px solid #90caf9;padding:4px;font-size:12px;">可拖拽调整</textarea>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">resize: vertical（仅垂直调整）</div>
        <textarea style="width:200px;height:60px;resize:vertical;border:1px solid #a5d6a7;padding:4px;font-size:12px;">仅垂直调整</textarea>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">resize: horizontal（仅水平调整）</div>
        <textarea style="width:200px;height:60px;resize:horizontal;border:1px solid #ffcc80;padding:4px;font-size:12px;">仅水平调整</textarea>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">outline + outline-offset</div>
        <div style="width:200px;height:40px;background:#fff;border:1px solid #ccc;outline:2px solid #e94560;outline-offset:4px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#333;">outline-offset:4px</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-043: UI ― Resize &amp; Outline-Offset
    </div>
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
