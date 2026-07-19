<template>
  <div style="width:318px;height:30px;background:#2C2C2E;display:flex;align-items:center;justify-content:space-between;box-sizing:border-box;padding:0 8px">
    <span style="font-size:13px;color:#8E8E93;cursor:pointer" @click="toggleHistory">{{ arrow }} History</span>
    <span style="font-size:13px;color:#FF9F0A;cursor:pointer" @click="clearHistory">Clear</span>
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $arrow = '>';
</script>

<style>
.history-header { background: #2C2C2E; }
</style>
