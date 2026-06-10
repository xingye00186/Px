<template>
  <!-- Level 4: Grid布局 - 测试 grid-2 / grid-3 网格布局 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">

    <!-- Section header -->
    <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">Grid 布局测试</div>

    <!-- Grid-2: 两列等分网格 -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">左侧卡片</div>
        <div style="font-size:14px;color:#e6edf3">红色左边框，占 grid 左侧一列。</div>
        <div style="font-size:14px;color:#e6edf3;margin-top:8px">内容描述：框架缺少渲染层 (Layer/Z-Order) 概念。所有元素在同一个扁平列表中。</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #22c55e;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">右侧卡片</div>
        <div style="font-size:14px;color:#e6edf3">绿色左边框，占 grid 右侧一列。</div>
        <div style="font-size:14px;color:#e6edf3;margin-top:8px">内容描述：实现声明式的 overlay layer 系统，开发者只需在弹窗组件上加 overlay 属性。</div>
      </div>
    </div>

    <!-- Grid-2: 渲染策略对比 -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">渲染策略 — 累积式</div>
        <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px">
          <div style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace">Layer 0 → Layer 1 → Layer 2</div>
        </div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">点击策略 — 层过滤</div>
        <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px">
          <div style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace">maxActiveLayer → 仅检查该层按钮</div>
        </div>
      </div>
    </div>

    <!-- Grid-3: 三列网格 -->
    <div style="font-size:16px;font-weight:600;color:#e6edf3;margin-top:24px;margin-bottom:12px">三列布局</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:24px">
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">列 1</div>
        <div style="font-size:13px;color:#8b949e">第一列内容</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">列 2</div>
        <div style="font-size:13px;color:#8b949e">第二列内容</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">列 3</div>
        <div style="font-size:13px;color:#8b949e">第三列内容</div>
      </div>
    </div>
  </div>
</template>
<script lang="php">
class Level4_Grid extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
