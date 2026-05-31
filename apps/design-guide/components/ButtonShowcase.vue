<template>
  <div style="left:0px;top:0px;width:420px">
    <!-- Primary Buttons -->
    <div style="left:16px;top:16px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Primary</div>
      <div style="left:0px;top:36px;display:flex;flex-direction:row;gap:8px;flex-wrap:wrap">
        <button style="padding:10px 20px;background:#6366F1;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Default</button>
        <button style="padding:10px 20px;background:#4F46E5;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Hover</button>
        <button style="padding:10px 20px;background:#4338CA;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Pressed</button>
        <button style="padding:10px 20px;background:#4338CA;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none;opacity:0.5">Disabled</button>
      </div>
    </div>

    <!-- Secondary Buttons -->
    <div style="left:16px;top:120px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Secondary</div>
      <div style="left:0px;top:36px;display:flex;flex-direction:row;gap:8px;flex-wrap:wrap">
        <button style="padding:10px 20px;background:#374151;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Default</button>
        <button style="padding:10px 20px;background:#4B5563;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Hover</button>
        <button style="padding:10px 20px;background:#1F2937;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Pressed</button>
        <button style="padding:10px 20px;background:#374151;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none;opacity:0.5">Disabled</button>
      </div>
    </div>

    <!-- Outline Buttons -->
    <div style="left:16px;top:224px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Outline</div>
      <div style="left:0px;top:36px;display:flex;flex-direction:row;gap:8px;flex-wrap:wrap">
        <button style="padding:10px 20px;background:transparent;color:#6366F1;border-radius:8px;font-size:14px;font-weight:500;border:2px solid #6366F1">Default</button>
        <button style="padding:10px 20px;background:rgba(99,102,241,0.1);color:#6366F1;border-radius:8px;font-size:14px;font-weight:500;border:2px solid #6366F1">Hover</button>
        <button style="padding:10px 20px;background:rgba(99,102,241,0.2);color:#6366F1;border-radius:8px;font-size:14px;font-weight:500;border:2px solid #6366F1">Pressed</button>
        <button style="padding:10px 20px;background:transparent;color:#6366F1;border-radius:8px;font-size:14px;font-weight:500;border:2px solid #6366F1;opacity:0.5">Disabled</button>
      </div>
    </div>

    <!-- Danger Button -->
    <div style="left:16px;top:328px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Danger</div>
      <div style="left:0px;top:36px;display:flex;flex-direction:row;gap:8px">
        <button style="padding:10px 20px;background:#EF4444;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Delete Item</button>
      </div>
    </div>

    <!-- Button Sizes -->
    <div style="left:16px;top:432px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Sizes</div>
      <div style="left:0px;top:36px;display:flex;flex-direction:row;gap:12px;align-items:center">
        <button style="padding:6px 12px;background:#6366F1;color:#FFFFFF;border-radius:8px;font-size:12px;font-weight:500;border:none">Small</button>
        <button style="padding:10px 20px;background:#6366F1;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Medium</button>
        <button style="padding:14px 28px;background:#6366F1;color:#FFFFFF;border-radius:8px;font-size:16px;font-weight:500;border:none">Large</button>
      </div>
    </div>

    <!-- Icon Buttons -->
    <div style="left:16px;top:536px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">With Icons</div>
      <div style="left:0px;top:36px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="width:40px;height:40px;background:#6366F1;color:#FFFFFF;border-radius:50%;font-size:20px;border:none">+</button>
        <button style="width:40px;height:40px;background:#374151;color:#FFFFFF;border-radius:50%;font-size:20px;border:none">-</button>
        <button style="padding:10px 20px;background:#6366F1;color:#FFFFFF;border-radius:8px;font-size:14px;font-weight:500;border:none">Save</button>
      </div>
    </div>
  </div>
</template>

<script lang="php">
class ButtonShowcaseComponent extends ReactiveComponent
{
}
</script>