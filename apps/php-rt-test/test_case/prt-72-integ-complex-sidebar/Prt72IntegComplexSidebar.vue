<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="width:620px;background:#f5f5f5;border:1px solid #ccc;">
      <div style="height:44px;background:#1e1e2e;display:flex;align-items:center;padding:0 12px;gap:12px;">
        <div style="background:#45475a;width:60px;height:28px;border-radius:4px;"></div>
        <div style="flex:1;max-width:200px;">
          <input style="width:100%;height:28px;background:#313244;border:1px solid #585b70;border-radius:4px;color:#cdd6f4;padding:0 8px;font-size:13px;" placeholder="Search...">
        </div>
        <div style="background:#585b70;width:28px;height:28px;border-radius:50%;"></div>
      </div>
      <div style="display:flex;">
        <div style="width:180px;background:#181825;min-height:300px;display:flex;flex-direction:column;">
          <div style="padding:12px 8px;display:flex;flex-direction:column;gap:4px;">
            <div style="height:32px;background:#313244;border-radius:4px;display:flex;align-items:center;padding:0 8px;"><span style="font-size:13px;color:#cdd6f4;">Dashboard</span></div>
            <div style="height:32px;background:#45475a;border-radius:4px;display:flex;align-items:center;padding:0 8px;"><span style="font-size:13px;color:#cdd6f4;">Analytics</span></div>
            <div style="height:32px;background:#45475a;border-radius:4px;display:flex;align-items:center;padding:0 8px;"><span style="font-size:13px;color:#cdd6f4;">Settings</span></div>
          </div>
          <div style="border-top:1px solid #313244;padding:8px;display:flex;flex-direction:column;gap:4px;">
            <div style="height:32px;background:#45475a;border-radius:4px;display:flex;align-items:center;padding:0 8px;"><span style="font-size:13px;color:#6c7086;">Help</span></div>
          </div>
        </div>
        <div style="flex:1;padding:12px;">
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:12px;">
            <div style="height:60px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:8px;display:flex;flex-direction:column;justify-content:center;padding:8px;">
              <span style="font-size:12px;color:rgba(255,255,255,0.7);">Total Users</span>
              <span style="font-size:20px;font-weight:700;color:#fff;">2,847</span>
            </div>
            <div style="height:60px;background:linear-gradient(135deg,#f093fb,#f5576c);border-radius:8px;display:flex;flex-direction:column;justify-content:center;padding:8px;">
              <span style="font-size:12px;color:rgba(255,255,255,0.7);">Revenue</span>
              <span style="font-size:20px;font-weight:700;color:#fff;">$12.4k</span>
            </div>
          </div>
          <div style="display:flex;gap:12px;">
            <div style="flex:2;height:160px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px;">
              <span style="font-size:14px;font-weight:600;color:#333;">Chart</span>
              <div style="display:flex;gap:4px;align-items:flex-end;height:100px;margin-top:8px;">
                <div style="flex:1;height:60px;background:#667eea;border-radius:3px;"></div>
                <div style="flex:1;height:40px;background:#667eea;border-radius:3px;"></div>
                <div style="flex:1;height:80px;background:#667eea;border-radius:3px;"></div>
                <div style="flex:1;height:50px;background:#667eea;border-radius:3px;"></div>
                <div style="flex:1;height:70px;background:#667eea;border-radius:3px;"></div>
              </div>
            </div>
            <div style="flex:1;height:160px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:6px;">
              <span style="font-size:14px;font-weight:600;color:#333;">Activity</span>
              <div style="display:flex;gap:8px;align-items:center;">
                <div style="width:8px;height:8px;border-radius:50%;background:#4caf50;"></div>
                <span style="font-size:13px;color:#666;">12 new signups</span>
              </div>
              <div style="display:flex;gap:8px;align-items:center;">
                <div style="width:8px;height:8px;border-radius:50%;background:#2196f3;"></div>
                <span style="font-size:13px;color:#666;">5 new orders</span>
              </div>
              <div style="display:flex;gap:8px;align-items:center;">
                <div style="width:8px;height:8px;border-radius:50%;background:#ff9800;"></div>
                <span style="font-size:13px;color:#666;">3 pending tasks</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt72IntegComplexSidebar extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
