<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 媒体 ・ Object-Fit 与 Object-Position 测试
    </div>
    <div class="of-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">object-fit: cover（覆盖裁剪）</div>
        <div style="width:160px;height:100px;background:#e3f2fd;overflow:hidden;border:1px solid #90caf9;">
          <div style="width:100%;height:100%;background:linear-gradient(135deg,#ff6b6b,#4ecdc4);object-fit:cover;">cover</div>
        </div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">object-fit: contain（等比包含）</div>
        <div style="width:160px;height:100px;background:#fff3e0;border:1px solid #ffcc80;">
          <div style="width:100%;height:100%;background:linear-gradient(135deg,#a8e063,#56ab2f);object-fit:contain;">contain</div>
        </div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">object-fit: fill（拉伸填充）</div>
        <div style="width:160px;height:100px;background:#fce4ec;border:1px solid #f48fb1;">
          <div style="width:100%;height:100%;background:linear-gradient(135deg,#667eea,#764ba2);object-fit:fill;">fill</div>
        </div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">object-fit: none（原始尺寸）</div>
        <div style="width:160px;height:100px;background:#e8f5e9;border:1px solid #a5d6a7;">
          <div style="width:100%;height:100%;background:linear-gradient(135deg,#f093fb,#f5576c);object-fit:none;">none</div>
        </div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-047: Media ― Object-Fit
    </div>
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
