<template>
  <div style="width:1920px;height:1000px;display:flex;flex-direction:column;background:#0B0B0E">

    <!-- Page Header -->
    <div style="padding:20px 24px 12px 24px;border-bottom:1px solid #1E1E24">
      <span style="font-size:16px;font-weight:600;color:#FFFFFF">动画系统</span>
      <span style="margin-left:10px;font-size:10px;color:#52525B">Animation — Transition · @keyframes · Easing · FLIP</span>
    </div>

    <div style="display:flex;flex-direction:column;gap:0;padding:16px 24px">

      <!-- Row 1: Transition + Color Transition -->
      <div style="display:flex;flex-direction:row;gap:8px">

        <!-- Transition Demo -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">过渡动画</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">transition opacity + transform</span>
          <div style="margin-top:10px;padding:12px;background:#18181B;border-radius:8px;display:flex;flex-direction:row;gap:10px;align-items:center">
            <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="toggleAnim" click-arg="">切换</button>
            <div style="width:56px;height:56px;border-radius:10px;display:flex;align-items:center;justify-content:center;transition:all 0.35s ease" :class="animVisible ? 'anim-box-show' : 'anim-box-hide'">
              <span style="font-size:22px">{{ animVisible ? '😊' : '😴' }}</span>
            </div>
          </div>
          <span style="margin-top:6px;font-size:9px;color:#52525B">点击按钮切换 — opacity + translateX 过渡</span>
        </div>

        <!-- Color Transition -->
        <div style="flex:1;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">颜色过渡</span>
          <span style="margin-left:8px;font-size:9px;color:#52525B">背景色 transition 0.3s ease</span>
          <div style="margin-top:10px;padding:12px;background:#18181B;border-radius:8px;display:flex;flex-direction:row;gap:10px;align-items:center">
            <button style="padding:6px 14px;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer;transition:all 0.3s ease" :class="colorIdx === 0 ? 'cb-indigo' : (colorIdx === 1 ? 'cb-emerald' : 'cb-rose')" @click="cycleColor" click-arg="">
              {{ colorIdx === 0 ? 'Indigo' : (colorIdx === 1 ? 'Emerald' : 'Rose') }}
            </button>
            <div style="width:56px;height:56px;border-radius:10px;display:flex;align-items:center;justify-content:center;transition:all 0.3s ease" :class="colorIdx === 0 ? 'cb-bg-indigo' : (colorIdx === 1 ? 'cb-bg-emerald' : 'cb-bg-rose')">
              <span style="font-size:10px;font-weight:600;color:#FFFFFF">{{ colorIdx === 0 ? 'INDIGO' : (colorIdx === 1 ? 'EMERALD' : 'ROSE') }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Row 2: @keyframes Animations -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">关键帧动画 @keyframes</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">旋转 · 脉冲 · 弹跳 · 呼吸 · 摇摆</span>
        <div style="margin-top:10px;padding:12px;background:#18181B;border-radius:8px;display:flex;flex-direction:row;gap:16px;align-items:center">
          <div class="kf-spin" style="width:48px;height:48px;background:#6366F1;border-radius:10px;display:flex;align-items:center;justify-content:center">
            <span style="font-size:16px;color:#FFFFFF">↻</span>
          </div>
          <div class="kf-pulse" style="width:48px;height:48px;background:#10B981;border-radius:24px;display:flex;align-items:center;justify-content:center">
            <span style="font-size:10px;font-weight:700;color:#FFFFFF">P</span>
          </div>
          <div class="kf-bounce" style="width:48px;height:48px;background:#F59E0B;border-radius:10px;display:flex;align-items:center;justify-content:center">
            <span style="font-size:16px;color:#FFFFFF">↑</span>
          </div>
          <div class="kf-breath" style="width:48px;height:48px;background:#3B82F6;border-radius:10px;display:flex;align-items:center;justify-content:center">
            <span style="font-size:10px;font-weight:700;color:#FFFFFF">B</span>
          </div>
          <div class="kf-shake" style="width:48px;height:48px;background:#EC4899;border-radius:10px;display:flex;align-items:center;justify-content:center">
            <span style="font-size:16px;color:#FFFFFF">~</span>
          </div>
        </div>
      </div>

      <!-- Row 3: Easing Functions -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">缓动函数 Easing</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">22 种内置缓动函数</span>
        <div style="margin-top:10px;padding:12px;background:#18181B;border-radius:8px">
          <div style="display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
            <div style="padding:4px 10px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:4px;font-size:9px;color:#A5B4FC">linear</div>
            <div style="padding:4px 10px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:4px;font-size:9px;color:#A5B4FC">ease</div>
            <div style="padding:4px 10px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:4px;font-size:9px;color:#A5B4FC">ease-in</div>
            <div style="padding:4px 10px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:4px;font-size:9px;color:#A5B4FC">ease-out</div>
            <div style="padding:4px 10px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:4px;font-size:9px;color:#A5B4FC">ease-in-out</div>
            <div style="padding:4px 10px;background:#053321;border:1px solid #065F46;border-radius:4px;font-size:9px;color:#6EE7B7">ease-in-cubic</div>
            <div style="padding:4px 10px;background:#053321;border:1px solid #065F46;border-radius:4px;font-size:9px;color:#6EE7B7">ease-out-cubic</div>
            <div style="padding:4px 10px;background:#451A03;border:1px solid #78350F;border-radius:4px;font-size:9px;color:#FCD34D">ease-in-back</div>
            <div style="padding:4px 10px;background:#451A03;border:1px solid #78350F;border-radius:4px;font-size:9px;color:#FCD34D">ease-out-back</div>
            <div style="padding:4px 10px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:4px;font-size:9px;color:#A5B4FC">ease-in-quad</div>
            <div style="padding:4px 10px;background:#1E1F2E;border:1px solid #2A2B3E;border-radius:4px;font-size:9px;color:#A5B4FC">ease-out-quad</div>
            <div style="padding:4px 10px;background:#450A0A;border:1px solid #7F1D1D;border-radius:4px;font-size:9px;color:#FCA5A5">ease-in-quart</div>
            <div style="padding:4px 10px;background:#450A0A;border:1px solid #7F1D1D;border-radius:4px;font-size:9px;color:#FCA5A5">ease-out-quart</div>
          </div>
        </div>
      </div>

      <!-- Row 4: v-show Animation -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">条件渲染动画</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">v-show + CSS transition 配合</span>
        <div style="margin-top:10px;padding:12px;background:#18181B;border-radius:8px;display:flex;flex-direction:row;gap:10px;align-items:center">
          <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="toggleExtra" click-arg="">切换额外内容</button>
          <div v-show="showExtra" style="padding:8px 16px;background:#1E1F2E;border:1px solid #6366F1;border-radius:6px;font-size:11px;color:#A5B4FC;transition:all 0.3s ease">✨ 额外内容 v-show 切换</div>
        </div>
      </div>

      <!-- Framework Animation Info -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">框架动画能力</span>
        <div style="margin-top:6px;padding:10px;background:#18181B;border-radius:6px">
          <span style="font-size:10px;color:#A1A1AA;line-height:1.6">
            Px 动画系统支持：Transition 组件（enter/leave 过渡动画）、TransitionGroup 组件（含 FLIP 算法）、
            22 种内置缓动函数（linear, ease, cubic-bezier 等）、@keyframes 关键帧动画解析与插值、
            样式插值（opacity / backgroundColor / color / transform translateX/Y）、
            AnimationManager 动画管理器（对象池 + MAX_ANIMATIONS 上限）、Platform::setAnimationTimer 帧驱动。
          </span>
        </div>
      </div>

      <!-- Spacer -->
      <div style="height:24px"></div>
    </div>
  </div>
</template>

<script lang="php">
class AnimationShowcaseComponent extends ReactiveComponent
{
    public bool $animVisible = true;
    public int $colorIdx = 0;
    public bool $showExtra = false;

    public function toggleAnim(string $arg): void
    {
        $this->animVisible = !$this->animVisible;
        $this->markDirty();
    }

    public function cycleColor(string $arg): void
    {
        $this->colorIdx = ($this->colorIdx + 1) % 3;
        $this->markDirty();
    }

    public function toggleExtra(string $arg): void
    {
        $this->showExtra = !$this->showExtra;
        $this->markDirty();
    }
}
</script>

<style>
/* Transition demo */
.anim-box-show {
  background: #6366F1;
}
.anim-box-hide {
  background: #27272A;
  opacity: 0.4;
  transform: translateX(-16px);
}

/* Color transition */
.cb-indigo { background: #6366F1; color: #FFFFFF; }
.cb-emerald { background: #10B981; color: #FFFFFF; }
.cb-rose { background: #F43F5E; color: #FFFFFF; }
.cb-bg-indigo { background: #6366F1; }
.cb-bg-emerald { background: #10B981; }
.cb-bg-rose { background: #F43F5E; }

/* @keyframes animations */
@keyframes kf-spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
.kf-spin { animation: kf-spin 2s linear infinite; }

@keyframes kf-pulse {
  0% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.12); opacity: 0.8; }
  100% { transform: scale(1); opacity: 1; }
}
.kf-pulse { animation: kf-pulse 1.5s ease-in-out infinite; }

@keyframes kf-bounce {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-12px); }
}
.kf-bounce { animation: kf-bounce 0.8s ease infinite; }

@keyframes kf-breath {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}
.kf-breath { animation: kf-breath 2s ease-in-out infinite; }

@keyframes kf-shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-4px); }
  75% { transform: translateX(4px); }
}
.kf-shake { animation: kf-shake 0.6s ease infinite; }
</style>
