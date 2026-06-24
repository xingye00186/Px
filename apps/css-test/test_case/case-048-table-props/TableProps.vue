<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 表格 ・ Table 属性测试
    </div>
    <div class="tb-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">border-collapse: collapse（合并边框）</div>
        <table style="border-collapse:collapse;width:100%;font-size:13px;">
          <tr><td style="border:1px solid #e94560;padding:6px;">A1</td><td style="border:1px solid #e94560;padding:6px;">B1</td></tr>
          <tr><td style="border:1px solid #e94560;padding:6px;">A2</td><td style="border:1px solid #e94560;padding:6px;">B2</td></tr>
        </table>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">border-collapse: separate + border-spacing</div>
        <table style="border-collapse:separate;border-spacing:4px;width:100%;font-size:13px;">
          <tr><td style="border:1px solid #4facfe;padding:6px;background:#e3f2fd;">X1</td><td style="border:1px solid #4facfe;padding:6px;background:#e3f2fd;">Y1</td></tr>
          <tr><td style="border:1px solid #4facfe;padding:6px;background:#e3f2fd;">X2</td><td style="border:1px solid #4facfe;padding:6px;background:#e3f2fd;">Y2</td></tr>
        </table>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">table-layout: fixed（固定布局）</div>
        <table style="table-layout:fixed;width:100%;font-size:13px;border:1px solid #a5d6a7;">
          <colgroup><col style="width:60px;"><col style="width:auto;"></colgroup>
          <tr><td style="border:1px solid #a5d6a7;padding:4px;">短</td><td style="border:1px solid #a5d6a7;padding:4px;">自动宽度列</td></tr>
        </table>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:6px;">caption-side: bottom</div>
        <table style="width:100%;font-size:13px;border:1px solid #ffcc80;">
          <caption style="caption-side:bottom;padding:4px;font-size:12px;color:#888;">表格底部标题</caption>
          <tr><td style="border:1px solid #ffcc80;padding:6px;">数据 1</td><td style="border:1px solid #ffcc80;padding:6px;">数据 2</td></tr>
        </table>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-048: Table ― Border-Collapse &amp; Layout
    </div>
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
