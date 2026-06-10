<template>
  <!-- Level 6: 特殊组件 - 测试 milestone/step-list/highlight/toc/dep-box 等 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">

    <!-- TOC 目录卡片 -->
    <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">特殊组件测试</div>
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px;margin-bottom:24px">
      <div style="font-size:15px;font-weight:600;color:#e6edf3;margin-bottom:10px">目录</div>
      <div style="display:flex;flex-direction:row;gap:8px">
        <div style="width:480px">
          <div style="font-size:13px;color:#a78bfa;padding:3px 0">1. 当前问题与目标</div>
          <div style="font-size:13px;color:#a78bfa;padding:3px 0">2. Overlay Layer 核心设计</div>
          <div style="font-size:13px;color:#a78bfa;padding:3px 0">3. v5 M3 实现步骤</div>
        </div>
        <div style="width:480px">
          <div style="font-size:13px;color:#a78bfa;padding:3px 0">4. 验证方案与向后兼容</div>
          <div style="font-size:13px;color:#a78bfa;padding:3px 0">5. DOM-like Tree 可行性分析</div>
        </div>
      </div>
    </div>

    <!-- Dep Box (带层的盒子) -->
    <div style="border:1px solid #30363d;border-radius:8px;overflow:hidden;margin-bottom:16px">
      <div style="background:rgba(34,197,94,0.1);padding:8px 14px;font-weight:600;font-size:13px;color:#e6edf3;border-bottom:1px solid #30363d">v5 M3: Flat + Layer</div>
      <div style="padding:12px 14px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">框架提供:</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- layer 字段 (每个 element 携带层级编号)</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- maxActiveLayer (运行时确定最高活跃层)</div>
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-top:8px;margin-bottom:4px">应用解锁:</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- overlay 属性 → 弹窗不再需要逆条件</div>
      </div>
    </div>

    <div style="border:1px solid #30363d;border-radius:8px;overflow:hidden;margin-bottom:16px">
      <div style="background:rgba(124,58,237,0.1);padding:8px 14px;font-weight:600;font-size:13px;color:#e6edf3;border-bottom:1px solid #30363d">v6 M1: 分段布局 + RenderContext</div>
      <div style="padding:12px 14px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">框架提供:</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- 编译拆分 getLayout_X()</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- RenderContext (26方法)</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- 8 GDI + 7 事件</div>
      </div>
    </div>

    <!-- Step items (步骤列表) -->
    <div style="margin-top:24px;margin-bottom:16px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">实施步骤</div>

      <!-- Step 1 -->
      <div style="display:flex;flex-direction:row;gap:12px;margin-bottom:20px">
        <div style="width:24px;height:24px;border-radius:12px;background:#7c3aed;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <span style="font-size:12px;font-weight:600;color:#ffffff">1</span>
        </div>
        <div style="flex:1">
          <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:4px">AST 节点扩展</div>
          <div style="font-size:13px;color:#8b949e">添加 layer 和 isOverlay 属性到 TemplateNode</div>
        </div>
      </div>

      <!-- Step 2 -->
      <div style="display:flex;flex-direction:row;gap:12px;margin-bottom:20px">
        <div style="width:24px;height:24px;border-radius:12px;background:#7c3aed;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <span style="font-size:12px;font-weight:600;color:#ffffff">2</span>
        </div>
        <div style="flex:1">
          <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:4px">模板解析增强</div>
          <div style="font-size:13px;color:#8b949e">parseComponentRef() 检测 overlay boolean 属性</div>
        </div>
      </div>
    </div>

    <!-- 设计原则列表 -->
    <div style="margin-top:24px;margin-bottom:16px">
      <div style="font-size:16px;font-weight:600;color:#e6edf3;margin-bottom:12px">关键设计原则</div>
      <div style="padding-left:24px">
        <div style="display:flex;flex-direction:row;gap:8px;margin-bottom:8px">
          <span style="font-size:14px;color:#a78bfa;flex-shrink:0">①</span>
          <span style="font-size:14px;color:#e6edf3">每步只解决一个问题 — 不提前引入不需要的复杂度</span>
        </div>
        <div style="display:flex;flex-direction:row;gap:8px;margin-bottom:8px">
          <span style="font-size:14px;color:#a78bfa;flex-shrink:0">②</span>
          <span style="font-size:14px;color:#e6edf3">每步都是上一步的自然延伸 — group_id 依赖 layer</span>
        </div>
        <div style="display:flex;flex-direction:row;gap:8px;margin-bottom:8px">
          <span style="font-size:14px;color:#a78bfa;flex-shrink:0">③</span>
          <span style="font-size:14px;color:#e6edf3">数据结构先于行为变更 — 先在 flat array 上加 metadata</span>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div style="margin-top:48px;padding-top:32px;border-top:1px solid #30363d;display:flex;justify-content:center">
      <span style="font-size:12px;color:#8b949e">VueCalc Framework Architecture Roadmap</span>
    </div>
  </div>
</template>
<script lang="php">
class Level6_Special extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
