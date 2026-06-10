<template>
  <!-- Level 3: Flex布局 - 测试tier-header/badge/tag等flex布局元素 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">

    <!-- tier-header: flex row + align-items center + gap -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-bottom:8px;margin-top:16px">
      <div style="width:28px;height:28px;border-radius:14px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <span style="font-size:13px;font-weight:700;color:#22c55e">1</span>
      </div>
      <span style="font-size:14px;font-weight:600;color:#e6edf3">Tier 1: 已支持/零成本扩展</span>
    </div>

    <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-bottom:8px">
      <div style="width:28px;height:28px;border-radius:14px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <span style="font-size:13px;font-weight:700;color:#3b82f6">2</span>
      </div>
      <span style="font-size:14px;font-weight:600;color:#e6edf3">Tier 2: 中等 GDI 扩展</span>
    </div>

    <!-- Badge 行 -->
    <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-top:16px;margin-bottom:12px">
      <span style="font-size:11px;font-weight:600;color:#22c55e;background:rgba(34,197,94,0.15);padding:2px 8px;border-radius:4px">当前</span>
      <span style="font-size:11px;font-weight:600;color:#3b82f6;background:rgba(59,130,246,0.15);padding:2px 8px;border-radius:4px">下一步</span>
      <span style="font-size:11px;font-weight:600;color:#a78bfa;background:rgba(124,58,237,0.15);padding:2px 8px;border-radius:4px">v6</span>
      <span style="font-size:11px;font-weight:600;color:#8b949e;background:#1c2128;padding:2px 8px;border-radius:4px">远期</span>
    </div>

    <!-- Tags 行 (flex-wrap) -->
    <div style="display:flex;flex-direction:row;gap:4px;flex-wrap:wrap;margin-top:12px;margin-bottom:12px">
      <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">rect</span></div>
      <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">text</span></div>
      <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">button</span></div>
      <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">grid</span></div>
      <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">label</span></div>
    </div>

    <!-- Grid-3 三列卡片组 (flex row, 每个 flex:1) -->
    <div style="display:flex;flex-direction:row;gap:12px;margin-top:16px;margin-bottom:16px">
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">原则 1</div>
        <div style="font-size:13px;color:#8b949e">每步只解决一个问题</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">原则 2</div>
        <div style="font-size:13px;color:#8b949e">每步都是上一步的自然延伸</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">原则 3</div>
        <div style="font-size:13px;color:#8b949e">数据结构先于行为变更</div>
      </div>
    </div>

    <!-- Milestone 行 (flex complex) -->
    <div style="display:flex;flex-direction:row;gap:8px;align-items:flex-start;margin-top:16px;margin-bottom:12px">
      <div style="width:24px;height:24px;border-radius:12px;border:2px solid #22c55e;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px">
        <span style="font-size:10px;font-weight:700;color:#22c55e">●</span>
      </div>
      <div style="flex:1">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#22c55e;background:rgba(34,197,94,0.15);padding:2px 8px;border-radius:4px">当前</span>
          <span style="font-size:14px;font-weight:600;color:#e6edf3">v5 M3: Flat + Layer</span>
        </div>
        <div style="font-size:12px;color:#8b949e;margin-top:4px">~100 行 / 7 文件</div>
      </div>
    </div>
  </div>
</template>
<script lang="php">
class Level3_Flex extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
