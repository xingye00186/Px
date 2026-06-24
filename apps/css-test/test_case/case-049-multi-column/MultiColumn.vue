<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 多列 ・ Multi-Column 布局测试
    </div>
    <div class="mc-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">column-count: 3（三列布局）</div>
        <div style="column-count:3;column-gap:16px;font-size:13px;color:#333;background:#fff;padding:8px;border:1px solid #e0e0e0;text-align:justify;">
          这是多列布局的测试文本。column-count 属性将内容分为指定数量的列。这里演示了三列布局效果，每列之间有 gap 间距。
        </div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">column-rule: 2px solid #e94560</div>
        <div style="column-count:3;column-gap:20px;column-rule:2px solid #e94560;font-size:13px;color:#333;background:#fff;padding:8px;border:1px solid #e0e0e0;">
          列与列之间带有红色 2px 实线分隔线。column-rule 设置列间分隔线的样式、宽度和颜色。
        </div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">column-width: 150px（指定列宽）</div>
        <div style="column-width:150px;column-gap:12px;font-size:13px;color:#333;background:#fff;padding:8px;border:1px solid #e0e0e0;">
          使用 column-width 指定每列宽度为 150px。浏览器自动根据容器宽度计算列数。
        </div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-049: Layout ― Multi-Column
    </div>
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
