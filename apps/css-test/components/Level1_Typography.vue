<template>
  <!-- Level 1: 排版元素 - 测试 code/pre/blockquote/a 样式 -->
  <div style="width:1100px;padding:32px;background:#0d1117;color:#e6edf3;font-size:14px;line-height:1.7;box-sizing:border-box">

    <!-- 内联代码 -->
    <div style="font-size:14px;color:#e6edf3;margin-bottom:12px">
      这是一个包含
      <span style="font-size:12px;font-family:Consolas,monospace;background:#161b22;padding:2px 6px;border-radius:4px;border:1px solid #30363d;color:#e6edf3">内联代码</span>
      的段落。
    </div>

    <!-- 代码块 (pre > code) -->
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px 20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace;white-space:pre">function hello() {
    return "Hello World";
}</div>
    </div>

    <!-- 引用块 (blockquote) -->
    <div style="border-left:3px solid #7c3aed;padding:12px 16px;margin-top:12px;margin-bottom:12px;background:rgba(124,58,237,0.04);border-radius:0 8px 8px 0">
      <div style="font-size:14px;color:#e6edf3">这是一个引用块。它有一个紫色的左边框和浅紫色的背景。边框半径为右侧8px，左侧0。</div>
    </div>

    <!-- 链接 -->
    <div style="font-size:14px;color:#a78bfa;margin-top:12px;margin-bottom:12px">这是一个链接示例文字</div>

    <!-- 高亮块 (highlight) -->
    <div style="background:rgba(124,58,237,0.06);border:1px solid rgba(124,58,237,0.2);border-radius:8px;padding:16px 20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:14px;color:#e6edf3">高亮块内容，带浅紫色背景和紫色边框。</div>
    </div>

    <!-- 多代码块连续 -->
    <div style="background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px 20px;margin-top:12px;margin-bottom:12px">
      <div style="font-size:12px;color:#e6edf3;font-family:Consolas,monospace;white-space:pre">abstract class RenderContext {
    abstract public function save(): void;
    abstract public function restore(): void;
    abstract public function translate(float $dx, float $dy): void;
}</div>
    </div>
  </div>
</template>
<script lang="php">
class Level1_Typography extends ReactiveComponent
{
    // render() 由 SFC 编译器自动从 <template> 生成
}
</script>
