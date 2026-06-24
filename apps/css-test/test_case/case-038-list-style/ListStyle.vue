<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 列表 ・ List-Style 样式测试
    </div>
    <div class="ls-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">list-style-type: disc（默认）</div>
        <ul style="margin-left:24px;font-size:16px;color:#333;list-style-type:disc;">
          <li>列表项 A</li>
          <li>列表项 B</li>
          <li>列表项 C</li>
        </ul>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">list-style-type: decimal</div>
        <ol style="margin-left:24px;font-size:16px;color:#333;list-style-type:decimal;">
          <li>第一项</li>
          <li>第二项</li>
          <li>第三项</li>
        </ol>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">list-style-type: square</div>
        <ul style="margin-left:24px;font-size:16px;color:#333;list-style-type:square;">
          <li>方块列表项</li>
          <li>方块列表项</li>
        </ul>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">list-style-type: circle</div>
        <ul style="margin-left:24px;font-size:16px;color:#333;list-style-type:circle;">
          <li>空心圆点</li>
          <li>空心圆点</li>
        </ul>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-038: List ― List-Style
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
