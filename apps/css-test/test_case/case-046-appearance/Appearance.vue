<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 界面 ・ Appearance 与 Cursor 测试
    </div>
    <div class="ap-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">appearance: none（移除默认样式）</div>
        <select style="appearance:none;padding:4px 8px;border:1px solid #90caf9;border-radius:4px;font-size:14px;">
          <option>选项一</option><option>选项二</option>
        </select>
        <input type="checkbox" style="appearance:none;width:16px;height:16px;border:1px solid #e94560;margin-left:8px;">
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">cursor: pointer</div>
        <div style="padding:8px 16px;background:#e3f2fd;border-radius:4px;cursor:pointer;display:inline-block;">鼠标悬停变为手型</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">cursor: text / wait / crosshair</div>
        <div>
          <span style="cursor:text;background:#fff3e0;padding:4px 8px;margin-right:8px;">text光标</span>
          <span style="cursor:wait;background:#e8f5e9;padding:4px 8px;margin-right:8px;">wait等待</span>
          <span style="cursor:crosshair;background:#fce4ec;padding:4px 8px;">crosshair十字</span>
        </div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">appearance: auto（默认浏览器样式）</div>
        <input type="text" value="默认输入框" style="border:1px solid #ccc;padding:4px;font-size:14px;">
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-046: UI ― Appearance &amp; Cursor
    </div>
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
