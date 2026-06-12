<template>
  <!-- Level 7: Full Integration - 完整集成测试 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">

    <!-- Level 0: Container -->
    <div style="width:1100px;padding:32px;background:#0d1117">
      <div style="font-size:28px;font-weight:700;color:#e6edf3;margin-bottom:4px">VueCalc 框架架构演进路线图</div>
      <div style="font-size:15px;color:#8b949e;margin-bottom:32px">副标题文字示例</div>
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-top:48px;margin-bottom:16px">1. 一级标题示例</div>
      <div style="font-size:16px;font-weight:600;color:#e6edf3;margin-top:32px;margin-bottom:12px">1.1 二级标题示例</div>
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-top:16px;margin-bottom:8px">三级标题示例</div>
      <div style="font-size:14px;color:#e6edf3;margin-top:8px;margin-bottom:8px">这是一个段落文本，用于测试正常的文字渲染和行高。</div>
      <div style="font-size:14px;color:#e6edf3;margin-top:8px;margin-bottom:8px">这是第二个段落，包含一些长文本用于测试多行显示效果。</div>
    </div>
    <div style="margin:0 32px;border-top:1px solid #30363d"></div>

    <!-- Level 1: Typography -->
    <div style="width:1100px;padding:32px;background:#0d1117">
      <div style="font-size:14px;color:#e6edf3;margin-bottom:12px">
        这是一个包含
        <span style="font-size:12px;font-family:Consolas,monospace;background:#161b22;padding:2px 6px;border-radius:4px;border:1px solid #30363d;color:#e6edf3">内联代码</span>
        的段落。
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px 20px;margin-top:12px;margin-bottom:12px">
        <div style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace;white-space:pre">function hello() {
    return "Hello World";
}</div>
      </div>
      <div style="border-left:3px solid #7c3aed;padding:12px 16px;margin-top:12px;margin-bottom:12px;background:rgba(124,58,237,0.04);border-radius:0 8px 8px 0">
        <div style="font-size:14px;color:#e6edf3">这是一个引用块。它有一个紫色的左边框和浅紫色的背景。</div>
      </div>
    </div>
    <div style="margin:0 32px;border-top:1px solid #30363d"></div>

    <!-- Level 2: Cards -->
    <div style="width:1100px;padding:32px;background:#0d1117">
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">默认卡片</div>
        <div style="font-size:14px;color:#e6edf3">没有左边框的常规卡片。</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">强调卡片</div>
        <div style="font-size:14px;color:#e6edf3">紫色左边框，用于强调显示。</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #22c55e;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">成功卡片</div>
        <div style="font-size:14px;color:#e6edf3">绿色左边框，表示成功状态。</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #f59e0b;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">警告卡片</div>
        <div style="font-size:14px;color:#e6edf3">黄色左边框，表示警告状态。</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">危险卡片</div>
        <div style="font-size:14px;color:#e6edf3">红色左边框，表示错误状态。</div>
      </div>
      <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #3b82f6;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
        <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">信息卡片</div>
        <div style="font-size:14px;color:#e6edf3">蓝色左边框，表示信息提示。</div>
      </div>
    </div>
    <div style="margin:0 32px;border-top:1px solid #30363d"></div>

    <!-- Level 3: Flex -->
    <div style="width:1100px;padding:32px;background:#0d1117">
      <div style="display:flex;flex-direction:row;align-items:center;gap:8px;margin-bottom:8px;margin-top:16px">
        <div style="width:28px;height:28px;border-radius:14px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <span style="font-size:13px;font-weight:700;color:#22c55e">1</span>
        </div>
        <span style="font-size:14px;font-weight:600;color:#e6edf3">Tier 1: 已支持/零成本扩展</span>
      </div>
      <div style="display:flex;flex-direction:row;gap:4px;flex-wrap:wrap;margin-top:12px;margin-bottom:12px">
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">rect</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">text</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">button</span></div>
        <div style="background:#1c2128;padding:2px 6px;border-radius:3px"><span style="font-size:11px;color:#8b949e">grid</span></div>
      </div>
    </div>
    <div style="margin:0 32px;border-top:1px solid #30363d"></div>

    <!-- Level 4: Grid -->
    <div style="width:1100px;padding:32px;background:#0d1117">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">Grid 布局测试</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">
        <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px">
          <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">左侧卡片</div>
          <div style="font-size:14px;color:#e6edf3">红色左边框，占 grid 左侧一列。</div>
        </div>
        <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #22c55e;border-radius:8px;padding:20px">
          <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">右侧卡片</div>
          <div style="font-size:14px;color:#e6edf3">绿色左边框，占 grid 右侧一列。</div>
        </div>
      </div>
    </div>
    <div style="margin:0 32px;border-top:1px solid #30363d"></div>

    <!-- Level 5: Tables -->
    <div style="width:1100px;padding:32px;background:#0d1117">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">受影响文件汇总</div>
      <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden;margin-bottom:24px">
        <div style="display:flex;flex-direction:row;background:#161b22;border-bottom:1px solid #30363d">
          <div style="width:40px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">#</div>
          <div style="width:350px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">文件</div>
          <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3">说明</div>
        </div>
        <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
          <div style="width:40px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">1</div>
          <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">framework/compiler/ast-nodes.php</div>
          <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">layer, isOverlay 属性</div>
        </div>
        <div style="display:flex;flex-direction:row">
          <div style="width:40px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">3</div>
          <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">framework/sfc-compiler.php</div>
          <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">layer 分配逻辑</div>
        </div>
      </div>
    </div>
    <div style="margin:0 32px;border-top:1px solid #30363d"></div>

    <!-- Level 6: Special -->
    <div style="width:1100px;padding:32px;background:#0d1117">
      <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">特殊组件测试</div>
      <div style="border:1px solid #30363d;border-radius:8px;overflow:hidden;margin-bottom:16px">
        <div style="background:rgba(34,197,94,0.1);padding:8px 14px;font-weight:600;font-size:13px;color:#e6edf3;border-bottom:1px solid #30363d">v5 M3: Flat + Layer</div>
        <div style="padding:12px 14px">
          <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- layer 字段 (每个 element 携带层级编号)</div>
          <div style="font-size:13px;color:#e6edf3;margin-left:16px;padding:2px 0">- maxActiveLayer (运行时确定最高活跃层)</div>
        </div>
      </div>
      <div style="margin-top:48px;padding-top:32px;border-top:1px solid #30363d;display:flex;justify-content:center">
        <span style="font-size:12px;color:#8b949e">VueCalc Framework Architecture Roadmap</span>
      </div>
    </div>

  </div>
</template>
<script lang="php">
class Level7_Integration extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
