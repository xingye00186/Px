<template>
  <div style="width:318px;height:100px;background:#1C1C1E;overflow:hidden;position:relative">
    <span v-if="hasMemory === '1'" style="position:absolute;left:4px;top:4px;font-size:12px;color:#FF9F0A;font-weight:bold">M</span>
    <span style="position:absolute;left:0;top:24px;width:318px;font-size:14px;color:#8E8E93;text-align:right">{{ expression }}</span>
    <span class="display-pulse" style="position:absolute;left:0;top:54px;width:318px;font-size:36px;color:#FFFFFF;font-weight:bold;text-align:right">{{ display }}</span>
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $display = '0';
    #[Reactive]
    public string $expression = '';
    #[Reactive]
    public string $hasMemory = '0';
</script>

<style>
.bg-memory { background: #1C1C1E; }
.expr-text { font-size: 14px; color: #8E8E93; text-align: right; }
.result-text { font-size: 36px; color: #FFFFFF; font-weight: bold; text-align: right; }
</style>
