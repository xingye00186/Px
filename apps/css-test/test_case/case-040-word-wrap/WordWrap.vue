<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 文本 ・ Word-Wrap / Overflow-Wrap 换行测试
    </div>
    <div class="ww-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">overflow-wrap: normal（默认不换行）</div>
        <div style="width:200px;font-size:14px;color:#333;background:#fff;padding:6px;border:1px solid #ddd;overflow-wrap:normal;">SuperLongWordWithoutAnyBreakPointThatShouldOverflow</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">overflow-wrap: break-word 强制换行</div>
        <div style="width:200px;font-size:14px;color:#333;background:#fff;padding:6px;border:1px solid #ddd;overflow-wrap:break-word;">SuperLongWordWithoutAnyBreakPointThatShouldOverflow</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">word-wrap: break-word（旧名别名）</div>
        <div style="width:200px;font-size:14px;color:#333;background:#fff;padding:6px;border:1px solid #ddd;word-wrap:break-word;">AnotherVeryLongWordThatNeedsToBeBrokenAcrossLines</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-040: Text ― Word-Wrap &amp; Overflow-Wrap
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
