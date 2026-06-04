<template>
  <div style="width:1920px;display:flex;flex-direction:column;gap:0">

    <!-- Section Title -->
    <div style="margin:16px 16px 0 16px;height:30px;width:1888px;padding-bottom:8px;border-bottom:1px solid #2A2A2E">
      <span style="font-size:13px;font-weight:600;color:#FFFFFF">卡片组件 Card</span>
    </div>

    <!-- Basic Card -->
    <div style="margin:12px 16px 0 16px;height:80px;width:1888px;background:#1F1F23;border-radius:10px;padding:14px">
      <div style="font-size:14px;font-weight:600;color:#FFFFFF">Basic Card</div>
      <div style="margin-top:8px;font-size:12px;color:#9CA3AF">A basic card with subtle styling for standard content display.</div>
    </div>

    <!-- Elevated Card -->
    <div style="margin:12px 16px 0 16px;height:120px;width:1888px;background:#1F1F23;border-radius:10px;padding:14px">
      <div style="display:flex;flex-direction:row;justify-content:space-between;align-items:center">
        <span style="font-size:14px;font-weight:600;color:#FFFFFF">Elevated Card</span>
        <span style="background:#6366F1;color:#FFFFFF;font-size:9px;font-weight:600;padding:2px 8px;border-radius:10px">NEW</span>
      </div>
      <div style="margin-top:8px;font-size:12px;color:#9CA3AF">Card with action buttons and a badge for extra prominence.</div>
      <div style="margin-top:10px;display:flex;flex-direction:row;justify-content:flex-end;gap:6px">
        <button style="background:transparent;color:#9CA3AF;border:none;padding:6px 12px;font-size:11px">Cancel</button>
        <button style="background:#6366F1;color:#FFFFFF;border:none;padding:6px 12px;border-radius:5px;font-size:11px">Confirm</button>
      </div>
    </div>

    <!-- Outlined Card -->
    <div style="margin:12px 16px 0 16px;height:80px;width:1888px;background:transparent;border-radius:10px;padding:14px;border:1px solid #2A2A2E">
      <div style="font-size:14px;font-weight:600;color:#FFFFFF">Outlined Card</div>
      <div style="margin-top:8px;font-size:12px;color:#9CA3AF">A card with border instead of background fill for a lighter look.</div>
    </div>

    <!-- Media Card -->
    <div style="margin:12px 16px 16px 16px;height:140px;width:1888px;background:#1F1F23;border-radius:10px;padding:14px">
      <div style="height:60px;background:#6366F1;border-radius:6px;display:flex;align-items:center;justify-content:center">
        <span style="color:#FFFFFF;font-size:10px;font-weight:500">MEDIA AREA</span>
      </div>
      <div style="margin-top:10px;font-size:14px;font-weight:600;color:#FFFFFF">Media Card</div>
      <div style="margin-top:4px;font-size:12px;color:#9CA3AF">Cards can include a media area for images or featured content.</div>
    </div>

    <!-- Spacer -->
    <div style="height:16px"></div>
  </div>
</template>

<script lang="php">
class CardShowcaseComponent extends ReactiveComponent
{
}
</script>
