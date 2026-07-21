<template>
  <div style="width:100%;height:100%;display:flex;flex-direction:column;background:#1C1C1E">
    <!-- SimpleCounter -->
    <div v-if="currentCase === 'SimpleCounter'" style="padding:10px">
      <span style="font-size:16px;color:#FFFFFF">Simple Counter: {{ counter }}</span>
      <button style="margin-left:10px;padding:5px 15px;background:#FF9F0A" @click="increment">+1</button>
    </div>

    <!-- ManyProps -->
    <div v-else-if="currentCase === 'ManyProps'" style="padding:10px">
      <span style="font-size:16px;color:#FFFFFF">ManyProps: tracked={{ trackedProp }} others={{ otherCount }}</span>
      <button style="margin-left:10px;padding:5px 15px;background:#FF9F0A" @click="changeTracked">Change Tracked</button>
      <button style="margin-left:10px;padding:5px 15px;background:#505050" @click="changeUntracked">Change Untracked</button>
    </div>

    <!-- DeepTree -->
    <div v-else-if="currentCase === 'DeepTree'" style="display:flex;flex-direction:column">
      <deep-tree-node style="margin:2px" :depth="5" :label="'root'" />
    </div>

    <!-- MixedWorkload -->
    <div v-else-if="currentCase === 'MixedWorkload'" style="display:flex;flex-direction:column">
      <div style="padding:10px;border-bottom:1px solid #333">
        <span style="font-size:14px;color:#FFFFFF">Cycle: {{ cycleCount }} Render: {{ totalRenders }}</span>
      </div>
      <div style="flex:1;overflow-y:scroll;padding:10px" :scroll-top="scrollPos">
        <div v-for="item in items" :key="item.id" style="height:30px;display:flex;align-items:center;border-bottom:1px solid #444;font-size:13px;color:#CCC">
          <span>{{ item.label }}</span>
          <span :style="'margin-left:auto;color:' + (item.active ? '#FF9F0A' : '#666')">{{ item.value }}</span>
        </div>
      </div>
    </div>

    <!-- FormDashboard: multi-field form with validation -->
    <div v-else-if="currentCase === 'FormDashboard'" style="display:flex;flex-direction:column;padding:8px;gap:4px">
      <div style="display:flex;justify-content:space-between;padding:4px 0">
        <span style="font-size:14px;color:#FFF">Dashboard v{{ version }}</span>
      </div>
      <div v-for="fd in formFields" :key="fd.id" style="padding:3px 0;border-bottom:1px solid #333">
        <span style="font-size:12px;color:#8E8E93">{{ fd.label }}:</span>
        <span :style="'font-size:13px;color:' + (fd.error ? '#FF4444' : '#FFF')">{{ fd.value }}</span>
        <span v-if="fd.error" style="margin-left:8px;font-size:11px;color:#FF4444">{{ fd.error }}</span>
      </div>
      <div style="display:flex;gap:6px;margin-top:4px">
        <span v-for="st in stats" :key="st.id" style="flex:1;padding:4px;background:#2C2C2E;text-align:center">
          <span :style="'font-size:16px;font-weight:bold;color:' + st.color">{{ st.val }}</span>
        </span>
      </div>
    </div>

    <!-- ChatStream: growing message list -->
    <div v-else-if="currentCase === 'ChatStream'" style="display:flex;flex-direction:column;padding:8px">
      <div style="font-size:12px;color:#8E8E93;padding:4px 0">Messages: {{ msgCount }}</div>
     <div v-for="msg in messages" :key="msg.id" style="padding:4px 0;border-bottom:1px solid #2C2C2E;font-size:13px">
        <span style="color:#FF9F0A">{{ msg.author }}:</span>
        <span style="color:#FFF;margin-left:6px">{{ msg.text }}</span>
      </div>
    </div>

    <!-- HoverGrid: 100-cell hover simulation -->
    <div v-else-if="currentCase === 'HoverGrid'" style="display:flex;flex-direction:column;padding:8px;gap:4px">
      <span style="font-size:12px;color:#8E8E93">HoverGrid: cycle={{ hoverCycle }} idx={{ hoveredIdx }}</span>
      <div style="display:grid;grid-template-columns:repeat(10,1fr);gap:3px;margin-top:4px">
        <div v-for="cell in hoverCells" :key="cell.id"
          :style="'height:18px;border-radius:3px;background:' + (cell.idx === hoveredIdx ? '#FF9F0A' : '#2C2C2E') + ';cursor:pointer'">
        </div>
      </div>
    </div>

    <!-- TextHeavy: 20x20 text grid, 20 unique strings repeat 20 times -->
    <div v-else-if="currentCase === 'TextHeavy'" style="display:flex;flex-direction:column;padding:8px;gap:4px">
      <span style="font-size:12px;color:#8E8E93">TextHeavy: cycle={{ textCycle }} cells=400 unique=20</span>
      <div style="display:grid;grid-template-columns:repeat(20,1fr);gap:2px;margin-top:4px">
        <div v-for="cell in textItems" :key="cell.id"
          :style="'padding:2px 0;font-size:11px;text-align:center;border-radius:2px;background:' + (cell.highlighted ? '#FF9F0A' : '#2C2C2E') + ';color:' + (cell.highlighted ? '#000' : '#CCC')">
          {{ cell.label }}
        </div>
      </div>
    </div>

    <!-- DynamicList: add/remove widgets each cycle -->
    <div v-else-if="currentCase === 'DynamicList'" style="display:flex;flex-direction:column;padding:8px;gap:2px">
      <span style="font-size:12px;color:#8E8E93">DynamicList: count={{ dynaCount }}</span>
      <div v-for="wd in dynaWidgets" :key="wd.id"
        style="height:14px;display:flex;align-items:center;padding:0 4px;background:#2C2C2E;font-size:11px;color:#CCC">
        <span>{{ wd.label }}</span>
      </div>
    </div>

    <!-- StaticTemplate: static header/nav/footer + dynamic body -->
    <div v-else-if="currentCase === 'StaticTemplate'" style="display:flex;flex-direction:column;width:100%;height:100%;background:#1C1C1E">
      <!-- 静态 header — 应被 SFC 编译器 hoist -->
      <div style="display:flex;align-items:center;padding:10px 16px;background:#2C2C2E;border-bottom:1px solid #38383A">
        <span style="font-size:15px;color:#FF9F0A;font-weight:bold">Px Framework</span>
        <span style="margin-left:10px;font-size:11px;color:#8E8E93">v{{ stCycle }}</span>
      </div>
      <!-- 静态导航 tabs — 应被 SFC 编译器 hoist -->
      <div style="display:flex;gap:6px;padding:6px 16px;background:#1C1C1E;border-bottom:1px solid #2C2C2E">
        <span style="padding:3px 14px;background:#3A3A3C;border-radius:4px;font-size:12px;color:#FFF">Dashboard</span>
        <span style="padding:3px 14px;background:#3A3A3C;border-radius:4px;font-size:12px;color:#FFF">Analytics</span>
        <span style="padding:3px 14px;background:#FF9F0A;border-radius:4px;font-size:12px;color:#000">Settings</span>
      </div>
      <!-- 动态内容体 — 不被 hoist -->
      <div style="flex:1;display:flex;flex-direction:column;padding:8px 16px;gap:3px;overflow-y:scroll">
        <span style="font-size:12px;color:#8E8E93">StaticTemplate: cycle={{ stCycle }} items={{ stCount }}</span>
        <div v-for="item in stItems" :key="item.id"
          :style="'padding:6px 12px;background:' + (item.active ? '#FF9F0A' : '#2C2C2E') + ';border-radius:4px;cursor:pointer'"
          @click="stToggle(item.id)">
          <span :style="'font-size:12px;color:' + (item.active ? '#000' : '#CCC')">{{ item.label }}</span>
        </div>
      </div>
      <!-- 静态 footer — 应被 SFC 编译器 hoist -->
     <div style="padding:6px 16px;background:#2C2C2E;border-top:1px solid #38383A;text-align:center">
        <span style="font-size:10px;color:#48484A">© Px Framework — static footer</span>
      </div>
    </div>

    <!-- LiveDashboard: IDE with sparse updates -->
    <div v-else-if="currentCase === 'LiveDashboard'" style="display:flex;flex-direction:column;width:100%;height:100%;background:#1C1C1E;font-size:12px;color:#CCC;font-family:monospace">
      <!-- Menu bar — 纯静态，hoist -->
      <div style="display:flex;align-items:center;height:24px;padding:0 8px;background:#2C2C2E;border-bottom:1px solid #38383A;gap:12px;font-size:11px;color:#8E8E93">
        <span>File</span><span>Edit</span><span>View</span><span>Tools</span><span>Help</span>
      </div>

      <div style="flex:1;display:flex;min-height:0">
        <!-- Sidebar — 项目文件树，纯静态 -->
        <div style="width:160px;background:#252526;border-right:1px solid #38383A;display:flex;flex-direction:column;padding:4px 0;overflow-y:scroll">
          <div style="padding:2px 8px;font-size:10px;color:#8E8E93;text-transform:uppercase">EXPLORER</div>
          <div v-for="item in ideSidebar" :key="item.id" style="padding:2px 8px 2px 16px;display:flex;align-items:center;gap:4px;color:#CCC;font-size:11px">
            <span>{{ item.icon }}</span><span>{{ item.label }}</span>
          </div>
        </div>

        <!-- Main content -->
        <div style="flex:1;display:flex;flex-direction:column;min-width:0">
          <!-- Editor tabs — 纯静态 -->
          <div style="display:flex;align-items:center;height:24px;background:#252526;border-bottom:1px solid #38383A;padding:0 4px;gap:2px;font-size:11px">
            <span style="padding:2px 10px;background:#1C1C1E;border-radius:2px 2px 0 0;color:#FFF">main.cpp</span>
            <span style="padding:2px 10px;color:#8E8E93">header.h</span>
            <span style="padding:2px 10px;color:#8E8E93">Makefile</span>
          </div>

          <!-- Editor 代码行 — 稀疏更新（每帧改 1-2 行） -->
          <div style="flex:1;overflow-y:scroll;padding:4px 0;background:#1C1C1E">
            <div v-for="line in ideEditorLines" :key="line.id" style="display:flex;height:18px;line-height:18px">
              <span style="width:40px;text-align:right;padding-right:12px;color:#48484A;user-select:none;font-size:11px">{{ line.num }}</span>
              <span :style="'flex:1;padding-left:4px;font-size:12px;color:' + (line.highlighted ? '#FF9F0A' : '#CCC') + ';background:' + (line.highlighted ? 'rgba(255,159,10,0.1)' : 'transparent') + ';white-space:pre'">{{ line.text }}</span>
            </div>
          </div>

          <!-- Console 面板— 稀疏追加（每帧 2-3 条） -->
          <div style="height:130px;background:#1E1E1E;border-top:1px solid #38383A;display:flex;flex-direction:column">
            <div style="display:flex;align-items:center;height:20px;padding:0 8px;background:#252526;border-bottom:1px solid #38383A;font-size:10px;color:#8E8E93">
              <span style="color:#FF9F0A">■</span><span style="margin-left:4px">Console</span><span style="margin-left:auto">{{ ideLogCount }} lines</span>
            </div>
            <div style="flex:1;overflow-y:scroll;padding:2px 4px;font-size:11px">
              <div v-for="log in ideConsoleLog" :key="log.id" style="padding:1px 0;display:flex;gap:6px">
                <span :style="'color:' + log.levelColor">[{{ log.level }}]</span>
                <span style="color:#CCC">{{ log.text }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Status bar — 少量动态 -->
      <div style="display:flex;align-items:center;height:20px;padding:0 8px;background:#007ACC;font-size:11px;color:#FFF;gap:12px">
        <span>Ln {{ cursorLine }}, Col {{ cursorCol }}</span>
        <span style="color:rgba(255,255,255,0.6)">UTF-8</span>
        <span style="color:rgba(255,255,255,0.6)">Git: {{ gitBranch }}</span>
        <span style="margin-left:auto">Lint: {{ lintWarnings }} warnings</span>
      </div>
    </div>

    <div v-else style="padding:20px">
      <span style="font-size:18px;color:#FF4444">Unknown case: {{ currentCase }}</span>
    </div>
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $currentCase = 'SimpleCounter';

    // ── SimpleCounter ──
    #[Reactive]
    public int $counter = 0;

    public function increment(): void
    {
        $this->counter++;
    }

    // ── ManyProps ──
    #[Reactive]
    public int $trackedProp = 0;

    #[Reactive]
    public array $untrackedProps = [];

    #[Reactive]
    public int $otherCount = 999;

    public function changeTracked(): void
    {
        $this->trackedProp++;
    }

    public function changeUntracked(): void
    {
        $this->untrackedProps[] = time();
    }

    // ── MixedWorkload ──
    #[Reactive]
    public int $cycleCount = 0;
    #[Reactive]
    public int $totalRenders = 0;
    #[Reactive]
    public int $scrollPos = 0;

    #[Reactive]
    public array $items = [];

    public function prepareItems(): void
    {
        $this->items = [];
        for ($i = 0; $i < 100; $i++) {
            $this->items[] = ['id' => (string)$i, 'label' => 'Item ' . $i, 'value' => (string)($i * 10), 'active' => ($i % 2 === 0)];
        }
    }

    public function runWorkloadCycle(): void
    {
        $this->cycleCount++;
        $this->scrollPos = ($this->scrollPos + 50) % 3000;
        $idx = $this->cycleCount % 100;
        $items = $this->items;
        if (isset($items[$idx])) {
            $items[$idx]['value'] = (string)((int)$items[$idx]['value'] + 1);
            $items[$idx]['active'] = !$items[$idx]['active'];
        }
        $this->items = $items;
    }

    // ── FormDashboard ──
    #[Reactive]
    public string $version = '1.0';

    #[Reactive]
    public array $formFields = [];

    #[Reactive]
    public array $stats = [];

    public function initFormDashboard(): void
    {
        $labels = ['Name','Email','Phone','Age','City','Score','Level','Status','Dept','Role'];
        $fields = [];
        for ($i = 0; $i < 10; $i++) {
            $fields[] = ['id' => (string)$i, 'label' => $labels[$i], 'value' => 'val_' . $i, 'error' => ''];
        }
        $this->formFields = $fields;
        $this->stats = [
            ['id' => 's1', 'val' => '0', 'color' => '#FF9F0A'],
            ['id' => 's2', 'val' => '0', 'color' => '#30D158'],
            ['id' => 's3', 'val' => '0', 'color' => '#FF4444'],
        ];
        $this->version = '1.0';
    }

    public function runDashboardCycle(): void
    {
        // Update 3 random fields per cycle
        $fields = $this->formFields;
        for ($i = 0; $i < 3; $i++) {
            $idx = abs(crc32((string)($this->cycleCount * 10 + $i))) % count($fields);
            $fields[$idx]['value'] = 'u_' . $this->cycleCount;
            $fields[$idx]['error'] = ($this->cycleCount % 5 === $i) ? 'err' : '';
        }
        $this->formFields = $fields;
        $this->version = (string)((int)$this->version + 0.1);
    }

    // ── ChatStream ──
    #[Reactive]
    public array $messages = [];

    #[Reactive]
    public int $msgCount = 0;

    public function initChatStream(): void
    {
        $this->messages = [];
        $this->msgCount = 0;
    }

    public function runChatCycle(): void
    {
        // Add 3 new messages per cycle
        $msgs = $this->messages;
        $authors = ['Alice','Bob','Charlie','Diana','Eve'];
        for ($i = 0; $i < 3; $i++) {
            $id = (string)$this->msgCount;
            $author = $authors[abs(crc32($id)) % 5];
            $msgs[] = ['id' => $id, 'author' => $author, 'text' => 'msg #' . $id];
            $this->msgCount++;
        }
        $this->messages = $msgs;
    }

    // ── HoverGrid ──
    #[Reactive]
    public int $hoveredIdx = -1;

    #[Reactive]
    public int $hoverCycle = 0;

    #[Reactive]
    public array $hoverCells = [];

    public function initHoverGrid(): void
    {
        $cells = [];
        for ($i = 0; $i < 100; $i++) {
            $cells[] = ['id' => 'h' . $i, 'idx' => $i];
        }
        $this->hoverCells = $cells;
        $this->hoveredIdx = -1;
        $this->hoverCycle = 0;
    }

    public function runHoverCycle(): void
    {
        $this->hoverCycle++;
        $this->hoveredIdx = $this->hoverCycle % 100;
    }

    // ── DynamicList ──
    #[Reactive]
    public array $dynaWidgets = [];

    #[Reactive]
    public int $dynaCount = 0;

    public function initDynamicList(): void
    {
        $w = [];
        for ($i = 0; $i < 50; $i++) {
            $w[] = ['id' => 'dl-' . $i, 'label' => 'W' . $i];
        }
        $this->dynaWidgets = $w;
        $this->dynaCount = 50;
    }

    public function runDynamicCycle(): void
    {
        $w = $this->dynaWidgets;
        array_shift($w);
        $idx = $this->dynaCount;
        $w[] = ['id' => 'dl-' . $idx, 'label' => 'W' . $idx];
        $this->dynaCount = $idx + 1;
        $this->dynaWidgets = $w;
    }

    // ── StaticTemplate ──
    #[Reactive]
    public array $stItems = [];

    #[Reactive]
    public int $stCycle = 0;

    #[Reactive]
    public int $stCount = 0;

    public function initStaticTemplate(): void
    {
        $items = [];
        $labels = ['Alpha','Beta','Gamma','Delta','Epsilon','Zeta','Eta','Theta','Iota','Kappa'];
        for ($i = 0; $i < 50; $i++) {
            $items[] = ['id' => 'st-' . $i, 'label' => $labels[$i % 10] . ' #' . $i, 'active' => false];
        }
        $this->stItems = $items;
        $this->stCycle = 0;
        $this->stCount = 50;
    }

    public function runStaticCycle(): void
    {
        $this->stCycle++;
        $items = $this->stItems;
        for ($i = 0; $i < 5; $i++) {
            $idx = abs(crc32((string)($this->stCycle * 10 + $i))) % count($items);
            $items[$idx]['active'] = !$items[$idx]['active'];
        }
        $this->stItems = $items;
        $this->stCount = count($items);
    }

    public function stToggle(string $id): void
    {
        $items = $this->stItems;
        foreach ($items as &$it) {
            if ($it['id'] === $id) { $it['active'] = !$it['active']; break; }
        }
        $this->stItems = $items;
    }

    // ── TextHeavy ──
    #[Reactive]
    public int $textCycle = 0;

    #[Reactive]
    public array $textItems = [];

    public function initTextHeavy(): void
    {
        $names = ['Alpha','Beta','Gamma','Delta','Epsilon','Zeta','Eta','Theta','Iota','Kappa','Lambda','Mu','Nu','Xi','Omicron','Pi','Rho','Sigma','Tau','Upsilon'];
        $items = [];
        for ($i = 0; $i < 400; $i++) {
            $items[] = ['id' => 't' . $i, 'label' => $names[$i % 20], 'highlighted' => false];
        }
        $this->textItems = $items;
        $this->textCycle = 0;
    }

    public function runTextCycle(): void
    {
        $this->textCycle++;
        $items = $this->textItems;
        for ($i = 0; $i < 10; $i++) {
            $idx = abs(crc32((string)($this->textCycle * 10 + $i))) % 400;
            $items[$idx]['highlighted'] = !$items[$idx]['highlighted'];
        }
        $this->textItems = $items;
    }

    // ── LiveDashboard ──
    #[Reactive]
    public int $ideCount = 0;

    #[Reactive]
    public int $cursorLine = 1;

    #[Reactive]
    public int $cursorCol = 1;

    #[Reactive]
    public string $gitBranch = 'main';

    #[Reactive]
    public int $lintWarnings = 0;

    #[Reactive]
    public int $ideScroll = 0;

    #[Reactive]
    public array $ideSidebar = [];

    #[Reactive]
    public array $ideEditorLines = [];

    #[Reactive]
    public array $ideConsoleLog = [];

    #[Reactive]
    public int $ideLogCount = 0;

    public function initIde(): void
    {
        $folders = ['src','include','lib','docs','tests'];
        $files = [
            ['icon'=>'📁','label'=>'src'],
            ['icon'=>'  📄','label'=>'main.cpp'],
            ['icon'=>'  📄','label'=>'utils.cpp'],
            ['icon'=>'📁','label'=>'include'],
            ['icon'=>'  📄','label'=>'header.h'],
            ['icon'=>'📁','label'=>'lib'],
            ['icon'=>'  📄','label'=>'core.cpp'],
            ['icon'=>'  📄','label'=>'renderer.cpp'],
            ['icon'=>'📁','label'=>'docs'],
            ['icon'=>'  📄','label'=>'README.md'],
            ['icon'=>'📁','label'=>'tests'],
            ['icon'=>'  📄','label'=>'test_main.cpp'],
            ['icon'=>'📄','label'=>'Makefile'],
            ['icon'=>'📄','label'=>'.gitignore'],
            ['icon'=>'📄','label'=>'build.sh'],
        ];
        $this->ideSidebar = $files;

        // 50 行代码
        $codeSnippets = [
            '#include <iostream>','using namespace std;','','int main() {',
            '    auto x = 42;','    auto y = x * 2;','    cout << x << endl;',
            '    for (int i = 0; i < 10; i++) {',"        cout << i << endl;",
            '    }','    return 0;','}','','void helper() {',
            '    vector<int> v;','    v.push_back(1);','    v.push_back(2);',
            '    for (auto& val : v) {','        val *= 2;','    }',
            '}','','class MyClass {','public:',
            '    MyClass() = default;','    ~MyClass() = default;',
            '    void doSomething() {','        // TODO: implement',
            '    }','private:',
            '    int m_value = 0;','    string m_name;','};',
        ];
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = ['id' => 'el-'.$i, 'num' => $i + 1, 'text' => $codeSnippets[$i % count($codeSnippets)], 'highlighted' => false];
        }
        $this->ideEditorLines = $lines;

        // 初始控制台输出
        $logs = [
            ['id' => 'log-0', 'text' => 'Build system initialized', 'level' => 'INFO', 'levelColor' => '#30D158'],
            ['id' => 'log-1', 'text' => 'Compiler: g++ (Ubuntu 11.4.0)', 'level' => 'INFO', 'levelColor' => '#30D158'],
            ['id' => 'log-2', 'text' => 'Configuration: Release', 'level' => 'INFO', 'levelColor' => '#30D158'],
        ];
        $this->ideConsoleLog = $logs;
        $this->ideLogCount = 3;

        $this->cursorLine = 1;
        $this->cursorCol = 1;
        $this->gitBranch = 'main';
        $this->lintWarnings = 0;
        $this->ideCount = 0;
        $this->ideScroll = 0;
    }

    public function runIdeCycle(): void
    {
        $this->ideCount++;

        // 1. 更新 1 行编辑器高亮（PATCH_STYLE）
        $lines = $this->ideEditorLines;
        $idx = $this->ideCount % 50;
        $lines[$idx]['highlighted'] = !$lines[$idx]['highlighted'];
        $this->ideEditorLines = $lines;

        // 2. 追加 2 条控制台日志（PATCH_TEXT），上限 60 条
        $logs = $this->ideConsoleLog;
        $levels = [['INFO','#30D158'],['WARN','#FF9F0A'],['ERROR','#FF4444']];
        for ($i = 0; $i < 2; $i++) {
            $id = 'log-' . $this->ideLogCount;
            $lv = $levels[abs(crc32((string)$this->ideLogCount)) % 3];
            $logs[] = ['id' => $id, 'text' => 'Cycle ' . $this->ideCount . ' step ' . $i, 'level' => $lv[0], 'levelColor' => $lv[1]];
            $this->ideLogCount++;
        }
        // 保持上限，防止无限增长
        if (count($logs) > 60) {
            $logs = array_slice($logs, -60);
        }
        $this->ideConsoleLog = $logs;

        // 3. 改变光标位置（少量动态文本）
        $this->cursorLine = ($this->ideCount % 50) + 1;
        $this->cursorCol = ($this->ideCount % 80) + 1;
        $this->lintWarnings = $this->ideCount % 10;
    }

    // ── Case switching ──
    public function selectCase(string $case): void
    {
        $this->currentCase = $case;
        if ($case === 'ManyProps') {
            $this->otherCount = 999;
            $this->untrackedProps = [];
            for ($i = 0; $i < 999; $i++) { $this->untrackedProps[] = $i; }
        }
        if ($case === 'MixedWorkload') { $this->prepareItems(); }
        if ($case === 'FormDashboard') { $this->initFormDashboard(); }
        if ($case === 'ChatStream') { $this->initChatStream(); }
        if ($case === 'HoverGrid') { $this->initHoverGrid(); }
        if ($case === 'DynamicList') { $this->initDynamicList(); }
        if ($case === 'StaticTemplate') { $this->initStaticTemplate(); }
        if ($case === 'TextHeavy') { $this->initTextHeavy(); }
        if ($case === 'LiveDashboard') { $this->initIde(); }
    }
</script>
