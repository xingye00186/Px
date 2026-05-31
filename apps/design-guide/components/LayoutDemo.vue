<template>
  <div style="left:0px;top:0px;width:420px">
    <!-- Flex Center -->
    <div style="left:16px;top:16px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Flex Center</div>
      <div style="left:0px;top:32px;width:388px;height:80px;background:#282840;border-radius:12px;padding:24px;display:flex;align-items:center;justify-content:center">
        <div style="width:120px;height:48px;background:#6366F1;border-radius:8px;display:flex;align-items:center;justify-content:center">
          <span style="color:#FFFFFF;font-size:14px;font-weight:500">Centered</span>
        </div>
      </div>
    </div>

    <!-- Flex Space Between -->
    <div style="left:16px;top:152px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Flex Space Between</div>
      <div style="left:0px;top:32px;width:388px;height:80px;background:#282840;border-radius:12px;padding:24px;display:flex;align-items:center">
        <span style="font-size:14px;font-weight:500;color:#FFFFFF">Left</span>
        <div style="flex:1"></div>
        <span style="font-size:14px;font-weight:500;color:#FFFFFF">Right</span>
      </div>
    </div>

    <!-- Grid Layout -->
    <div style="left:16px;top:288px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Grid 3 Columns</div>
      <div style="left:0px;top:32px;width:388px;background:#282840;border-radius:12px;padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
        <div style="background:#374151;border-radius:8px;padding:16px;text-align:center;color:#FFFFFF;font-size:12px">Item 1</div>
        <div style="background:#374151;border-radius:8px;padding:16px;text-align:center;color:#FFFFFF;font-size:12px">Item 2</div>
        <div style="background:#374151;border-radius:8px;padding:16px;text-align:center;color:#FFFFFF;font-size:12px">Item 3</div>
        <div style="background:#374151;border-radius:8px;padding:16px;text-align:center;color:#FFFFFF;font-size:12px">Item 4</div>
        <div style="background:#374151;border-radius:8px;padding:16px;text-align:center;color:#FFFFFF;font-size:12px">Item 5</div>
        <div style="background:#374151;border-radius:8px;padding:16px;text-align:center;color:#FFFFFF;font-size:12px">Item 6</div>
      </div>
    </div>

    <!-- Stack Vertical -->
    <div style="left:16px;top:440px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Stack Vertical</div>
      <div style="left:0px;top:32px;width:388px;background:#282840;border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:8px">
        <div style="background:#374151;border-radius:6px;padding:12px 16px;color:#FFFFFF;font-size:14px">Item 1</div>
        <div style="background:#374151;border-radius:6px;padding:12px 16px;color:#FFFFFF;font-size:14px">Item 2</div>
        <div style="background:#374151;border-radius:6px;padding:12px 16px;color:#FFFFFF;font-size:14px">Item 3</div>
      </div>
    </div>

    <!-- Gap Demo -->
    <div style="left:16px;top:576px;width:388px">
      <div style="left:0px;top:0px;width:388px;font-size:14px;font-weight:600;color:#FFFFFF">Gap 16px</div>
      <div style="left:0px;top:32px;width:388px;background:#282840;border-radius:12px;padding:16px;display:flex;flex-direction:row;gap:16px;flex-wrap:wrap">
        <div style="background:#374151;border-radius:6px;padding:8px 16px;color:#9CA3AF;font-size:12px">8px</div>
        <div style="background:#374151;border-radius:6px;padding:8px 16px;color:#9CA3AF;font-size:12px">16px</div>
        <div style="background:#374151;border-radius:6px;padding:8px 16px;color:#9CA3AF;font-size:12px">24px</div>
        <div style="background:#374151;border-radius:6px;padding:8px 16px;color:#9CA3AF;font-size:12px">32px</div>
      </div>
    </div>
  </div>
</template>

<script lang="php">
class LayoutDemoComponent extends ReactiveComponent
{
}
</script>