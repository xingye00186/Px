<template>
  <div style="left:0px;top:0px;width:400px;height:300px" title="v-else Test">
    <div style="left:0px;top:0px;width:400px;height:300px;background:#2D2D2D">
      <div style="left:10px;top:10px;width:380px;height:40px;background:#444">
        <span style="font-size:14px;color:#FFF">{{ statusText }}</span>
      </div>

      <!-- Test v-if + v-else -->
      <div v-if="status === 'A'" style="left:10px;top:60px;width:100px;height:30px;background:#4CAF50">
        <span>Status A</span>
      </div>
      <div v-else-if="status === 'B'" style="left:10px;top:60px;width:100px;height:30px;background:#FFC107">
        <span>Status B</span>
      </div>
      <div v-else style="left:10px;top:60px;width:100px;height:30px;background:#F44336">
        <span>Status C</span>
      </div>

      <!-- Test :class directive -->
      <div :class="status === 'A' ? 'active' : 'inactive'"
           style="left:10px;top:100px;width:100px;height:30px">
        <span>Dynamic Class</span>
      </div>

      <!-- Test v-show -->
      <div v-show="isVisible"
           style="left:10px;top:140px;width:100px;height:30px;background:#2196F3">
        <span>Toggle Me</span>
      </div>
    </div>
  </div>
</template>

<script lang="php">
public string $status = "A";
public string $statusText = "Current Status: A";
public bool $isVisible = true;
public bool $isActive = true;

public function setStatus(string $s): void
{
    $this->status = $s;
    $this->statusText = "Current Status: " . $s;
    $this->markDirty();
}

public function toggle(): void
{
    $this->isVisible = !$this->isVisible;
    $this->markDirty();
}
</script>

<style>
.active { font-weight: bold; }
.inactive { font-weight: normal; }
</style>