<template>
  <div style="width:1920px;height:1000px;display:flex;flex-direction:column;background:#0B0B0E">

    <!-- Page Header -->
    <div style="padding:20px 24px 12px 24px;border-bottom:1px solid #1E1E24">
      <span style="font-size:16px;font-weight:600;color:#FFFFFF">色彩体系</span>
      <span style="margin-left:10px;font-size:10px;color:#52525B">Color System — 完整的语义化色彩令牌</span>
    </div>

    <div style="display:flex;flex-direction:column;gap:0;padding:16px 24px">

      <!-- Primary Indigo -->
      <div style="background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Primary — Indigo</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">主色调，用于品牌色、主要按钮、选中态</span>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:8px">
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#818CF8;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Light</span>
            <span style="font-size:8px;color:#52525B">#818CF8</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#6366F1;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Default</span>
            <span style="font-size:8px;color:#52525B">#6366F1</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#4F46E5;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Dark</span>
            <span style="font-size:8px;color:#52525B">#4F46E5</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#4338CA;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Darker</span>
            <span style="font-size:8px;color:#52525B">#4338CA</span>
          </div>
        </div>
      </div>

      <!-- Semantic Colors -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Semantic — 语义色</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">用于不同的状态反馈（成功/警告/错误/信息）</span>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:8px">
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#10B981;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Success</span>
            <span style="font-size:8px;color:#52525B">#10B981</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#F59E0B;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Warning</span>
            <span style="font-size:8px;color:#52525B">#F59E0B</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#EF4444;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Error</span>
            <span style="font-size:8px;color:#52525B">#EF4444</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:48px;background:#3B82F6;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Info</span>
            <span style="font-size:8px;color:#52525B">#3B82F6</span>
          </div>
        </div>
      </div>

      <!-- Extended Semantic Colors -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Extended — 扩展语义色</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">Magenta / Purple / Cyan / Orange / Lime</span>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:8px">
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:40px;background:#EC4899;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Magenta</span>
            <span style="font-size:8px;color:#52525B">#EC4899</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:40px;background:#8B5CF6;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Purple</span>
            <span style="font-size:8px;color:#52525B">#8B5CF6</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:40px;background:#06B6D4;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Cyan</span>
            <span style="font-size:8px;color:#52525B">#06B6D4</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:40px;background:#F97316;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Orange</span>
            <span style="font-size:8px;color:#52525B">#F97316</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:40px;background:#84CC16;border-radius:8px"></div>
            <span style="margin-top:6px;font-size:10px;color:#D1D5DB">Lime</span>
            <span style="font-size:8px;color:#52525B">#84CC16</span>
          </div>
        </div>
      </div>

      <!-- Neutral Gray Scale -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Neutral — 中性色阶</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">Gray 50 ~ 900 完整色阶</span>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#FAFAFA;border-radius:6px;border:1px solid #27272A"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">50</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#F4F4F5;border-radius:6px;border:1px solid #27272A"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">100</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#E4E4E7;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">200</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#D4D4D8;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">300</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#A1A1AA;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">400</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#71717A;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#D1D5DB">500</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#52525B;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#D1D5DB">600</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#3F3F46;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#D1D5DB">700</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#27272A;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#D1D5DB">800</span>
          </div>
          <div style="width:60px;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#18181B;border-radius:6px;border:1px solid #27272A"></div>
            <span style="margin-top:4px;font-size:9px;color:#D1D5DB">900</span>
          </div>
        </div>
      </div>

      <!-- Dark Theme Background Colors -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Dark Theme — 暗色主题</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">当前应用使用的背景色层级</span>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:8px">
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#121215;border-radius:6px;border:1px solid #1E1E24"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">Surface</span>
            <span style="font-size:8px;color:#52525B">#121215</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#0B0B0E;border-radius:6px;border:1px solid #1E1E24"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">Background</span>
            <span style="font-size:8px;color:#52525B">#0B0B0E</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#18181B;border-radius:6px;border:1px solid #27272A"></div>
            <span style="margin-top:4px;font-size:9px;color:#A1A1AA">Elevated</span>
            <span style="font-size:8px;color:#52525B">#18181B</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#1E1E24;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#D1D5DB">Border</span>
            <span style="font-size:8px;color:#52525B">#1E1E24</span>
          </div>
          <div style="flex:1;display:flex;flex-direction:column;align-items:center">
            <div style="width:100%;height:36px;background:#27272A;border-radius:6px"></div>
            <span style="margin-top:4px;font-size:9px;color:#D1D5DB">Border 2</span>
            <span style="font-size:8px;color:#52525B">#27272A</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">
class ColorPaletteComponent extends ReactiveComponent
{
}
</script>

<style>
</style>
