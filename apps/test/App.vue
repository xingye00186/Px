<template>
  <div style="left:0px;top:0px;width:640px;height:480px" title="VueCalc v6 M4 Test">
    <div style="left:0px;top:0px;width:640px;height:480px" class="main-bg"></div>

    <!-- 顶部工具栏 (Flex布局) -->
    <div style="display:flex;flex-direction:row;left:0px;top:0px;width:640px;height:40px;gap:8px" class="toolbar">
      <span style="left:10px;top:10px;font-size:16px;color:#FFFFFF" class="title-text">{{ titleText }}</span>
    </div>

    <!-- 文本编辑区域 -->
    <input style="left:10px;top:50px;width:620px;height:380px"
           v-model="content"
           placeholder="在此输入文本..."
           class="editor"
           @keydown="onKeyDown"
           @enter="onEnter" />

    <!-- 底部状态栏 (Flex布局) -->
    <div style="display:flex;flex-direction:row;left:0px;top:440px;width:640px;height:40px;gap:8px" class="statusbar">
      <span style="left:10px;top:450px;font-size:12px;color:#FFFFFF" class="status-text">{{ statusText }}</span>
    </div>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $titleText = 'VueCalc v6 M4 Test';
    public string $content = 'Hello, VueCalc!';
    public string $statusText = 'Ready | Press Enter to submit';

    public function onKeyDown(string $action, int $keyCode, string $char): void
    {
        $this->statusText = 'Key down: ' . $keyCode . ($char !== '' ? ' char:' . $char : '');
    }

    public function onEnter(string $action, int $keyCode, string $char): void
    {
        $len = strlen($this->content);
        $preview = $len > 20 ? substr($this->content, 0, 20) . '...' : $this->content;
        $this->statusText = 'Submitted: ' . $preview;
    }
}
</script>

<style>
#app { background: #1E1E1E; }
.main-bg { background: #1E1E1E; }
.toolbar { background: #2D2D2D; }
.title-text { color: #FFFFFF; }
.editor { background: #252526; color: #D4D4D4; font-size: 14px; }
.statusbar { background: #007ACC; }
.status-text { color: #FFFFFF; }
</style>
