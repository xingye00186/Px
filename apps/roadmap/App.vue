<template>
  <div style="width:1100px;height:900px;background:#0d1117;color:#e6edf3;font-size:14px;overflow-y:auto" :scroll-top="scrollTop">
    <!-- Header -->
    <div style="padding:32px 32px 0 32px">
      <span style="font-size:28px;font-weight:700;color:#e6edf3">VueCalc 框架架构演进路线图</span>
    </div>
    <div style="padding:4px 32px 32px 32px">
      <span style="font-size:15px;color:#8b949e">Overlay Layer 系统方案 &amp; 通用桌面框架 Widget 能力扩展 — 完整技术文档</span>
    </div>

    <!-- TOC Card -->
    <div style="margin:0 32px 24px 32px;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:24px">
      <div style="font-size:15px;font-weight:600;color:#e6edf3;margin-bottom:10px">目录</div>
      <div style="display:flex;flex-direction:row;flex-wrap:wrap;gap:8px">
        <div style="width:480px">
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">1. 当前问题与目标</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">2. Overlay Layer 核心设计</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">3. v5 M3 实现步骤</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">4. 验证方案与向后兼容</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">5. DOM-like Tree 可行性分析</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">6. 统一架构演进路线</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">7. 生命周期与可扩展性</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">8. 框架对比总结</span></div>
        </div>
        <div style="width:480px">
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">9. Widget 四层能力分类</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">10. RenderContext 渲染抽象</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">11. 差距分析与模板语法</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">12. 分阶段实施路线图</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">13. 双线演进总览</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">14. 依赖关系详解</span></div>
          <div style="height:20px;display:flex;align-items:center"><span style="font-size:13px;color:#a78bfa">15. 实施顺序与关键决策</span></div>
        </div>
      </div>
    </div>

    <!-- Section 1: Grid-2 layout with colored cards -->
    <div style="margin:0 32px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">1. 当前问题与目标</div>
    </div>
    <div style="margin:0 32px 12px 32px;display:flex;flex-direction:row;gap:16px">
      <!-- Danger Card -->
      <div style="flex:1;background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:4px">问题根因</div>
        <div style="font-size:14px;color:#e6edf3;margin-bottom:8px">框架缺少渲染层 (Layer/Z-Order) 概念。</div>
        <div style="font-size:14px;font-weight:600;color:#e6edf3">具体表现:</div>
        <div style="font-size:14px;color:#e6edf3;margin-top:4px">1. 紧耦合: 父组件必须知道所有弹窗状态</div>
        <div style="font-size:14px;color:#e6edf3">2. 不可扩展: 新增弹窗导致所有元素加逆条件</div>
        <div style="font-size:14px;color:#e6edf3">3. 不支持多层: 弹窗A上弹窗B复杂度指数增长</div>
        <div style="font-size:14px;color:#e6edf3">4. 点击穿透 Bug: 不检查 condition</div>
      </div>
      <!-- Success Card -->
      <div style="flex:1;background:#161b22;border:1px solid #30363d;border-left:3px solid #22c55e;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:4px">解决目标</div>
        <div style="font-size:14px;color:#e6edf3;margin-bottom:8px">实现声明式的 overlay layer 系统:</div>
        <div style="font-size:14px;color:#e6edf3">- 开发者只需在弹窗组件上加 overlay 属性</div>
        <div style="font-size:14px;color:#e6edf3">- 不再需要手写 v-if="!showDialog"</div>
        <div style="font-size:14px;color:#e6edf3">- 点击自动优先命中上层元素</div>
        <div style="font-size:14px;color:#e6edf3">- 天然支持多层叠加</div>
        <div style="font-size:14px;color:#e6edf3">- 100% 向后兼容</div>
      </div>
    </div>

    <!-- Normal Card (code block style) -->
    <div style="margin:0 32px 24px 32px;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:20px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">当前双重条件控制的痛点</div>
      <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px">
        <span style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace">&lt;num-pad x="0" y="80" v-if="!showDialog" /&gt;    ← 主界面隐藏</span>
      </div>
    </div>

    <!-- Section 2: Section with accent cards -->
    <div style="margin:0 32px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">2. Overlay Layer 核心设计</div>
    </div>
    <div style="margin:0 32px 12px 32px">
      <div style="font-size:16px;font-weight:600;color:#e6edf3;margin-bottom:12px">2.1 核心思路: 分层累积渲染 + 分层点击</div>
      <div style="font-size:14px;color:#e6edf3;margin-bottom:8px">每个 element/button 携带一个 layer 整数:</div>
      <div style="font-size:14px;color:#e6edf3">- Layer 0: 默认基础层 (主界面内容)</div>
      <div style="font-size:14px;color:#e6edf3;margin-bottom:12px">- Layer 1+: 叠加层 (弹窗、tooltip 等), 编译时自动分配</div>
    </div>

    <!-- Grid-2 accent cards -->
    <div style="margin:0 32px 16px 32px;display:flex;flex-direction:row;gap:16px">
      <div style="flex:1;background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">渲染策略 — 累积式</div>
        <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px">
          <span style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace">Layer 0 → Layer 1 → Layer 2</span>
        </div>
      </div>
      <div style="flex:1;background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">点击策略 — 层过滤</div>
        <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px">
          <span style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace">确定 maxActiveLayer → 仅检查该层按钮</span>
        </div>
      </div>
    </div>

    <!-- Highlight/Blockquote area -->
    <div style="margin:0 32px 16px 32px;background:rgba(124,58,237,0.06);border:1px solid rgba(124,58,237,0.2);border-radius:8px;padding:16px 20px">
      <div style="border-left:3px solid #7c3aed;padding:12px 16px">
        <div style="font-size:14px;color:#e6edf3">没有 condition 的按钮视为 "chrome" 按钮，永远可渲染、可点击，不受 layer 屏蔽影响。</div>
      </div>
      <div style="font-size:14px;color:#8b949e;margin-top:8px;padding-left:19px">NumPad 按钮带有 condition → 被正常屏蔽。"?" 按钮没有 condition → 是 chrome 按钮，始终可用。</div>
    </div>

    <!-- Section: Table -->
    <div style="margin:0 32px">
      <div style="font-size:16px;font-weight:600;color:#e6edf3;margin-bottom:12px">5.2 为什么选择拍平: 硬约束</div>
    </div>
    <div style="margin:0 32px 24px 32px;background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden">
      <!-- Table Header -->
      <div style="display:flex;flex-direction:row;background:#161b22;border-bottom:1px solid #30363d">
        <div style="width:300px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">缺失的 GDI 能力</div>
        <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3">在树结构中的角色</div>
      </div>
      <!-- Table Row 1 -->
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:300px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">SaveDC / RestoreDC</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">进入/退出子节点时保存/恢复坐标变换</div>
      </div>
      <!-- Table Row 2 -->
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:300px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">SetViewportOrgEx</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">子节点使用相对坐标 (父坐标系)</div>
      </div>
      <!-- Table Row 3 -->
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:300px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">SelectClipRgn</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">子节点裁剪到父容器范围内</div>
      </div>
      <!-- Table Row 4 -->
      <div style="display:flex;flex-direction:row">
        <div style="width:300px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">Alpha / RGBA 混合</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">半透明叠加效果</div>
      </div>
    </div>

    <!-- Section: Milestone Timeline -->
    <div style="margin:0 32px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">6. 统一架构演进路线</div>
    </div>

    <!-- Milestone items with flex row layout simulating timeline -->
    <div style="margin:0 32px 12px 32px;display:flex;flex-direction:row;gap:8px;align-items:flex-start">
      <!-- Timeline dot -->
      <div style="width:24px;height:24px;border-radius:12px;background:rgba(34,197,94,0.15);border:2px solid #22c55e;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px">
        <span style="font-size:10px;font-weight:700;color:#22c55e">●</span>
      </div>
      <div style="flex:1">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#22c55e;background:rgba(34,197,94,0.15);padding:2px 8px;border-radius:4px">当前</span>
          <span style="font-size:14px;font-weight:600;color:#e6edf3">v5 M3: Flat + Layer</span>
        </div>
        <div style="display:flex;flex-direction:row;gap:8px;margin-top:8px">
          <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">数据结构</span> <span style="font-size:11px;color:#e6edf3">+layer 字段</span></div>
          <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">渲染行为</span> <span style="font-size:11px;color:#e6edf3">分层渲染+点击</span></div>
        </div>
        <div style="font-size:12px;color:#8b949e;margin-top:4px">~100 行 / 7 文件 | 解决: overlay v-if 双重耦合</div>
      </div>
    </div>

    <div style="margin:0 32px 12px 32px;display:flex;flex-direction:row;gap:8px;align-items:flex-start">
      <div style="width:24px;height:24px;border-radius:12px;border:2px solid #30363d;background:#0d1117;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px">
      </div>
      <div style="flex:1">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#3b82f6;background:rgba(59,130,246,0.15);padding:2px 8px;border-radius:4px">下一步</span>
          <span style="font-size:14px;font-weight:600;color:#e6edf3">v5 M4: Groups + Incremental Rendering</span>
        </div>
        <div style="display:flex;flex-direction:row;gap:8px;margin-top:8px">
          <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">数据结构</span> <span style="font-size:11px;color:#e6edf3">+group_id</span></div>
          <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">渲染行为</span> <span style="font-size:11px;color:#e6edf3">ChangeQueue 增量</span></div>
        </div>
        <div style="font-size:12px;color:#8b949e;margin-top:4px">~80 行 / 4 文件 | 收益: O(n)→O(dirty)</div>
      </div>
    </div>

    <div style="margin:0 32px 12px 32px;display:flex;flex-direction:row;gap:8px;align-items:flex-start">
      <div style="width:24px;height:24px;border-radius:12px;border:2px solid #30363d;background:#0d1117;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px">
      </div>
      <div style="flex:1">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#a78bfa;background:rgba(124,58,237,0.15);padding:2px 8px;border-radius:4px">v6</span>
          <span style="font-size:14px;font-weight:600;color:#e6edf3">v6 M1: 分段布局 + 按需 Attach</span>
        </div>
        <div style="display:flex;flex-direction:row;gap:8px;margin-top:8px">
          <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">数据结构</span> <span style="font-size:11px;color:#e6edf3">拆分为 getLayout_X()</span></div>
          <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">生命周期</span> <span style="font-size:11px;color:#e6edf3">onAttach/onDetach</span></div>
        </div>
        <div style="font-size:12px;color:#8b949e;margin-top:4px">~300 行 PHP + ~180 行 C++ | 收益: 300→3000 元素无退化</div>
      </div>
    </div>

    <!-- Section: Widget Tier Cards -->
    <div style="margin:0 32px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">9. Widget 四层能力分类</div>
    </div>

    <!-- Tier 1 card -->
    <div style="margin:0 32px 8px 32px;background:#161b22;border:1px solid #30363d;border-left:3px solid #22c55e;border-radius:8px;padding:20px">
      <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-bottom:8px">
        <div style="width:28px;height:28px;border-radius:14px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center">
          <span style="font-size:13px;font-weight:700;color:#22c55e">1</span>
        </div>
        <span style="font-size:14px;font-weight:600;color:#e6edf3">Tier 1: 已支持/零成本扩展 (当前 v5)</span>
      </div>
      <div style="display:flex;flex-direction:row;gap:4px;flex-wrap:wrap">
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">rect</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">text</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">button</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">grid</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">label</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">separator</span></div>
      </div>
    </div>

    <!-- Tier 2 card -->
    <div style="margin:0 32px 8px 32px;background:#161b22;border:1px solid #30363d;border-left:3px solid #3b82f6;border-radius:8px;padding:20px">
      <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-bottom:8px">
        <div style="width:28px;height:28px;border-radius:14px;background:rgba(59,130,246,0.15);display:flex;align-items:center;justify-content:center">
          <span style="font-size:13px;font-weight:700;color:#3b82f6">2</span>
        </div>
        <span style="font-size:14px;font-weight:600;color:#e6edf3">Tier 2: 中等 GDI 扩展 + AST 新增 (v6 M1)</span>
      </div>
      <div style="display:flex;flex-direction:row;gap:4px;flex-wrap:wrap">
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">image</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">input</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">textarea</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">dropdown</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">checkbox</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">radio</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">tooltip</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">groupbox</span></div>
      </div>
    </div>

    <!-- Tier 3 card -->
    <div style="margin:0 32px 8px 32px;background:#161b22;border:1px solid #30363d;border-left:3px solid #f59e0b;border-radius:8px;padding:20px">
      <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-bottom:8px">
        <div style="width:28px;height:28px;border-radius:14px;background:rgba(245,158,11,0.15);display:flex;align-items:center;justify-content:center">
          <span style="font-size:13px;font-weight:700;color:#f59e0b">3</span>
        </div>
        <span style="font-size:14px;font-weight:600;color:#e6edf3">Tier 3: 高级 GDI + 状态管理 + 拖拽交互 (v6 M2)</span>
      </div>
      <div style="display:flex;flex-direction:row;gap:4px;flex-wrap:wrap">
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">slider</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">list/table</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">scrollbar</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">tabs</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">split-pane</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">progress-bar</span></div>
      </div>
    </div>

    <!-- Tier 4 card -->
    <div style="margin:0 32px 16px 32px;background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px">
      <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-bottom:8px">
        <div style="width:28px;height:28px;border-radius:14px;background:rgba(239,68,68,0.15);display:flex;align-items:center;justify-content:center">
          <span style="font-size:13px;font-weight:700;color:#ef4444">4</span>
        </div>
        <span style="font-size:14px;font-weight:600;color:#e6edf3">Tier 4: 复杂组合组件 (v6 M3+)</span>
      </div>
      <div style="display:flex;flex-direction:row;gap:4px;flex-wrap:wrap">
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">menubar</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">combobox</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">datagrid</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">tree</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">richtext</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">datepicker</span></div>
      </div>
    </div>

    <!-- Section: RenderContext abstract class code -->
    <div style="margin:0 32px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">10. RenderContext 后端无关渲染接口</div>
    </div>
    <div style="margin:0 32px 16px 32px;background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">设计目标</div>
      <div style="font-size:14px;color:#e6edf3">对齐 Skia SkCanvas、Flutter Canvas、HTML5 CanvasRenderingContext2D。框架面向抽象基类编程，后端可切换 (GDI → Skia → Web Canvas)。</div>
    </div>

    <!-- Code block (pre) -->
    <div style="margin:0 32px 16px 32px;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px 20px">
      <div style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace;white-space:pre">abstract class RenderContext {
    abstract public function save(): void;
    abstract public function restore(): void;
    abstract public function translate(float $dx, float $dy): void;
    abstract public function clipRect(float $x, float $y, float $w, float $h): void;
    abstract public function fillRect(float $x, float $y, float $w, float $h): void;
    abstract public function strokeRect(float $x, float $y, float $w, float $h): void;
    abstract public function drawText(float $x, float $y, string $text): void;
    abstract public function drawLine(float $x1, float $y1, float $x2, float $y2): void;
    // ... 26 methods total
}</div>
    </div>

    <!-- Section: Dependency boxes -->
    <div style="margin:0 32px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">14. 依赖关系详解</div>
    </div>

    <!-- Dep box: v5 M3 -->
    <div style="margin:0 32px 12px 32px;border:1px solid #30363d;border-radius:8px;overflow:hidden">
      <div style="background:rgba(34,197,94,0.1);padding:8px 14px;font-weight:600;font-size:13px;color:#e6edf3;border-bottom:1px solid #30363d">v5 M3: Flat + Layer</div>
      <div style="padding:12px 14px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">框架提供:</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- layer 字段 (每个 element/button 携带层级编号)</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- maxActiveLayer (运行时确定最高活跃层)</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- 两阶段渲染 + 分层点击</div>
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-top:8px;margin-bottom:4px">应用解锁:</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- overlay 属性 → 弹窗不再需要 inverse condition</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- 多层叠加天然支持</div>
      </div>
    </div>

    <!-- Dep box: v6 M1 -->
    <div style="margin:0 32px 12px 32px;border:1px solid #30363d;border-radius:8px;overflow:hidden">
      <div style="background:rgba(124,58,237,0.1);padding:8px 14px;font-weight:600;font-size:13px;color:#e6edf3;border-bottom:1px solid #30363d">v6 M1: 分段布局 + RenderContext → [Tier 2 Widget]</div>
      <div style="padding:12px 14px">
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-bottom:4px">框架提供:</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- 编译拆分 getLayout_X()</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- RenderContext (26方法)</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- 8 GDI + 7 事件</div>
        <div style="font-size:13px;font-weight:600;color:#e6edf3;margin-top:8px;margin-bottom:4px">Widget 解锁:</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- image: onAttach → LoadImage</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- input: 分段布局 + KEYDOWN/CHAR</div>
        <div style="font-size:13px;color:#e6edf3;margin-left:16px">- dropdown: overlay (基于 layer) + clipRect</div>
      </div>
    </div>

    <!-- Section: CSS Grid test (grid-template-columns) -->
    <div style="margin:0 32px">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">11. 差距分析</div>
    </div>
    <div style="margin:0 32px 24px 32px;background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden">
      <!-- Comparison table -->
      <div style="display:flex;flex-direction:row;background:#161b22;border-bottom:1px solid #30363d">
        <div style="width:150px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">维度</div>
        <div style="width:350px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">当前状态 (v5)</div>
        <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3">目标状态 (v6+)</div>
      </div>
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:150px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">GDI 原语</div>
        <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">5 个</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">13 个 (+8: line, image, oval...)</div>
      </div>
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:150px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">AST 节点</div>
        <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">4 种 + 2 元节点</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">15+ 种 (+11 个 widget 节点)</div>
      </div>
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:150px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">布局系统</div>
        <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">编译期扁平计算，无自适应</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">编译期 + 运行时动态布局</div>
      </div>
      <div style="display:flex;flex-direction:row">
        <div style="width:150px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">CSS 映射</div>
        <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">8 属性</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">18+ (border-radius, opacity, cursor...)</div>
      </div>
    </div>

    <!-- CSS Grid-3 layout test -->
    <div style="margin:0 32px">
      <div style="font-size:16px;font-weight:600;color:#e6edf3;margin-bottom:12px">关键设计原则</div>
    </div>
    <div style="margin:0 32px 24px 32px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
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

    <!-- Footer -->
    <div style="margin:0 32px;padding:48px 0 32px 0;border-top:1px solid #30363d;display:flex;justify-content:center">
      <span style="font-size:12px;color:#8b949e">VueCalc Framework Architecture Roadmap</span>
    </div>
  </div>
</template>

<script lang="php">
    /** 滚动位置 */
    public string $scrollTop = '0';
</script>
