<template>
  <div class="bx-card" style="width:720px;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 文本 ・ 单词换行测试
    </div>
    <div class="wb-row" style="display:flex;gap:16px;margin-bottom:16px;">
      <div class="wb-card" style="flex:1;padding:14px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
        <div style="font-size:11px;color:#999;margin-bottom:8px;">overflow-wrap: normal (默认)</div>
        <div class="wb-nowrap" style="width:180px;overflow-wrap:normal;font-size:14px;color:#444;padding:8px;background:#fff;border-radius:4px;border:1px solid #eee;">
          Supercalifragilisticexpialidocious超长英文不换行
        </div>
      </div>
      <div class="wb-card" style="flex:1;padding:14px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
        <div style="font-size:11px;color:#999;margin-bottom:8px;">word-break: break-all</div>
        <div class="wb-break" style="width:180px;word-break:break-all;font-size:14px;color:#444;padding:8px;background:#fff;border-radius:4px;border:1px solid #eee;">
          Supercalifragilisticexpialidocious超长英文换行
        </div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-023: Text ― Word Break
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
