<template>
  <div style="width:328px;height:420px;z-index:1">
    <!-- 半透明覆盖层 (modal overlay) -->
    <div style="left:0px;top:0px;width:328px;height:420px" class="dialog-overlay" @click="toggleAboutDialog"></div>

    <!-- 对话框面板背景 -->
    <div style="left:24px;top:110px;width:280px;height:200px" class="dialog-box"></div>

    <!-- 对话框标题 -->
    <span style="left:24px;top:135px;text-align:center;width:280px" class="dialog-title">{{ dialogTitle }}</span>

    <!-- 分隔线 -->
    <div style="left:44px;top:155px;width:240px;height:2px" class="dialog-divider"></div>

    <!-- 对话框内容 -->
    <span style="left:24px;top:180px;text-align:center;width:280px" class="dialog-text">{{ dialogContent }}</span>

    <!-- 版本号 -->
    <span style="left:24px;top:215px;text-align:center;width:280px" class="dialog-version">{{ dialogVersion }}</span>

    <!-- 关闭按钮 -->
    <button style="left:114px;top:265px;width:100px;height:34px" class="btn-op" @click="toggleAboutDialog">Close</button>
  </div>
</template>

<style>
.dialog-overlay { background: #0a0a0a; }
.dialog-box     { background: #2d2d2d; }
.dialog-title   { font-size: 20px; color: #ffffff; font-weight: bold; }
.dialog-divider { background: #ff9500; }
.dialog-text    { font-size: 15px; color: #cccccc; }
.dialog-version { font-size: 13px; color: #888888; }
.dialog-hint    { font-size: 12px; color: #666666; }
</style>
