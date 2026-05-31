<template>
  <div style="left:0px;top:0px;width:420px">
    <!-- Basic Card -->
    <div style="left:16px;top:16px;width:388px;background:#282840;border-radius:12px;padding:16px">
      <div style="left:0px;top:0px;width:356px;font-size:16px;font-weight:600;color:#FFFFFF">Basic Card</div>
      <div style="left:0px;top:36px;width:356px;font-size:14px;color:#9CA3AF;line-height:1.5">
        This is a basic card with default elevation. It uses a subtle shadow to create depth on the surface.
      </div>
    </div>

    <!-- Elevated Card -->
    <div style="left:16px;top:140px;width:388px;background:#282840;border-radius:12px;padding:16px;box-shadow:0 4px 16px rgba(0,0,0,0.3)">
      <div style="left:0px;top:0px;width:356px;display:flex;flex-direction:row;justify-content:space-between;align-items:center">
        <span style="font-size:16px;font-weight:600;color:#FFFFFF">Elevated Card</span>
        <span style="background:#6366F1;color:#FFFFFF;font-size:10px;font-weight:600;padding:2px 8px;border-radius:12px">New</span>
      </div>
      <div style="left:0px;top:36px;width:356px;font-size:14px;color:#9CA3AF;line-height:1.5">
        This elevated card has a stronger shadow for more prominence. Great for modals and dialogs.
      </div>
      <div style="left:0px;top:96px;width:356px;display:flex;flex-direction:row;justify-content:flex-end;gap:8px">
        <button style="background:transparent;color:#9CA3AF;border:none;padding:8px 16px;font-size:14px">Cancel</button>
        <button style="background:#6366F1;color:#FFFFFF;border:none;padding:8px 16px;border-radius:6px;font-size:14px">Confirm</button>
      </div>
    </div>

    <!-- Outlined Card -->
    <div style="left:16px;top:284px;width:388px;background:transparent;border-radius:12px;padding:16px;border:1px solid #374151">
      <div style="left:0px;top:0px;width:356px;font-size:16px;font-weight:600;color:#FFFFFF">Outlined Card</div>
      <div style="left:0px;top:36px;width:356px;font-size:14px;color:#9CA3AF;line-height:1.5">
        This card uses a border instead of shadow for a flatter appearance.
      </div>
    </div>

    <!-- Media Card -->
    <div style="left:16px;top:428px;width:388px;background:#282840;border-radius:12px;padding:16px">
      <div style="left:0px;top:0px;width:356px;height:80px;background:linear-gradient(135deg,#6366F1,#EC4899);border-radius:8px;margin-bottom:12px"></div>
      <div style="left:0px;top:0px;width:356px;font-size:16px;font-weight:600;color:#FFFFFF">Media Card</div>
      <div style="left:0px;top:36px;width:356px;font-size:14px;color:#9CA3AF;line-height:1.5">
        Cards can include a media area at the top for images or featured content.
      </div>
    </div>
  </div>
</template>

<script lang="php">
class CardShowcaseComponent extends ReactiveComponent
{
}
</script>