<template>
  <div class="rt-console" style="width:1600px;height:800px;display:flex;font-family:'Segoe UI','Noto Sans SC',system-ui,sans-serif;">
    <div class="sidebar" style="width:280px;height:100%;background:#1e1e2e;display:flex;flex-direction:column;flex-shrink:0;">
      <div class="sidebar-header" style="padding:16px 20px;background:#181825;border-bottom:1px solid #313244;flex-shrink:0;">
        <div style="font-size:18px;font-weight:700;color:#cdd6f4;">PHP RT Test</div>
        <div style="font-size:11px;color:#6c7086;margin-top:2px;">CSS Layout Suite · {{ caseCount }} cases</div>
      </div>
      <div class="case-list" style="flex:1;overflow-y:auto;padding:6px 0;min-height:0;">
        <div v-for="item in caseList" :key="item.tag" @click="selectCase(item.tag)"
             style="display:flex;align-items:center;height:34px;padding:0 20px;font-size:13px;color:#cdd6f4;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
             :style="'background:' + item.bgColor + ';'">
          {{ item.title }}
        </div>
      </div>
      <div class="sidebar-footer" style="padding:10px 16px;border-top:1px solid #313244;flex-shrink:0;">
        <button @click="refreshCases" style="width:100%;height:32px;background:#313244;border:none;border-radius:6px;color:#cdd6f4;font-size:12px;cursor:pointer;">Refresh</button>
      </div>
    </div>
    <div class="main-content" style="flex:1;display:flex;flex-direction:column;background:#f5f5f5;">
      <div class="content-header" style="height:52px;background:#fff;border-bottom:1px solid #e0e0e0;display:flex;align-items:center;padding:0 24px;flex-shrink:0;">
        <span style="font-size:15px;font-weight:600;color:#1e1e2e;">{{ currentTitle }}</span>
      </div>
      <div class="content-body" style="flex:1;padding:20px;overflow:auto;position:relative;min-height:0;">
        <component :is="caseName" />
      </div>
    </div>
  </div>
</template>
<script lang="php">
class AppComponent extends ReactiveComponent
{
    public array $caseList = [];
    public string $caseName = '';
    public string $currentTitle = '';
    public string $caseCount = '0';

    public function onMount(): void
    {
        global $argv;
        $cliCase = '';
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--case=')) {
                $cliCase = substr($arg, 7);
            }
        }

        $appDir = \Px\Core\Config::getAppDir();
        $caseDir = $appDir . '/test_case';
        $dirs = glob($caseDir . '/prt-*', GLOB_ONLYDIR);
        sort($dirs);

        $list = [];
        $colorPalette = ['#313244', '#45475a', '#585b70', '#6c7086', '#7f849c', '#a6adc8', '#bac2de', '#1e1e2e', '#252540', '#2a2a45'];
        foreach ($dirs as $i => $dir) {
            $base = basename($dir);
            $parts = explode('-', $base);
            array_shift($parts);
            $num = array_shift($parts) ?? '00';
            $title = $num . ' ' . ucwords(implode(' ', $parts));
            $list[] = ['tag' => $base, 'title' => $title, 'name' => $base, 'bgColor' => $colorPalette[$i % count($colorPalette)]];
        }

        $this->caseList = $list;
        $this->caseCount = (string)count($list);

        if ($cliCase !== '') {
            $this->caseName = $cliCase;
            $this->currentTitle = $cliCase;
            foreach ($list as $item) {
                if ($item['tag'] === $cliCase) {
                    $this->currentTitle = $item['title'];
                    break;
                }
            }
            $this->markDirty();
        } elseif (!empty($list)) {
            $this->caseName = $list[0]['tag'];
            $this->currentTitle = $list[0]['title'];
        }
    }

    public function selectCase(string $tag): void
    {
        $this->caseName = $tag;
        foreach ($this->caseList as $item) {
            if ($item['tag'] === $tag) {
                $this->currentTitle = $item['title'];
                break;
            }
        }
        $this->markDirty();
    }

    public function refreshCases(): void
    {
        $appDir = \Px\Core\Config::getAppDir();
        $caseDir = $appDir . '/test_case';
        $dirs = glob($caseDir . '/prt-*', GLOB_ONLYDIR);
        sort($dirs);
        $list = [];
        $colorPalette = ['#313244', '#45475a', '#585b70', '#6c7086', '#7f849c', '#a6adc8', '#bac2de', '#1e1e2e', '#252540', '#2a2a45'];
        foreach ($dirs as $i => $dir) {
            $base = basename($dir);
            $parts = explode('-', $base);
            array_shift($parts);
            $num = array_shift($parts) ?? '00';
            $title = $num . ' ' . ucwords(implode(' ', $parts));
            $list[] = ['tag' => $base, 'title' => $title, 'name' => $base, 'bgColor' => $colorPalette[$i % count($colorPalette)]];
        }
        $this->caseList = $list;
        $this->caseCount = (string)count($list);
        $found = false;
        foreach ($list as $item) {
            if ($item['tag'] === $this->caseName) {
                $this->currentTitle = $item['title'];
                $found = true;
                break;
            }
        }
        if (!$found && !empty($list)) {
            $this->caseName = $list[0]['tag'];
            $this->currentTitle = $list[0]['title'];
        }
        $this->markDirty();
    }
}
</script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width:1600px; height:800px; overflow:hidden; font-size:16px; background:#fff; color:#000; }
</style>
