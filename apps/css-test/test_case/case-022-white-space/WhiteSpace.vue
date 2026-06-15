<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 文本 ・ 空白/溢出测试
    </div>
    <div class="ws-section" style="margin-bottom:12px;padding:14px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:4px;">white-space: nowrap + text-overflow: ellipsis</div>
      <div class="ws-ellipsis" style="width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:14px;color:#444;padding:8px;background:#fff;border-radius:4px;border:1px solid #eee;">
        这是一段非常长的文本内容，超长时应该显示省略号而不是换行显示
      </div>
    </div>
    <div class="ws-section" style="margin-bottom:16px;padding:14px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:4px;">white-space: pre (保留空格和换行)</div>
      <div class="ws-pre" style="white-space:pre;font-size:14px;color:#444;padding:8px;background:#fff;border-radius:4px;border:1px solid #eee;font-family:monospace;">
        保留    空格
        保留换行
        缩进效果
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-022: Text ― White Space / Overflow
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
