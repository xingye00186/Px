<template>
  <!-- Level 2: 卡片系统 - 测试6种彩色左边框卡片 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">

    <!-- 默认卡片 -->
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">默认卡片</div>
      <div style="font-size:14px;color:#e6edf3">没有左边框的常规卡片。</div>
    </div>

    <!-- 强调色卡片 (card-accent) -->
    <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #7c3aed;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">强调卡片</div>
      <div style="font-size:14px;color:#e6edf3">紫色左边框，用于强调显示。</div>
    </div>

    <!-- 成功卡片 (card-success) -->
    <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #22c55e;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">成功卡片</div>
      <div style="font-size:14px;color:#e6edf3">绿色左边框，表示成功状态。</div>
    </div>

    <!-- 警告卡片 (card-warning) -->
    <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #f59e0b;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">警告卡片</div>
      <div style="font-size:14px;color:#e6edf3">黄色左边框，表示警告状态。</div>
    </div>

    <!-- 危险卡片 (card-danger) -->
    <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #ef4444;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">危险卡片</div>
      <div style="font-size:14px;color:#e6edf3">红色左边框，表示错误状态。</div>
    </div>

    <!-- 信息卡片 (card-info) -->
    <div style="background:#161b22;border:1px solid #30363d;border-left:3px solid #3b82f6;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">信息卡片</div>
      <div style="font-size:14px;color:#e6edf3">蓝色左边框，表示信息提示。</div>
    </div>

    <!-- 带代码块的卡片 -->
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;font-weight:600;color:#e6edf3;margin-bottom:8px">含代码的卡片</div>
      <div style="background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:16px">
        <div style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace">卡片内部的代码块</div>
      </div>
    </div>
  </div>
</template>
<script lang="php">
class Level2_Cards extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
