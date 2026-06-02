<template>
  <div style="left:0px;top:0px;width:1920px;display:flex;flex-direction:column;gap:0px">

    <!-- Flex Center -->
    <div style="margin:16px 16px 0 16px;height:90px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Flex Center</div>
      <div style="margin-top:8px;height:64px;background:#1F1F23;border-radius:8px;display:flex;align-items:center;justify-content:center">
        <div style="width:100px;height:36px;background:#6366F1;border-radius:6px;display:flex;align-items:center;justify-content:center">
          <span style="color:#FFFFFF;font-size:12px;font-weight:500">Centered</span>
        </div>
      </div>
    </div>

    <!-- Flex Space Between -->
    <div style="margin:16px 16px 0 16px;height:80px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Flex Space Between</div>
      <div style="margin-top:8px;height:48px;background:#1F1F23;border-radius:8px;padding:0 16px;display:flex;align-items:center">
        <span style="font-size:12px;font-weight:500;color:#FFFFFF">Left</span>
        <div style="flex:1"></div>
        <span style="font-size:12px;font-weight:500;color:#FFFFFF">Right</span>
      </div>
    </div>

    <!-- Flex Row with Gap -->
    <div style="margin:16px 16px 0 16px;height:90px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Flex Row Gap</div>
      <div style="margin-top:8px;background:#1F1F23;border-radius:8px;padding:12px;display:flex;flex-direction:row;gap:8px">
        <div style="flex:1;background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:11px">A</span>
        </div>
        <div style="flex:2;background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:11px">B (flex:2)</span>
        </div>
        <div style="flex:1;background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:11px">C</span>
        </div>
      </div>
    </div>

    <!-- Grid 3 Columns -->
    <div style="margin:16px 16px 0 16px;height:120px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Grid 3 Columns</div>
      <div style="margin-top:8px;background:#1F1F23;border-radius:8px;padding:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
        <div style="background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:10px">1</span>
        </div>
        <div style="background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:10px">2</span>
        </div>
        <div style="background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:10px">3</span>
        </div>
        <div style="background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:10px">4</span>
        </div>
        <div style="background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:10px">5</span>
        </div>
        <div style="background:#374151;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
          <span style="color:#D1D5DB;font-size:10px">6</span>
        </div>
      </div>
    </div>

    <!-- Stack Vertical -->
    <div style="margin:16px 16px 0 16px;height:150px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Stack Vertical (flex column)</div>
      <div style="margin-top:8px;background:#1F1F23;border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:6px">
        <div style="background:#374151;border-radius:4px;padding:8px 12px">
          <span style="color:#D1D5DB;font-size:12px">Row 1</span>
        </div>
        <div style="background:#374151;border-radius:4px;padding:8px 12px">
          <span style="color:#D1D5DB;font-size:12px">Row 2</span>
        </div>
        <div style="background:#374151;border-radius:4px;padding:8px 12px">
          <span style="color:#D1D5DB;font-size:12px">Row 3</span>
        </div>
      </div>
    </div>

    <!-- Gap Demo -->
    <div style="margin:16px 16px 0 16px;height:90px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">Gap Demo</div>
      <div style="margin-top:8px;background:#1F1F23;border-radius:8px;padding:12px;display:flex;flex-direction:row;gap:12px;flex-wrap:wrap">
        <div style="background:#374151;border-radius:4px;padding:6px 12px">
          <span style="color:#9CA3AF;font-size:10px">8px</span>
        </div>
        <div style="background:#374151;border-radius:4px;padding:6px 12px">
          <span style="color:#9CA3AF;font-size:10px">16px</span>
        </div>
        <div style="background:#374151;border-radius:4px;padding:6px 12px">
          <span style="color:#9CA3AF;font-size:10px">24px</span>
        </div>
        <div style="background:#374151;border-radius:4px;padding:6px 12px">
          <span style="color:#9CA3AF;font-size:10px">32px</span>
        </div>
      </div>
    </div>

    <!-- Spacer -->
    <div style="height:16px"></div>
  </div>
</template>

<script lang="php">
class LayoutDemoComponent extends ReactiveComponent
{
}
</script>
