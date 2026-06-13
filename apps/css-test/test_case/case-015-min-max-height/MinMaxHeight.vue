<template>
  <div class="bx-card" style="width:720px;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);">
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 布局 ・ Min/Max 约束测试
    </div>
    <div class="bx-row" style="display:flex;gap:16px;align-items:stretch;">
      <div class="mm-item" style="flex:1;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
        <div style="font-size:12px;color:#999;margin-bottom:8px;">min-height: 100px</div>
        <div class="mm-min" style="min-height:100px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;padding:10px;">min-h 生效</div>
      </div>
      <div class="mm-item" style="flex:1;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
        <div style="font-size:12px;color:#999;margin-bottom:8px;">max-height: 80px (内容多)</div>
        <div class="mm-max" style="max-height:80px;background:linear-gradient(135deg,#f093fb,#f5576c);border-radius:6px;font-size:12px;color:#fff;padding:10px;overflow:hidden;">
          多行文本内容<br>第2行<br>第3行<br>第4行<br>第5行<br>第6行<br>第7行
        </div>
      </div>
      <div class="mm-item" style="flex:1;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
        <div style="font-size:12px;color:#999;margin-bottom:8px;">无约束 (参考)</div>
        <div style="background:linear-gradient(135deg,#4ade80,#38f9d7);border-radius:6px;font-size:12px;color:#333;padding:10px;">自适应高度</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-015: Layout ― Min / Max Height
    </div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
