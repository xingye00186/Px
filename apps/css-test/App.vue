<template>
  <div class="test-sandbox" style="width:1600px;height:800px;background:#f5f5f5;overflow-y:auto;">
    <div class="sandbox-header" style="height:40px;background:#fff;border-bottom:1px solid #ddd;padding:0 20px;display:flex;align-items:center;font-size:14px;color:#666;">
      CSS Test Sandbox — <span style="color:#333;font-weight:bold;">Test Case</span>
    </div>
    <div style="padding:20px;">
      <component :is="caseName" />
    </div>
  </div>
</template>
<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $caseName = '';

    public function onMount(): void
    {
        global $argv;
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--case=')) {
                $this->caseName = substr($arg, 7);
            }
        }
    }
}
</script>
