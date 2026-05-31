<template>
  <div style="left:0px;top:0px;width:420px">
    <!-- Display -->
    <div style="left:16px;top:16px;width:388px;display:flex;flex-direction:row;gap:16px;padding-bottom:12px;border-bottom:1px solid #333336">
      <span style="width:72px;font-size:11px;color:#6B7280">Display</span>
      <span style="font-size:32px;font-weight:600;color:#FFFFFF">The quick brown fox</span>
    </div>

    <!-- Headline -->
    <div style="left:16px;top:72px;width:388px;display:flex;flex-direction:row;gap:16px;padding-bottom:12px;border-bottom:1px solid #333336">
      <span style="width:72px;font-size:11px;color:#6B7280">Headline</span>
      <span style="font-size:24px;font-weight:600;color:#FFFFFF">The quick brown fox</span>
    </div>

    <!-- Title -->
    <div style="left:16px;top:128px;width:388px;display:flex;flex-direction:row;gap:16px;padding-bottom:12px;border-bottom:1px solid #333336">
      <span style="width:72px;font-size:11px;color:#6B7280">Title</span>
      <span style="font-size:18px;font-weight:500;color:#D1D5DB">The quick brown fox</span>
    </div>

    <!-- Body -->
    <div style="left:16px;top:184px;width:388px;display:flex;flex-direction:row;gap:16px;padding-bottom:12px;border-bottom:1px solid #333336">
      <span style="width:72px;font-size:11px;color:#6B7280">Body</span>
      <span style="font-size:14px;font-weight:400;color:#D1D5DB">The quick brown fox jumps over</span>
    </div>

    <!-- Label -->
    <div style="left:16px;top:240px;width:388px;display:flex;flex-direction:row;gap:16px;padding-bottom:12px;border-bottom:1px solid #333336">
      <span style="width:72px;font-size:11px;color:#6B7280">Label</span>
      <span style="font-size:12px;font-weight:500;color:#FFFFFF;letter-spacing:0.5px">THE QUICK BROWN FOX</span>
    </div>

    <!-- Caption -->
    <div style="left:16px;top:296px;width:388px;display:flex;flex-direction:row;gap:16px;padding-bottom:12px;border-bottom:1px solid #333336">
      <span style="width:72px;font-size:11px;color:#6B7280">Caption</span>
      <span style="font-size:11px;font-weight:400;color:#9CA3AF">The quick brown fox jumps over</span>
    </div>

    <!-- Weight Grid -->
    <div style="left:16px;top:352px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Font Weight</div>
      <div style="left:0px;top:32px;display:flex;flex-direction:row;gap:12px">
        <div style="flex:1;background:#282840;border-radius:8px;padding:12px">
          <span style="font-size:14px;font-weight:400;color:#FFFFFF">Regular 400</span>
        </div>
        <div style="flex:1;background:#282840;border-radius:8px;padding:12px">
          <span style="font-size:14px;font-weight:500;color:#FFFFFF">Medium 500</span>
        </div>
      </div>
      <div style="left:0px;top:56px;display:flex;flex-direction:row;gap:12px">
        <div style="flex:1;background:#282840;border-radius:8px;padding:12px">
          <span style="font-size:14px;font-weight:600;color:#FFFFFF">Semibold 600</span>
        </div>
        <div style="flex:1;background:#282840;border-radius:8px;padding:12px">
          <span style="font-size:14px;font-weight:700;color:#FFFFFF">Bold 700</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">
class TypographyShowcaseComponent extends ReactiveComponent
{
}
</script>