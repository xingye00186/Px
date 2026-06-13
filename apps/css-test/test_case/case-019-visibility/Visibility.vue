<template>
  <div class="bx-card" style="width:720px;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);">
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 布局 ・ Visibility vs Display 测试
    </div>
    <div class="bx-row" style="display:flex;gap:12px;align-items:center;margin-bottom:16px;">
      <div style="flex:1;padding:16px;background:#e8f5e9;border-radius:8px;text-align:center;border:1px solid #4ade80;">Normal</div>
      <div id="vis-hidden" style="flex:1;padding:16px;background:#fff3e0;border-radius:8px;text-align:center;border:1px solid #fb923c;visibility:hidden;">visibility: hidden (占位)</div>
      <div id="disp-none" style="flex:1;padding:16px;background:#fce4ec;border-radius:8px;text-align:center;border:1px solid #e94560;display:none;">display: none</div>
      <div style="flex:1;padding:16px;background:#e8f5e9;border-radius:8px;text-align:center;border:1px solid #4ade80;">Normal</div>
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;line-height:1.6;background:#fafafa;padding:12px;border-radius:6px;">
      <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;">visibility:hidden</code> 隐藏但保留空间(占位)；<code>display:none</code> 完全消失不占空间。
      <br>所以 Normal 之间应只有 1 个间隙(visibility占位)而非 2 个。
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-019: Layout ― Visibility vs Display
    </div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
