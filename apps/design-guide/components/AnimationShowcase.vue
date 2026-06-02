<template>
  <div style="left:0px;top:0px;width:1920px;display:flex;flex-direction:column;gap:0px">

    <!-- CSS transition demo -->
    <div style="margin:16px 16px 0 16px;height:110px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">过渡动画 (transition)</div>
      <div style="margin-top:8px;padding:12px;background:#1F1F23;border-radius:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="toggleAnim" click-arg="">切换动画</button>
        <div :class="animVisible ? 'anim-box-visible' : 'anim-box-hidden'" style="width:60px;height:60px;border-radius:8px;display:flex;align-items:center;justify-content:center">
          <span style="font-size:20px;color:#FFFFFF">{{ animVisible ? '😊' : '😴' }}</span>
        </div>
      </div>
      <div style="margin-top:6px;font-size:10px;color:#6B7280">点击按钮切换显示状态（opacity + translateX 过渡）</div>
    </div>

    <!-- Background color transition -->
    <div style="margin:16px 16px 0 16px;height:110px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">颜色过渡 (color transition)</div>
      <div style="margin-top:8px;padding:12px;background:#1F1F23;border-radius:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
        <button style="padding:6px 14px;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="toggleColor" click-arg="" :class="colorState === 'blue' ? 'btn-blue' : 'btn-green'">切换颜色</button>
        <div :class="colorState === 'blue' ? 'color-box-blue' : 'color-box-green'" style="width:60px;height:60px;border-radius:8px;display:flex;align-items:center;justify-content:center">
          <span style="font-size:11px;font-weight:500;color:#FFFFFF">{{ colorState === 'blue' ? 'BLUE' : 'GREEN' }}</span>
        </div>
      </div>
      <div style="margin-top:6px;font-size:10px;color:#6B7280">点击在蓝色/绿色之间过渡</div>
    </div>

    <!-- Animation property demo -->
    <div style="margin:16px 16px 0 16px;height:130px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">关键帧动画 (@keyframes)</div>
      <div style="margin-top:8px;padding:12px;background:#1F1F23;border-radius:8px;display:flex;flex-direction:row;gap:12px;align-items:center">
        <div class="spin-box" style="width:50px;height:50px;background:#6366F1;border-radius:8px;display:flex;align-items:center;justify-content:center">
          <span style="font-size:18px;color:#FFFFFF">&#x21bb;</span>
        </div>
        <div class="pulse-box" style="width:50px;height:50px;background:#10B981;border-radius:25px;display:flex;align-items:center;justify-content:center">
          <span style="font-size:12px;font-weight:600;color:#FFFFFF">Pulse</span>
        </div>
        <div class="bounce-box" style="width:50px;height:50px;background:#F59E0B;border-radius:8px;display:flex;align-items:center;justify-content:center">
          <span style="font-size:18px;color:#FFFFFF">&#x2191;</span>
        </div>
      </div>
      <div style="margin-top:6px;font-size:10px;color:#6B7280">@keyframes 动画：旋转 / 脉冲 / 弹跳</div>
    </div>

    <!-- Easing functions -->
    <div style="margin:16px 16px 0 16px;height:150px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">缓动函数 (Easing Functions)</div>
      <div style="margin-top:8px;padding:12px;background:#1F1F23;border-radius:8px">
        <div style="display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">linear</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease-in</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease-out</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease-in-out</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease-in-cubic</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease-out-cubic</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease-in-back</div>
          <div style="padding:4px 10px;background:#2A2A3E;border-radius:4px;font-size:10px;color:#D1D5DB">ease-out-back</div>
        </div>
      </div>
    </div>

    <!-- v-if / v-show animation context -->
    <div style="margin:16px 16px 0 16px;height:100px;width:1888px">
      <div style="font-size:13px;font-weight:600;color:#FFFFFF">条件渲染动画</div>
      <div style="margin-top:8px;padding:12px;background:#1F1F23;border-radius:8px">
        <div style="display:flex;flex-direction:row;gap:8px;align-items:center">
          <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500" @click="toggleExtra" click-arg="">切换额外内容</button>
          <div v-show="showExtra" class="extra-box" style="padding:8px 16px;background:#374151;border-radius:6px;font-size:11px;color:#D1D5DB">额外内容 v-show</div>
        </div>
      </div>
    </div>

    <!-- Framework animation system info -->
    <div style="margin:12px 16px 0 16px;height:80px;width:1888px">
      <div style="padding:10px;background:#1F1F23;border-radius:6px">
        <span style="font-size:11px;color:#9CA3AF">
          Px 框架动画系统支持：Transition 组件、TransitionGroup 组件（含 FLIP 算法）、
          22 种缓动函数、@keyframes 关键帧动画、样式插值（opacity/color/transform）、
          AnimationManager 动画管理器、对象池优化。通过 Platform::setAnimationTimer 驱动。
        </span>
      </div>
    </div>

    <!-- Spacer -->
    <div style="height:16px"></div>
  </div>
</template>

<script lang="php">
class AnimationShowcaseComponent extends ReactiveComponent
{
    public bool $animVisible = true;
    public string $colorState = 'blue';
    public bool $showExtra = false;

    public function toggleAnim(string $arg): void
    {
        $this->animVisible = !$this->animVisible;
        $this->markDirty();
    }

    public function toggleColor(string $arg): void
    {
        $this->colorState = $this->colorState === 'blue' ? 'green' : 'blue';
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
/* Transition classes for the smiley box */
.anim-box-visible {
  background: #6366F1;
  transition: all 0.3s ease;
}
.anim-box-hidden {
  background: #374151;
  opacity: 0.3;
  transform: translateX(-20px);
  transition: all 0.3s ease;
}

/* Background color transition */
.btn-blue {
  background: #3B82F6;
}
.btn-green {
  background: #10B981;
}
.color-box-blue {
  background: #3B82F6;
  transition: background 0.3s ease;
}
.color-box-green {
  background: #10B981;
  transition: background 0.3s ease;
}

/* @keyframes animations */
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
.spin-box {
  animation: spin 2s linear infinite;
}

@keyframes pulse {
  0% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.1); opacity: 0.8; }
  100% { transform: scale(1); opacity: 1; }
}
.pulse-box {
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes bounce {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-10px); }
}
.bounce-box {
  animation: bounce 0.8s ease infinite;
}

/* Extra box with transition */
.extra-box {
  transition: all 0.3s ease;
}
</style>
