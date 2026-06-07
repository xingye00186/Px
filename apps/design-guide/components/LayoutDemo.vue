<template>
  <div style="width:1920px;height:1000px;display:flex;flex-direction:column;background:#0B0B0E">

    <!-- Page Header -->
    <div style="padding:20px 24px 12px 24px;border-bottom:1px solid #1E1E24">
      <span style="font-size:16px;font-weight:600;color:#FFFFFF">布局系统</span>
      <span style="margin-left:10px;font-size:10px;color:#52525B">Layout — Flexbox · Grid · Block · Gap</span>
    </div>

    <div style="display:flex;flex-direction:column;gap:0;padding:16px 24px">

      <!-- Row 1: Flex Center + Flex Space Between -->
      <div style="display:flex;flex-direction:row;gap:8px">

        <!-- Flex Center -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Flex Center</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">align-items + justify-content</span>
          <div style="margin-top:10px;height:60px;background:#18181B;border-radius:8px;display:flex;align-items:center;justify-content:center">
            <div style="width:100px;height:32px;background:#6366F1;border-radius:6px;display:flex;align-items:center;justify-content:center">
              <span style="color:#FFFFFF;font-size:11px;font-weight:500">居中</span>
            </div>
          </div>
        </div>

        <!-- Flex Space Between -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Flex Space-Between</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">两端对齐排列</span>
          <div style="margin-top:10px;height:40px;background:#18181B;border-radius:8px;padding:0 14px;display:flex;align-items:center">
            <span style="font-size:11px;font-weight:500;color:#D1D5DB">左侧</span>
            <div style="flex:1"></div>
            <span style="font-size:11px;font-weight:500;color:#D1D5DB">右侧</span>
          </div>
        </div>
      </div>

      <!-- Row 2: Flex Row Gap -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Flex Row Gap</span>
          <span style="font-size:9px;color:#52525B">弹性比例布局 (flex: 1 / 2 / 1)</span>
          <div style="flex:1"></div>
          <span style="font-size:9px;color:#71717A">gap: 8px</span>
        </div>
        <div style="margin-top:10px;background:#18181B;border-radius:8px;padding:10px;display:flex;flex-direction:row;gap:8px">
          <div style="flex:1;background:#27272A;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
            <span style="color:#A1A1AA;font-size:11px">flex:1</span>
          </div>
          <div style="flex:2;background:#6366F1;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:11px;font-weight:500">flex:2 (主要)</span>
          </div>
          <div style="flex:1;background:#27272A;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
            <span style="color:#A1A1AA;font-size:11px">flex:1</span>
          </div>
        </div>
      </div>

      <!-- Row 3: Grid Layouts -->
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px">

        <!-- Grid 3 Columns -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Grid 3 列</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">grid-template-columns:repeat(3,1fr)</span>
          <div style="margin-top:10px;background:#18181B;border-radius:8px;padding:10px;display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
            <div style="background:#27272A;border-radius:6px;padding:12px;display:flex;align-items:center;justify-content:center">
              <span style="color:#A1A1AA;font-size:10px">1</span>
            </div>
            <div style="background:#27272A;border-radius:6px;padding:12px;display:flex;align-items:center;justify-content:center">
              <span style="color:#A1A1AA;font-size:10px">2</span>
            </div>
            <div style="background:#27272A;border-radius:6px;padding:12px;display:flex;align-items:center;justify-content:center">
              <span style="color:#A1A1AA;font-size:10px">3</span>
            </div>
            <div style="background:#27272A;border-radius:6px;padding:12px;display:flex;align-items:center;justify-content:center">
              <span style="color:#A1A1AA;font-size:10px">4</span>
            </div>
            <div style="background:#6366F1;border-radius:6px;padding:12px;display:flex;align-items:center;justify-content:center">
              <span style="color:#FFFFFF;font-size:10px;font-weight:500">5</span>
            </div>
            <div style="background:#27272A;border-radius:6px;padding:12px;display:flex;align-items:center;justify-content:center">
              <span style="color:#A1A1AA;font-size:10px">6</span>
            </div>
          </div>
        </div>

        <!-- Grid 4 Columns -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Grid 4 列</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">不均匀列宽 (2fr 1fr 1fr 2fr)</span>
          <div style="margin-top:10px;background:#18181B;border-radius:8px;padding:10px;display:grid;grid-template-columns:2fr 1fr 1fr 2fr;gap:6px">
            <div style="background:#6366F1;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
              <span style="color:#FFFFFF;font-size:10px;font-weight:500">2fr</span>
            </div>
            <div style="background:#27272A;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
              <span style="color:#A1A1AA;font-size:10px">1fr</span>
            </div>
            <div style="background:#27272A;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
              <span style="color:#A1A1AA;font-size:10px">1fr</span>
            </div>
            <div style="background:#10B981;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center">
              <span style="color:#FFFFFF;font-size:10px;font-weight:500">2fr</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Row 4: Stack Vertical + Gap Demo -->
      <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px">

        <!-- Stack Vertical -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Flex Column 堆叠</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">flex-direction:column + gap</span>
          <div style="margin-top:10px;background:#18181B;border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:6px">
            <div style="background:#27272A;border-radius:4px;padding:8px 12px">
              <span style="color:#A1A1AA;font-size:11px">Row 1 — 标题行</span>
            </div>
            <div style="background:#6366F1;border-radius:4px;padding:8px 12px">
              <span style="color:#FFFFFF;font-size:11px;font-weight:500">Row 2 — 活跃行</span>
            </div>
            <div style="background:#27272A;border-radius:4px;padding:8px 12px">
              <span style="color:#A1A1AA;font-size:11px">Row 3 — 内容行</span>
            </div>
            <div style="background:#27272A;border-radius:4px;padding:8px 12px">
              <span style="color:#A1A1AA;font-size:11px">Row 4 — 底部行</span>
            </div>
          </div>
        </div>

        <!-- Gap Demo -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Gap 间距对比</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">不同的 gap 间距大小</span>
          <div style="margin-top:10px;background:#18181B;border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:8px">
            <div style="display:flex;flex-direction:row;gap:4px">
              <div style="width:40px;height:24px;background:#27272A;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#71717A;font-size:8px">4px</span>
              </div>
              <div style="width:40px;height:24px;background:#27272A;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#71717A;font-size:8px">4px</span>
              </div>
              <div style="width:40px;height:24px;background:#27272A;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#71717A;font-size:8px">4px</span>
              </div>
            </div>
            <div style="display:flex;flex-direction:row;gap:8px">
              <div style="width:40px;height:24px;background:#374151;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#A1A1AA;font-size:8px">8px</span>
              </div>
              <div style="width:40px;height:24px;background:#374151;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#A1A1AA;font-size:8px">8px</span>
              </div>
              <div style="width:40px;height:24px;background:#374151;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#A1A1AA;font-size:8px">8px</span>
              </div>
            </div>
            <div style="display:flex;flex-direction:row;gap:16px">
              <div style="width:60px;height:24px;background:#6366F1;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#FFFFFF;font-size:8px;font-weight:500">16px</span>
              </div>
              <div style="width:60px;height:24px;background:#6366F1;border-radius:4px;display:flex;align-items:center;justify-content:center">
                <span style="color:#FFFFFF;font-size:8px;font-weight:500">16px</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Row 5: Flex Wrap -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Flex Wrap 换行</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">flex-wrap:wrap 响应式换行</span>
        <div style="margin-top:10px;background:#18181B;border-radius:8px;padding:10px;display:flex;flex-direction:row;gap:8px;flex-wrap:wrap">
          <div style="width:80px;height:32px;background:#6366F1;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:10px;font-weight:500">Item 1</span>
          </div>
          <div style="width:80px;height:32px;background:#10B981;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:10px;font-weight:500">Item 2</span>
          </div>
          <div style="width:80px;height:32px;background:#F59E0B;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:10px;font-weight:500">Item 3</span>
          </div>
          <div style="width:80px;height:32px;background:#EF4444;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:10px;font-weight:500">Item 4</span>
          </div>
          <div style="width:80px;height:32px;background:#3B82F6;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:10px;font-weight:500">Item 5</span>
          </div>
          <div style="width:80px;height:32px;background:#8B5CF6;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:10px;font-weight:500">Item 6</span>
          </div>
          <div style="width:80px;height:32px;background:#EC4899;border-radius:6px;display:flex;align-items:center;justify-content:center">
            <span style="color:#FFFFFF;font-size:10px;font-weight:500">Item 7</span>
          </div>
        </div>
      </div>

      <!-- Spacer -->
      <div style="height:24px"></div>
    </div>
  </div>
</template>

<script lang="php">
class LayoutDemoComponent extends ReactiveComponent
{
}
</script>

<style>
</style>
