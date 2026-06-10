<template>
  <!-- Level 0: 页面容器 - 测试全局页面布局、标题层级、段落间距 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">
    <div style="font-size:28px;font-weight:700;color:#e6edf3;margin-bottom:4px">VueCalc 框架架构演进路线图</div>
    <div style="font-size:15px;color:#8b949e;margin-bottom:32px">副标题文字示例</div>

    <div style="font-size:20px;font-weight:600;color:#e6edf3;padding-bottom:8px;border-bottom:2px solid #30363d;margin-top:48px;margin-bottom:16px">1. 一级标题示例</div>
    <div style="font-size:16px;font-weight:600;color:#e6edf3;margin-top:32px;margin-bottom:12px">1.1 二级标题示例</div>
    <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-top:16px;margin-bottom:8px">三级标题示例</div>

    <div style="font-size:14px;color:#e6edf3;margin-top:8px;margin-bottom:8px">这是一个段落文本，用于测试正常的文字渲染和行高。line-height: 1.7 应该让每行之间有合适的间距。</div>
    <div style="font-size:14px;color:#e6edf3;margin-top:8px;margin-bottom:8px">这是第二个段落，包含一些长文本用于测试多行显示效果。Px 框架的布局引擎应该能够正确处理文字换行和行间距，确保可读性良好。</div>

    <div style="font-size:14px;color:#e6edf3;margin-top:8px;margin-bottom:8px">无序列表:</div>
    <div style="padding-left:24px;margin-top:8px;margin-bottom:8px">
      <div style="font-size:14px;color:#e6edf3;margin-top:4px;margin-bottom:4px">• 列表项 1</div>
      <div style="font-size:14px;color:#e6edf3;margin-top:4px;margin-bottom:4px">• 列表项 2</div>
      <div style="font-size:14px;color:#e6edf3;margin-top:4px;margin-bottom:4px">• 列表项 3</div>
    </div>

    <div style="font-size:14px;color:#e6edf3;margin-top:8px;margin-bottom:8px">有序列表:</div>
    <div style="padding-left:24px;margin-top:8px;margin-bottom:8px">
      <div style="font-size:14px;color:#e6edf3;margin-top:4px;margin-bottom:4px">1. 第一步</div>
      <div style="font-size:14px;color:#e6edf3;margin-top:4px;margin-bottom:4px">2. 第二步</div>
      <div style="font-size:14px;color:#e6edf3;margin-top:4px;margin-bottom:4px">3. 第三步</div>
    </div>
  </div>
</template>
<script lang="php">
class Level0_Container extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
