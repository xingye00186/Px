<template>
  <div class="wrapper-test" style="width:800px;margin:0 auto;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;">
    <div class="test-header" style="font-size:18px;font-weight:600;margin-bottom:16px;color:#333;">
      Centered Wrapper Test (margin: 0 auto)
    </div>
    <div class="test-body" style="font-size:14px;color:#666;line-height:1.6;">
      <p>This container is 800px wide with margin:0 auto.</p>
      <p>The sandbox is 1600px wide with 20px padding.</p>
      <p>Expected x = (1600 - 800 - 2*20) / 2 = 380px</p>
    </div>
    <div class="test-footer" style="margin-top:16px;padding-top:16px;border-top:1px solid #eee;font-size:12px;color:#999;">
      case-001: wrapper x-position verification
    </div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent
{
}
</script>
