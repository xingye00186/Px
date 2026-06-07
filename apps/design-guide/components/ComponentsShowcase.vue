<template>
  <div style="width:1920px;height:1000px;display:flex;flex-direction:column;background:#0B0B0E">

    <!-- Page Header -->
    <div style="padding:20px 24px 12px 24px;border-bottom:1px solid #1E1E24">
      <span style="font-size:16px;font-weight:600;color:#FFFFFF">组件库</span>
      <span style="margin-left:10px;font-size:10px;color:#52525B">Component Library — 60+ 开箱即用的 UI 组件</span>
    </div>

    <div style="display:flex;flex-direction:column;gap:0;padding:16px 24px">

      <!-- A. Buttons Section -->
      <div style="background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Buttons — 按钮</span>
          <span style="font-size:9px;color:#52525B">6 种类型 × 3 种尺寸</span>
          <div style="flex:1"></div>
          <span style="font-size:9px;color:#71717A">{{ btnMsg }}</span>
        </div>
        <!-- Button row: types -->
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
          <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Primary">Primary</button>
          <button style="padding:6px 14px;background:#10B981;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Success">Success</button>
          <button style="padding:6px 14px;background:#F59E0B;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Warning">Warning</button>
          <button style="padding:6px 14px;background:#EF4444;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Danger">Danger</button>
          <button style="padding:6px 14px;background:#3B82F6;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Info">Info</button>
        </div>
        <!-- Plain variant buttons -->
        <div style="margin-top:8px;display:flex;flex-direction:row;gap:6px;flex-wrap:wrap">
          <button style="padding:6px 14px;background:transparent;color:#818CF8;border:1px solid #6366F1;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Primary Plain">Plain</button>
          <button style="padding:6px 14px;background:transparent;color:#34D399;border:1px solid #10B981;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Success Plain">Success</button>
          <button style="padding:6px 14px;background:transparent;color:#FBBF24;border:1px solid #F59E0B;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Warning Plain">Warning</button>
          <button style="padding:6px 14px;background:transparent;color:#F87171;border:1px solid #EF4444;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Danger Plain">Danger</button>
          <button style="padding:6px 14px;background:transparent;color:#60A5FA;border:1px solid #3B82F6;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Info Plain">Info</button>
        </div>
        <!-- Size variants -->
        <div style="margin-top:8px;display:flex;flex-direction:row;gap:8px;align-items:center">
          <button style="padding:4px 10px;background:#6366F1;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Small">Small</button>
          <button style="padding:6px 14px;background:#6366F1;color:#FFFFFF;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Medium">Medium</button>
          <button style="padding:8px 20px;background:#6366F1;color:#FFFFFF;border:none;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer" @click="clickBtn" click-arg="Large">Large</button>
          <button style="width:32px;height:32px;background:#6366F1;color:#FFFFFF;border:none;border-radius:50%;font-size:16px;cursor:pointer" @click="clickBtn" click-arg="Icon">+</button>
        </div>
      </div>

      <!-- B. Cards Section -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Cards — 卡片</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">多种卡片变体展示内容</span>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:12px">
          <!-- Basic Card -->
          <div style="flex:1;background:#18181B;border:1px solid #27272A;border-radius:10px;padding:14px">
            <div style="width:100%;height:4px;background:#6366F1;border-radius:2px;margin-bottom:10px"></div>
            <span style="font-size:13px;font-weight:600;color:#FFFFFF">Basic Card</span>
            <span style="margin-top:6px;font-size:11px;color:#A1A1AA">基础卡片，适用内容展示。</span>
          </div>
          <!-- Elevated Card -->
          <div style="flex:1;background:#18181B;border:1px solid #27272A;border-radius:10px;padding:14px">
            <div style="display:flex;flex-direction:row;justify-content:space-between;align-items:center">
              <span style="font-size:13px;font-weight:600;color:#FFFFFF">Elevated</span>
              <span style="padding:2px 8px;background:#6366F1;border-radius:8px;font-size:8px;font-weight:600;color:#FFFFFF">NEW</span>
            </div>
            <span style="margin-top:6px;font-size:11px;color:#A1A1AA">带有标签和操作的增强卡片。</span>
            <div style="margin-top:10px;display:flex;flex-direction:row;justify-content:flex-end;gap:6px">
              <button style="padding:4px 10px;background:transparent;color:#A1A1AA;border:none;font-size:10px;cursor:pointer" @click="cardAction" click-arg="Cancel">取消</button>
              <button style="padding:4px 10px;background:#6366F1;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="cardAction" click-arg="Confirm">确认</button>
            </div>
          </div>
          <!-- Outlined Card -->
          <div style="flex:1;background:transparent;border:1px solid #27272A;border-radius:10px;padding:14px">
            <span style="font-size:13px;font-weight:600;color:#FFFFFF">Outlined</span>
            <span style="margin-top:6px;font-size:11px;color:#A1A1AA">使用边框替代背景的轻量卡片。</span>
          </div>
          <!-- Media Card -->
          <div style="flex:1;background:#18181B;border:1px solid #27272A;border-radius:10px;padding:14px">
            <div style="height:50px;background:linear-gradient(135deg,#6366F1,#8B5CF6);border-radius:6px;display:flex;align-items:center;justify-content:center">
              <span style="font-size:9px;color:#FFFFFF;font-weight:500">Media Area</span>
            </div>
            <span style="margin-top:8px;font-size:12px;font-weight:600;color:#FFFFFF">Media Card</span>
            <span style="margin-top:4px;font-size:10px;color:#A1A1AA">含封面区域的富媒体卡片。</span>
          </div>
        </div>
      </div>

      <!-- C. Badges & Tags Section -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
          <span style="font-size:11px;font-weight:600;color:#FFFFFF">Badges &amp; Tags — 徽章与标签</span>
          <span style="font-size:9px;color:#52525B">通知标记 / 分类标签</span>
          <div style="flex:1"></div>
          <span style="font-size:9px;color:#71717A">{{ badgeMsg }}</span>
        </div>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:16px;align-items:center">
          <!-- Badge Demo -->
          <div style="display:flex;flex-direction:row;gap:12px;align-items:center">
            <span style="font-size:10px;color:#A1A1AA">徽章:</span>
            <div style="position:relative;width:32px;height:32px;background:#27272A;border-radius:6px;display:flex;align-items:center;justify-content:center">
              <span style="font-size:12px">🔔</span>
              <div style="position:absolute;right:-4px;top:-4px;min-width:16px;height:16px;background:#EF4444;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 4px">
                <span style="font-size:9px;font-weight:600;color:#FFFFFF">{{ badgeValue }}</span>
              </div>
            </div>
            <div style="position:relative;width:32px;height:32px;background:#27272A;border-radius:6px;display:flex;align-items:center;justify-content:center">
              <span style="font-size:12px">✉️</span>
              <div style="position:absolute;right:-4px;top:-4px;width:8px;height:8px;background:#EF4444;border-radius:4px"></div>
            </div>
            <button style="padding:4px 10px;background:#374151;color:#D1D5DB;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="incBadge" click-arg="">+1</button>
            <button style="padding:4px 10px;background:#374151;color:#D1D5DB;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="resetBadge" click-arg="">重置</button>
          </div>
          <div style="width:1px;height:32px;background:#27272A"></div>
          <!-- Tags Demo -->
          <div style="display:flex;flex-direction:row;gap:6px;flex-wrap:wrap;align-items:center">
            <span style="font-size:10px;color:#A1A1AA">标签:</span>
            <div style="padding:3px 10px;border-radius:4px;font-size:10px;font-weight:500" :class="'tg-' . tag1">{{ tag1Text }}</div>
            <div style="padding:3px 10px;border-radius:4px;font-size:10px;font-weight:500" :class="'tg-' . tag2">{{ tag2Text }}</div>
            <div style="padding:3px 10px;border-radius:4px;font-size:10px;font-weight:500" :class="'tg-' . tag3">{{ tag3Text }}</div>
            <div style="padding:3px 10px;background:#27272A;color:#A1A1AA;border-radius:4px;font-size:10px;display:flex;flex-direction:row;align-items:center;gap:4px">
              <span>可关闭</span>
              <span style="cursor:pointer;font-size:8px" @click="closeTag" click-arg="">✕</span>
            </div>
          </div>
        </div>
      </div>

      <!-- D. Switch & State Section -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Switch — 开关</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">点击切换开/关状态</span>
        <div style="margin-top:10px;display:flex;flex-direction:row;gap:16px;align-items:center">
          <!-- Switch toggle -->
          <div style="width:44px;height:22px;border-radius:11px;cursor:pointer" :class="switchOn ? 'sw-on' : 'sw-off'" @click="toggleSwitch">
            <div style="width:18px;height:18px;border-radius:9px;background:#FFFFFF;margin-top:2px" :style="switchOn ? 'margin-left:24px' : 'margin-left:2px'"></div>
          </div>
          <span style="font-size:11px;font-weight:500;color:#D1D5DB">{{ switchOn ? '已开启' : '已关闭' }}</span>
          <div style="margin-left:16px;width:8px;height:8px;border-radius:4px" :class="switchOn ? 'dot-on' : 'dot-off'"></div>
          <span style="font-size:9px;color:#71717A">{{ switchOn ? 'activeColor: #10B981' : 'inactiveColor: #52525B' }}</span>
        </div>
      </div>

      <!-- E. Progress Section -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Progress — 进度条</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">可交互进度的可视反馈</span>
        <div style="margin-top:10px;display:flex;flex-direction:column;gap:10px">
          <!-- Default Progress -->
          <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
            <span style="font-size:9px;color:#A1A1AA;width:50px">默认</span>
            <div style="flex:1;height:6px;background:#27272A;border-radius:3px">
              <div style="height:6px;border-radius:3px;background:#6366F1" :style="'width:' . progressVal . 'px'"></div>
            </div>
            <span style="font-size:10px;color:#A1A1AA;width:30px">{{ progressVal }}%</span>
          </div>
          <!-- Success Progress -->
          <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
            <span style="font-size:9px;color:#A1A1AA;width:50px">成功</span>
            <div style="flex:1;height:6px;background:#27272A;border-radius:3px">
              <div style="height:6px;border-radius:3px;background:#10B981" :style="'width:' . progressVal . 'px'"></div>
            </div>
            <span style="font-size:10px;color:#A1A1AA;width:30px">{{ progressVal }}%</span>
          </div>
          <!-- Warning Progress -->
          <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
            <span style="font-size:9px;color:#A1A1AA;width:50px">警告</span>
            <div style="flex:1;height:6px;background:#27272A;border-radius:3px">
              <div style="height:6px;border-radius:3px;background:#F59E0B" :style="'width:' . progressVal . 'px'"></div>
            </div>
            <span style="font-size:10px;color:#A1A1AA;width:30px">{{ progressVal }}%</span>
          </div>
          <!-- Error Progress -->
          <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
            <span style="font-size:9px;color:#A1A1AA;width:50px">异常</span>
            <div style="flex:1;height:6px;background:#27272A;border-radius:3px">
              <div style="height:6px;border-radius:3px;background:#EF4444" :style="'width:' . progressVal . 'px'"></div>
            </div>
            <span style="font-size:10px;color:#A1A1AA;width:30px">{{ progressVal }}%</span>
          </div>
          <!-- Progress Controls -->
          <div style="display:flex;flex-direction:row;gap:6px;align-items:center;margin-top:4px">
            <button style="padding:4px 10px;background:#374151;color:#D1D5DB;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="decrProgress" click-arg="">−10</button>
            <button style="padding:4px 10px;background:#6366F1;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="incrProgress" click-arg="">+10</button>
            <button style="padding:4px 10px;background:#374151;color:#D1D5DB;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="resetProgress" click-arg="">重置</button>
            <span style="font-size:9px;color:#71717A">点击调整进度值 (0~100)</span>
          </div>
        </div>
      </div>

      <!-- F. Messages Section -->
      <div style="margin-top:8px;background:#121215;border:1px solid #1E1E24;border-radius:8px;padding:14px 18px">
        <span style="font-size:11px;font-weight:600;color:#FFFFFF">Messages — 消息提示</span>
        <span style="margin-left:8px;font-size:9px;color:#52525B">4 种类型消息条</span>
        <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
          <div style="padding:8px 12px;border-radius:4px;font-size:11px;border-left:3px solid" :class="msgVisible ? 'msg-success-msg' : 'msg-hidden'" v-show="msgVisible">{{ currentMsg }}</div>
          <div style="display:flex;flex-direction:row;gap:6px">
            <button style="padding:4px 12px;background:#10B981;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="showMessage" click-arg="success">Success</button>
            <button style="padding:4px 12px;background:#EF4444;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="showMessage" click-arg="error">Error</button>
            <button style="padding:4px 12px;background:#F59E0B;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="showMessage" click-arg="warning">Warning</button>
            <button style="padding:4px 12px;background:#3B82F6;color:#FFFFFF;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="showMessage" click-arg="info">Info</button>
            <button style="padding:4px 12px;background:#374151;color:#D1D5DB;border:none;border-radius:4px;font-size:10px;cursor:pointer" @click="hideMsg" click-arg="">隐藏</button>
          </div>
        </div>
      </div>

      <!-- Spacer -->
      <div style="height:24px"></div>
    </div>
  </div>
</template>

<script lang="php">
class ComponentsShowcaseComponent extends ReactiveComponent
{
    // Button state
    public string $btnMsg = '点击按钮测试';

    // Card state
    public string $cardMsg = '';

    // Badge state
    public int $badgeValue = 3;
    public string $badgeMsg = '通知值: 3';

    // Tag state
    public string $tag1 = 'primary';
    public string $tag1Text = 'Primary';
    public string $tag2 = 'success';
    public string $tag2Text = 'Success';
    public string $tag3 = 'warning';
    public string $tag3Text = 'Warning';
    public bool $tagClosed = false;

    // Switch state
    public bool $switchOn = false;

    // Progress state
    public int $progressVal = 45;

    // Message state
    public string $currentMsg = '';
    public bool $msgVisible = false;

    public function clickBtn(string $label): void
    {
        $this->btnMsg = '✅ 点击: ' . $label;
        $this->markDirty();
    }

    public function cardAction(string $action): void
    {
        $this->cardMsg = '卡片操作: ' . $action;
        $this->markDirty();
    }

    public function incBadge(string $arg): void
    {
        $this->badgeValue++;
        $this->badgeMsg = '通知值: ' . $this->badgeValue;
        $this->markDirty();
    }

    public function resetBadge(string $arg): void
    {
        $this->badgeValue = 0;
        $this->badgeMsg = '通知值: 0';
        $this->markDirty();
    }

    public function closeTag(string $arg): void
    {
        $this->tagClosed = true;
        $this->markDirty();
    }

    public function toggleSwitch(): void
    {
        $this->switchOn = !$this->switchOn;
        $this->markDirty();
    }

    public function incrProgress(string $arg): void
    {
        $this->progressVal = min(100, $this->progressVal + 10);
        $this->markDirty();
    }

    public function decrProgress(string $arg): void
    {
        $this->progressVal = max(0, $this->progressVal - 10);
        $this->markDirty();
    }

    public function resetProgress(string $arg): void
    {
        $this->progressVal = 0;
        $this->markDirty();
    }

    public function showMessage(string $type): void
    {
        $msgs = [
            'success' => '✅ 操作成功完成！',
            'error' => '❌ 操作失败，请重试。',
            'warning' => '⚠️ 请注意：配置尚未保存。',
            'info' => 'ℹ️ 这是一条提示信息。',
        ];
        $this->currentMsg = $msgs[$type] ?? '';
        $this->msgVisible = true;
        $this->markDirty();
    }

    public function hideMsg(string $arg): void
    {
        $this->msgVisible = false;
        $this->markDirty();
    }
}
</script>

<style>
.sw-on { background: #10B981; transition: all 0.2s ease; }
.sw-off { background: #52525B; transition: all 0.2s ease; }
.dot-on { background: #10B981; }
.dot-off { background: #52525B; }
.tg-primary { background: #1E1F2E; color: #A5B4FC; }
.tg-success { background: #053321; color: #6EE7B7; }
.tg-warning { background: #451A03; color: #FCD34D; }
.tg-danger { background: #450A0A; color: #FCA5A5; }
.tg-info { background: #1E3A5F; color: #93C5FD; }
.msg-success-msg { background: #053321; color: #6EE7B7; border-left-color: #10B981; }
.msg-hidden { display: none; }
</style>
