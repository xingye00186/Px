<template>
  <div style="left:0px;top:0px;width:318px;height:30px;background:#2C2C2E">
    <span style="left:8px;top:6px;font-size:13px;color:#8E8E93;cursor:pointer" @click="toggleHistory">{{ arrow }} History</span>
    <span style="left:270px;top:6px;font-size:13px;color:#FF9F0A;cursor:pointer" @click="clearHistory">Clear</span>
  </div>
</template>

<script lang="php">
    public string $arrow = '>';
</script>

<style>
.history-header { background: #2C2C2E; }
</style>
