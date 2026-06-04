<template>
  <div style="width:1920px;display:flex;flex-direction:column;gap:0">

    <!-- Primary Buttons -->
    <div style="margin:16px 16px 0 16px;height:70px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Primary</div>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
        <button style="padding:8px 16px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Default</button>
        <button style="padding:8px 16px;background:#4F46E5;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Hover</button>
        <button style="padding:8px 16px;background:#4338CA;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Pressed</button>
        <button style="padding:8px 16px;background:#4338CA;color:#6B7280;border:none;border-radius:6px;font-size:12px;font-weight:500">Disabled</button>
      </div>
    </div>

    <!-- Secondary Buttons -->
    <div style="margin:16px 16px 0 16px;height:70px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Secondary</div>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
        <button style="padding:8px 16px;background:#374151;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Default</button>
        <button style="padding:8px 16px;background:#4B5563;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Hover</button>
        <button style="padding:8px 16px;background:#1F2937;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Pressed</button>
        <button style="padding:8px 16px;background:#374151;color:#6B7280;border:none;border-radius:6px;font-size:12px;font-weight:500">Disabled</button>
      </div>
    </div>

    <!-- Outline Buttons -->
    <div style="margin:16px 16px 0 16px;height:70px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Outline</div>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
        <button style="padding:8px 16px;background:transparent;color:#6366F1;border:2px solid #6366F1;border-radius:6px;font-size:12px;font-weight:500">Default</button>
        <button style="padding:8px 16px;background:#1F1F3E;color:#818CF8;border:2px solid #818CF8;border-radius:6px;font-size:12px;font-weight:500">Hover</button>
        <button style="padding:8px 16px;background:#2A2A4E;color:#6366F1;border:2px solid #6366F1;border-radius:6px;font-size:12px;font-weight:500">Pressed</button>
        <button style="padding:8px 16px;background:transparent;color:#6B7280;border:2px solid #374151;border-radius:6px;font-size:12px;font-weight:500">Disabled</button>
      </div>
    </div>

    <!-- Danger Button -->
    <div style="margin:16px 16px 0 16px;height:70px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Danger</div>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:6px">
        <button style="padding:8px 16px;background:#EF4444;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Delete</button>
        <button style="padding:8px 16px;background:#DC2626;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Remove</button>
      </div>
    </div>

    <!-- Button Sizes -->
    <div style="margin:16px 16px 0 16px;height:70px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Sizes</div>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="padding:4px 10px;background:#6366F1;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;font-weight:500">Small</button>
        <button style="padding:8px 16px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Medium</button>
        <button style="padding:12px 24px;background:#6366F1;color:#FFFFFF;border:none;border-radius:8px;font-size:14px;font-weight:500">Large</button>
      </div>
    </div>

    <!-- Icon Buttons -->
    <div style="margin:16px 16px 0 16px;height:70px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Icon Buttons</div>
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="width:36px;height:36px;background:#6366F1;color:#FFFFFF;border:none;border-radius:50%;font-size:18px">+</button>
        <button style="width:36px;height:36px;background:#374151;color:#FFFFFF;border:none;border-radius:50%;font-size:18px">-</button>
        <button style="padding:8px 16px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:12px;font-weight:500">Save</button>
      </div>
    </div>

    <!-- Spacer -->
    <div style="height:16px"></div>
  </div>
</template>

<script lang="php">
class ButtonShowcaseComponent extends ReactiveComponent
{
}
</script>
