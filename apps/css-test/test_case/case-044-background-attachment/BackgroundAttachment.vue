<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 背景 ・ Background-Attachment 测试
    </div>
    <div class="ba-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;border-radius:6px;border:1px solid #ccc;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">background-attachment: scroll（默认）</div>
        <div style="width:200px;height:80px;overflow:auto;border:1px solid #90caf9;background:linear-gradient(135deg,#e3f2fd,#bbdefb);background-attachment:scroll;">
          <div style="height:150px;padding:8px;font-size:12px;color:#333;">滚动内容 - 背景随内容滚动</div>
        </div>
      </div>
      <div style="padding:16px;border-radius:6px;border:1px solid #ccc;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">background-attachment: fixed</div>
        <div style="width:200px;height:80px;overflow:auto;border:1px solid #a5d6a7;background:linear-gradient(135deg,#e8f5e9,#c8e6c9);background-attachment:fixed;">
          <div style="height:150px;padding:8px;font-size:12px;color:#333;">滚动内容 - 背景固定不动</div>
        </div>
      </div>
      <div style="padding:16px;border-radius:6px;border:1px solid #ccc;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">background-attachment: local</div>
        <div style="width:200px;height:80px;overflow:auto;border:1px solid #ffcc80;background:linear-gradient(135deg,#fff3e0,#ffe0b2);background-attachment:local;">
          <div style="height:150px;padding:8px;font-size:12px;color:#333;">滚动内容 - 背景随元素滚动</div>
        </div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-044: Background ― Attachment
    </div>
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
