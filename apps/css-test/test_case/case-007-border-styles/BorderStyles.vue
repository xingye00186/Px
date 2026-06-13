<template>
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 盒模型 ・ 边框样式测试
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;margin-bottom:20px;line-height:1.5;">
      每侧不同颜色+宽度：边框红(4px) 上 / 蓝(3px) 右 / 绿(2px) 下 / 橙(1px) 左
    </div>
    <div id="target-box" style="width:300px;height:120px;margin:0 auto 24px;border-top:4px solid #e94560;border-right:3px solid #0f3460;border-bottom:2px solid #4ade80;border-left:1px solid #fb923c;border-radius:10px;background:linear-gradient(135deg,#f8f9ff,#eef2ff);display:flex;align-items:center;justify-content:center;font-size:14px;color:#444;">
      不同方向边框
    </div>
    <div class="bx-grid" style="display:flex;gap:16px;flex-wrap:wrap;justify-content:center;">
      <div class="bx-item" style="flex:1;min-width:140px;padding:16px;background:#f8f9ff;border-radius:8px;text-align:center;">
        <div style="font-size:11px;color:#999;">边框圆角</div>
        <div id="box-radius-test" style="width:80px;height:60px;margin:10px auto 0;border:2px solid #0f3460;border-radius:16px 4px 16px 4px;background:linear-gradient(135deg,#0f3460,#e94560);"></div>
        <div style="font-size:11px;margin-top:8px;color:#666;">border-radius: 16px 4px</div>
      </div>
      <div class="bx-item" style="flex:1;min-width:140px;padding:16px;background:#f8f9ff;border-radius:8px;text-align:center;">
        <div style="font-size:11px;color:#999;">虚线边框</div>
        <div style="width:80px;height:60px;margin:10px auto 0;border:3px dashed #e94560;border-radius:8px;background:#fff;"></div>
        <div style="font-size:11px;margin-top:8px;color:#666;">border: 3px dashed</div>
      </div>
      <div class="bx-item" style="flex:1;min-width:140px;padding:16px;background:#f8f9ff;border-radius:8px;text-align:center;">
        <div style="font-size:11px;color:#999;">无边框</div>
        <div style="width:80px;height:60px;margin:10px auto 0;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:8px;"></div>
        <div style="font-size:11px;margin-top:8px;color:#666;">border: none</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:20px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-007: Box Model ― Border Styles
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
