<template>
  <div style="left:0px;top:0px;width:318px;height:100px;background:#1C1C1E">
    <span v-if="hasMemory === '1'" style="left:4px;top:4px;font-size:12px;color:#FF9F0A;font-weight:bold">M</span>
    <span style="left:0px;top:24px;width:318px;font-size:14px;color:#8E8E93;text-align:right">{{ expression }}</span>
    <span style="left:0px;top:54px;width:318px;font-size:36px;color:#FFFFFF;font-weight:bold;text-align:right">{{ display }}</span>
  </div>
</template>

<script lang="php">
    public string $display = '0';
    public string $expression = '';
    public string $hasMemory = '0';
</script>

<style>
.bg-memory { background: #1C1C1E; }
.expr-text { font-size: 14px; color: #8E8E93; text-align: right; }
.result-text { font-size: 36px; color: #FFFFFF; font-weight: bold; text-align: right; }
</style>
