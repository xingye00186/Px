<template>
  <!-- Level 5: 表格 - 用div模拟的表格布局测试 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">

    <!-- 受影响文件汇总表 -->
    <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">受影响文件汇总</div>
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden;margin-bottom:24px">
      <!-- Table header -->
      <div style="display:flex;flex-direction:row;background:#161b22;border-bottom:1px solid #30363d">
        <div style="width:40px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">#</div>
        <div style="width:350px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">文件</div>
        <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3">说明</div>
      </div>
      <!-- Table rows -->
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:40px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">1</div>
        <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">framework/compiler/ast-nodes.php</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">layer, isOverlay 属性</div>
      </div>
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:40px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">2</div>
        <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">framework/compiler/template-parser.php</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">overlay 解析 + layer 输出</div>
      </div>
      <div style="display:flex;flex-direction:row">
        <div style="width:40px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">3</div>
        <div style="width:350px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-family:Consolas,monospace">framework/sfc-compiler.php</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">layer 分配逻辑</div>
      </div>
    </div>

    <!-- 框架对比表 -->
    <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">框架对比</div>
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden;margin-bottom:24px">
      <div style="display:flex;flex-direction:row;background:#161b22;border-bottom:1px solid #30363d">
        <div style="width:120px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">维度</div>
        <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">Dear ImGui</div>
        <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3">VueCalc</div>
      </div>
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:120px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">数据表示</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">逐帧 draw list</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">编译时 flat 数组</div>
      </div>
      <div style="display:flex;flex-direction:row">
        <div style="width:120px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">Hit-test</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">逆序窗口迭代</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">按 layer 逆序</div>
      </div>
    </div>

    <!-- 差距分析表 -->
    <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-bottom:16px">差距分析</div>
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;overflow:hidden;margin-bottom:24px">
      <div style="display:flex;flex-direction:row;background:#161b22;border-bottom:1px solid #30363d">
        <div style="width:120px;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">维度</div>
        <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">当前状态 (v5)</div>
        <div style="flex:1;padding:8px 12px;font-weight:600;font-size:13px;color:#e6edf3">目标状态 (v6+)</div>
      </div>
      <div style="display:flex;flex-direction:row;border-bottom:1px solid #30363d">
        <div style="width:120px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">GDI 原语</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">5 个</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">13 个</div>
      </div>
      <div style="display:flex;flex-direction:row">
        <div style="width:120px;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d;font-weight:600">CSS 映射</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3;border-right:1px solid #30363d">8 属性</div>
        <div style="flex:1;padding:8px 12px;font-size:13px;color:#e6edf3">18+ 属性</div>
      </div>
    </div>
  </div>
</template>
<script lang="php">
class Level5_Tables extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
