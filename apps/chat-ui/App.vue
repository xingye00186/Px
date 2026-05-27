<template>
  <div style="left:0px;top:0px;width:800px;height:600px" title="Chat App">
    <div style="left:0px;top:0px;width:800px;height:600px;background:#FFFFFF"></div>

    <div style="left:0px;top:0px;width:200px;height:600px;background:#2D2D2D">
      <div style="left:8px;top:8px;width:180px;height:28px">
        <div style="left:0px;top:0px;width:145px;height:28px;background:#404040">
          <span style="font-size:12px;color:#999;padding-left:8px">Search...</span>
        </div>
        <button style="left:148px;top:0px;width:28px;height:28px;background:#4CAF50;border:0">
          <span style="color:#FFF;font-size:16px">+</span>
        </button>
      </div>

      <div style="left:0px;top:44px;width:200px;height:556px;overflow:auto" :scroll-top="sidebarScrollTop">
        <template v-for="ch in channelItems" :key="ch.id">
          <div @click="selectChannel(ch.id)" class="channel-item">
            <span>{{ ch.name }}</span>
          </div>
        </template>
      </div>
    </div>

    <div style="left:200px;top:0px;width:1px;height:600px;background:#404040"></div>

    <div style="left:201px;top:0px;width:599px;height:600px">
      <div style="left:201px;top:0px;width:599px;height:50px;background:#E0F2F1">
        <span style="left:217px;top:17px;font-size:16px;font-weight:bold;color:#333">{{ currentChannelName }}</span>
      </div>

      <div style="left:201px;top:50px;width:599px;height:490px;overflow:auto;background:#FFFFFF" :scroll-top="scrollTop">
        <template v-for="msg in userMessageList" :key="msg.id">
          <div style="left:220px;top:0px;margin-bottom:12px">
            <div style="max-width:70%;background:#4CAF50;color:#FFF;padding:10px 14px;border-radius:16px 16px 4px 16px">
              <span style="font-size:14px">{{ msg.text }}</span>
            </div>
          </div>
        </template>
        <template v-for="msg in systemMessageList" :key="msg.id">
          <div style="left:201px;top:0px;margin-bottom:12px">
            <div style="max-width:70%;background:#F1F0EE;color:#333;padding:10px 14px;border-radius:16px 16px 16px 4px">
              <span style="font-size:14px">{{ msg.text }}</span>
            </div>
          </div>
        </template>
      </div>

      <div style="left:201px;top:540px;width:599px;height:60px;background:#323232">
        <button style="left:217px;top:12px;width:36px;height:36px;background:transparent;border:0">
          <span style="color:#FFF;font-size:16px">[attach]</span>
        </button>
        <button style="left:253px;top:12px;width:36px;height:36px;background:transparent;border:0">
          <span style="color:#FFF;font-size:16px">[emoji]</span>
        </button>
        <button style="left:289px;top:12px;width:36px;height:36px;background:transparent;border:0">
          <span style="color:#FFF;font-size:16px">[mic]</span>
        </button>
        <input v-model="inputText"
               style="left:325px;top:12px;width:200px;height:36px;padding:0 12px;background:#484848;color:#FFF;font-size:14px;border:0"
               placeholder="Type message..." />
        <button @click="sendMessage"
                style="left:530px;top:12px;width:70px;height:36px;background:#4CAF50;border:0">
          <span style="color:#FFF;font-size:14px">Send</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $scrollTop = "0";
    public string $sidebarScrollTop = "0";
    public string $inputText = "";
    public string $currentChannelName = "# File Assistant";

    public array $channels = [
        ['id' => '1', 'name' => '# File Assistant', 'selected' => true],
        ['id' => '2', 'name' => '# Dev', 'selected' => false],
        ['id' => '3', 'name' => '# Design', 'selected' => false],
        ['id' => '4', 'name' => '# Ops', 'selected' => false],
        ['id' => '5', 'name' => '# Management', 'selected' => false],
        ['id' => '6', 'name' => '# Finance', 'selected' => false],
        ['id' => '7', 'name' => '# HR', 'selected' => false],
        ['id' => '8', 'name' => '# Support', 'selected' => false],
        ['id' => '9', 'name' => '# Tech', 'selected' => false],
        ['id' => '10', 'name' => '# Project', 'selected' => false],
        ['id' => '11', 'name' => '# Product', 'selected' => false],
        ['id' => '12', 'name' => '# Marketing', 'selected' => false],
        ['id' => '13', 'name' => '# Sales', 'selected' => false],
        ['id' => '14', 'name' => '# Admin', 'selected' => false],
        ['id' => '15', 'name' => '# Other', 'selected' => false],
    ];

    public array $messages = [
        ['id' => '1', 'type' => 'system', 'text' => 'Welcome to File Assistant'],
        ['id' => '2', 'type' => 'user', 'text' => 'Hello, I want to upload'],
        ['id' => '3', 'type' => 'system', 'text' => 'Click + to upload file'],
        ['id' => '4', 'type' => 'system', 'text' => 'Upload failed: exceeds 5MB'],
        ['id' => '5', 'type' => 'user', 'text' => 'Please confirm content'],
        ['id' => '6', 'type' => 'system', 'text' => 'PHP Notice...'],
        ['id' => '7', 'type' => 'system', 'text' => 'Click OK to continue'],
        ['id' => '8', 'type' => 'user', 'text' => 'Upload PDF file'],
        ['id' => '9', 'type' => 'user', 'text' => 'Confirm is correct'],
        ['id' => '10', 'type' => 'system', 'text' => 'File uploaded successfully'],
    ];

    private int $nextMsgId = 11;

    public function getChannelItems(): array
    {
        return $this->channels;
    }

    public function getUserMessageList(): array
    {
        $result = [];
        foreach ($this->messages as $msg) {
            if ($msg['type'] === 'user') {
                $result[] = $msg;
            }
        }
        return $result;
    }

    public function getSystemMessageList(): array
    {
        $result = [];
        foreach ($this->messages as $msg) {
            if ($msg['type'] === 'system') {
                $result[] = $msg;
            }
        }
        return $result;
    }

    public function selectChannel(string $id): void
    {
        foreach ($this->channels as &$ch) {
            $ch['selected'] = ($ch['id'] === $id);
            if ($ch['selected']) {
                $this->currentChannelName = $ch['name'];
            }
        }
        $this->markDirty();
    }

    public function sendMessage(): void
    {
        if ($this->inputText === '') return;

        $now = date('H:i');
        $this->messages[] = [
            'id' => (string)($this->nextMsgId),
            'type' => 'user',
            'text' => $this->inputText,
            'time' => $now,
        ];
        $this->nextMsgId++;
        $this->inputText = '';
        $this->markDirty();
    }
}
</script>

<style>
.channel-active { background:#4A4A4A; color:#FFF; height:32px;padding-left:12px;line-height:32px;font-size:13px }
.channel-item { background:transparent; color:#CCC; height:32px;padding-left:12px;line-height:32px;font-size:13px }
</style>