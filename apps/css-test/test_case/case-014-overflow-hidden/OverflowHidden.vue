<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 布局 ・ Overflow Hidden 裁切测试
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;margin-bottom:16px;line-height:1.5;">
      容器 overflow:hidden，宽250高120，超出的绿色块应被裁切
    </div>
    <div class="oh-container" style="width:250px;height:120px;overflow:hidden;border:2px solid #0f3460;border-radius:8px;margin:0 auto 16px;position:relative;background:#f8f9ff;">
      <div class="oh-content" style="width:300px;height:80px;background:linear-gradient(90deg,#4ade80,#38f9d7);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#333;position:relative;top:20px;">宽度300 > 容器250</div>
      <div class="oh-over" style="width:200px;height:50px;background:#e94560;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;margin-top:10px;">还在可视区</div>
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;line-height:1.6;background:#fafafa;padding:12px;border-radius:6px;">
      ? overflow:hidden 应对超出部分进行裁切，不显示滚动条。
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-014: Layout ― Overflow Hidden
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
