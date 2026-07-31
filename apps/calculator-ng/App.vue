<template>
  <div style="width:340px;height:660px;background:#1C1C1E;display:flex;flex-direction:column">
    <!-- Display -->
    <calculator-display style="margin-left:11px;flex-shrink:0" :display="display" :expression="expression" :hasMemory="hasMemory" />
    <!-- Memory Bar -->
    <memory-bar style="margin-left:11px;flex-shrink:0" />
    <!-- Scientific Pad -->
    <scientific-pad style="margin-left:11px;margin-top:2px;flex-shrink:0" />
    <!-- Basic Pad -->
    <basic-pad style="margin-left:11px;margin-top:2px;flex-shrink:0" :acLabel="acLabel" />
    <!-- History header -->
    <history-panel style="margin-left:11px;flex-shrink:0" :arrow="arrowText" />
    <!-- B4: history-panel 用 slide 类名设计（Transition 组件 enter/leave 动画演示） -->
    <div v-if="showHistory" class="history-panel slide-enter-active" style="margin-left:11px;margin-right:11px;flex:1;background:#2C2C2E">
      <template v-for="item in historyItems" :key="item.id">
        <div style="height:24px;cursor:pointer;display:flex;align-items:center;padding-left:8px" @click="loadHistoryItem" click-arg="item.id">
          <span style="font-size:12px;color:#FFFFFF">{{ item.text }}</span>
        </div>
      </template>
    </div>
    <div v-else style="flex:1"></div>
  </div>
</template>

<script lang="php">
    /** 当前显示值 */
    #[Reactive]
    public string $display = '0';

    /** 表达式文本 */
    #[Reactive]
    public string $expression = '';

    /** 第一个操作数 */
    public string $operand1 = '';

    /** 当前运算符 (+, -, ×, ÷) */
    public string $operator = '';

    /** 是否开始新输入 */
    public bool $newInput = true;

    /** 是否已输入小数点 */
    public bool $hasDecimal = false;

    /** AC/C 标签 */
    #[Reactive]
    public string $acLabel = 'AC';

    /** 记忆值 */
    public string $memory = '';

    /** 是否有记忆 */
    #[Reactive]
    public bool $hasMemory = false;

    /** 是否显示历史面板 */
    #[Reactive]
    public bool $showHistory = false;

    /** 历史面板箭头 */
    #[Reactive]
    public string $arrowText = '>';

    /** 历史记录列表 */
    #[Reactive]
    public array $historyItems = [];

    /** 历史记录计数器 */
    public string $historyCounter = '0';

    // ============================================================
    // Reset / Clear
    // ============================================================

    /** 重置或清除当前输入 */
    public function reset(): void
    {
        if ($this->acLabel === 'C') {
            // 仅清除当前输入
            $this->display = '0';
            $this->expression = '';
            $this->hasDecimal = false;
            $this->newInput = true;
            $this->acLabel = 'AC';
        } else {
            // 完全重置
            $this->display = '0';
            $this->expression = '';
            $this->operand1 = '';
            $this->operator = '';
            $this->newInput = true;
            $this->hasDecimal = false;
            $this->acLabel = 'AC';
        }
    }

    // ============================================================
    // Digit / Decimal Input
    // ============================================================

    /** 输入数字 */
    public function inputDigit(string $digit): void
    {
        if ($this->display === 'Error') {
            $this->display = $digit;
            $this->newInput = false;
            $this->hasDecimal = false;
        } elseif ($this->newInput) {
            $this->display = $digit;
            $this->newInput = false;
            $this->hasDecimal = false;
        } elseif ($this->display === '0' && $digit !== '.') {
            $this->display = $digit;
        } else {
            // 限制输入长度为 15 位（与 formatNumber 截断长度一致）
            if (strlen($this->display) >= 15) {
                return;
            }
            $this->display .= $digit;
        }
        if ($this->acLabel === 'AC') {
            $this->acLabel = 'C';
        }
    }

    /** 输入小数点 */
    public function inputDecimal(): void
    {
        if ($this->display === 'Error') {
            $this->display = '0.';
            $this->newInput = false;
            $this->hasDecimal = true;
        } elseif ($this->newInput) {
            $this->display = '0.';
            $this->newInput = false;
            $this->hasDecimal = true;
        } elseif (!$this->hasDecimal) {
            if (strlen($this->display) >= 15) {
                return;
            }
            $this->display .= '.';
            $this->hasDecimal = true;
        }
        if ($this->acLabel === 'AC') {
            $this->acLabel = 'C';
        }
    }

    // ============================================================
    // Operator / Calculation
    // ============================================================

    /** 输入运算符 */
    public function inputOperator(string $op): void
    {
        // Map symbol to internal operator
        $opInternal = $op;
        if ($op === '÷' || $op === '/') $opInternal = '÷';
        if ($op === '×' || $op === '*') $opInternal = '×';
        if ($op === '−' || $op === '-') $opInternal = '−';

        if ($this->display === 'Error') {
            $this->display = '0';
            $this->newInput = true;
        }

        if ($this->operator !== '' && !$this->newInput) {
            $this->calculateInternal();
        }
        $this->operand1 = $this->display;
        $this->operator = $opInternal;
        $this->expression = $this->operand1 . ' ' . $this->opDisplay($opInternal);
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    /** 执行计算（等号） */
    public function calculate(): void
    {
        if ($this->display === 'Error') {
            $this->reset();
            return;
        }
        if ($this->operator === '' || $this->newInput) {
            $this->expression = $this->display . ' =';
            return;
        }
        $this->calculateInternal();
    }

    /** 内部计算（不重置 input 标志） */
    private function calculateInternal(): void
    {
        $a = (float)$this->operand1;
        $b = (float)$this->display;
        $result = 0.0;
        $valid = true;

        $op = $this->operator;
        if ($op === '+') {
            $result = $a + $b;
        } elseif ($op === '−') {
            $result = $a - $b;
        } elseif ($op === '×') {
            $result = $a * $b;
        } elseif ($op === '÷') {
            if ($b == 0.0) {
                $this->display = 'Error';
                $this->expression = '';
                $this->operand1 = '';
                $this->operator = '';
                $this->newInput = true;
                return;
            }
            $result = $a / $b;
        } else {
            $valid = false;
        }

        if (!$valid) return;

        // Format result
        $resultStr = $this->formatNumber($result);

        $this->expression = $this->operand1 . ' ' . $this->opDisplay($op) . ' ' . $b . ' =';

        // Add to history
        $this->addHistory(
            $this->operand1 . ' ' . $this->opDisplay($op) . ' ' . $b . ' = ' . $resultStr,
            $resultStr
        );

        $this->display = $resultStr;
        $this->operand1 = '';
        $this->operator = '';
        $this->newInput = true;
        $this->hasDecimal = (strpos($this->display, '.') !== false);
        $this->acLabel = 'C';
    }

    /** 格式化数字 */
    private function formatNumber(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return 'Error';
        }
        if ($value == (float)(int)$value && abs($value) < 1000000000) {
            return (string)(int)$value;
        }
        $str = sprintf('%.10f', $value);
        $str = rtrim(rtrim($str, '0'), '.');
        if (strlen($str) > 15) {
            $str = substr($str, 0, 15);
            $str = rtrim($str, '.');
        }
        return $str;
    }

    /** 运算符显示字符 */
    private function opDisplay(string $op): string
    {
        if ($op === '÷' || $op === '/') return '÷';
        if ($op === '×' || $op === '*') return '×';
        if ($op === '−' || $op === '-') return '−';
        return $op;
    }

    // ============================================================
    // Unary Operations
    // ============================================================

    /** 正负号切换 */
    public function toggleSign(): void
    {
        if ($this->display === 'Error' || $this->display === '0') return;
        if ($this->display[0] === '-') {
            $this->display = substr($this->display, 1);
        } else {
            $this->display = '-' . $this->display;
        }
        $this->acLabel = 'C';
    }

    /** 百分比 */
    public function percent(): void
    {
        if ($this->display === 'Error') return;
        $value = (float)$this->display;
        $result = $value / 100.0;
        $this->display = $this->formatNumber($result);
        $this->expression = $value . '%';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    /** 退格 */
    public function backspace(): void
    {
        if ($this->newInput || $this->display === 'Error') return;
        $len = strlen($this->display);
        if ($len <= 1) {
            $this->display = '0';
            $this->newInput = true;
        } elseif ($len === 2 && $this->display[0] === '-') {
            $this->display = '0';
            $this->newInput = true;
        } else {
            $last = $this->display[$len - 1];
            if ($last === '.') {
                $this->hasDecimal = false;
            }
            $this->display = substr($this->display, 0, -1);
        }
        $this->acLabel = 'C';
    }

    // ============================================================
    // Memory Functions
    // ============================================================

    public function mc(): void
    {
        $this->memory = '';
        $this->hasMemory = false;
    }

    public function mr(): void
    {
        if ($this->hasMemory && $this->memory !== '') {
            $this->display = $this->memory;
            $this->newInput = true;
            $this->hasDecimal = (strpos($this->memory, '.') !== false);
            $this->acLabel = 'C';
        }
    }

    public function mPlus(): void
    {
        $mem = $this->hasMemory ? (float)$this->memory : 0.0;
        $val = (float)$this->display;
        if ($this->display === 'Error') $val = 0.0;
        $result = $mem + $val;
        $this->memory = $this->formatNumber($result);
        $this->hasMemory = true;
    }

    public function mMinus(): void
    {
        $mem = $this->hasMemory ? (float)$this->memory : 0.0;
        $val = (float)$this->display;
        if ($this->display === 'Error') $val = 0.0;
        $result = $mem - $val;
        $this->memory = $this->formatNumber($result);
        $this->hasMemory = true;
    }

    public function ms(): void
    {
        if ($this->display === 'Error') return;
        $this->memory = $this->display;
        $this->hasMemory = true;
    }

    // ============================================================
    // Scientific Functions
    // ============================================================

    public function sin(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        $result = sin(deg2rad($val));
        $this->display = $this->formatNumber($result);
        $this->expression = 'sin(' . $val . ') =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function cos(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        $result = cos(deg2rad($val));
        $this->display = $this->formatNumber($result);
        $this->expression = 'cos(' . $val . ') =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function tan(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        $result = tan(deg2rad($val));
        $this->display = $this->formatNumber($result);
        $this->expression = 'tan(' . $val . ') =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function log(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        if ($val <= 0) { $this->display = 'Error'; return; }
        $result = log10($val);
        $this->display = $this->formatNumber($result);
        $this->expression = 'log(' . $val . ') =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function ln(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        if ($val <= 0) { $this->display = 'Error'; return; }
        $result = log($val);
        $this->display = $this->formatNumber($result);
        $this->expression = 'ln(' . $val . ') =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function x2(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        $result = $val * $val;
        $this->display = $this->formatNumber($result);
        $this->expression = $val . '² =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function x3(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        $result = $val * $val * $val;
        $this->display = $this->formatNumber($result);
        $this->expression = $val . '³ =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function sqrt(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        if ($val < 0) { $this->display = 'Error'; return; }
        $result = sqrt($val);
        $this->display = $this->formatNumber($result);
        $this->expression = '√(' . $val . ') =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function inv(): void
    {
        if ($this->display === 'Error') return;
        $val = (float)$this->display;
        if ($val == 0.0) { $this->display = 'Error'; return; }
        $result = 1.0 / $val;
        $this->display = $this->formatNumber($result);
        $this->expression = '1/(' . $val . ') =';
        $this->newInput = true;
        $this->acLabel = 'C';
    }

    public function pi(): void
    {
        $this->display = '3.141592653589793';
        $this->expression = 'π';
        $this->newInput = true;
        $this->hasDecimal = true;
        $this->acLabel = 'C';
    }

    public function euler(): void
    {
        $this->display = '2.718281828459045';
        $this->expression = 'e';
        $this->newInput = true;
        $this->hasDecimal = true;
        $this->acLabel = 'C';
    }

    public function openParen(): void
    {
        if ($this->expression === '') {
            $this->expression = $this->display . ' (';
        } else {
            $this->expression .= ' (';
        }
        $this->acLabel = 'C';
    }

    public function closeParen(): void
    {
        if ($this->expression === '') {
            $this->expression = $this->display . ' )';
        } else {
            $this->expression .= ' )';
        }
        $this->acLabel = 'C';
    }

    // ============================================================
    // History Management
    // ============================================================

    /** 添加历史记录 */
    public function addHistory(string $text, string $result): void
    {
        $id = (string)((int)$this->historyCounter + 1);
        $this->historyCounter = $id;
        $this->historyItems[] = ['id' => $id, 'text' => $text, 'result' => $result];
        if (count($this->historyItems) > 50) {
            array_shift($this->historyItems);
        }
    }

    /** 切换历史面板 */
    public function toggleHistory(): void
    {
        $this->showHistory = !$this->showHistory;
        $this->arrowText = $this->showHistory ? 'v' : '>';
    }

    /** 清除历史 */
    public function clearHistory(): void
    {
        $this->historyItems = [];
        $this->historyCounter = '0';
    }

    /** 加载历史条目到显示 */
    public function loadHistoryItem(string $id): void
    {
        foreach ($this->historyItems as $item) {
            if ((string)$item['id'] === $id) {
                $this->display = (string)$item['result'];
                $this->newInput = true;
                $this->hasDecimal = (strpos($this->display, '.') !== false);
                $this->acLabel = 'C';
                break;
            }
        }
    }

    // ============================================================
    // Cleanup handler for ScientificPad buttons (alias methods)
    // ============================================================

    public function clear(): void
    {
        if ($this->acLabel === 'AC') {
            $this->reset();
        } else {
            $this->display = '0';
            $this->expression = '';
            $this->hasDecimal = false;
            $this->newInput = true;
            $this->acLabel = 'AC';
        }
    }
</script>

<style>
.bg-app { background: #1C1C1E; }

/* B2: CSS transition 自动触发 — 按钮 hover 时背景色平滑过渡 */
.btn-number { transition: background-color 0.2s ease; }
.btn-operator { transition: background-color 0.15s ease-out; }
.btn-func { transition: opacity 0.2s ease; }

/* B3: @keyframes — 显示器数字更新时的脉冲动画 */
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.6; }
    100% { opacity: 1; }
}
.display-pulse { animation: pulse 0.3s ease; }

/* E4: 几何属性动画 — 历史面板展开/收起的高度过渡 */
.history-panel { transition: height 0.3s ease-in-out; }

/* B4: Transition 组件的 enter/leave 类名 */
.slide-enter-from { opacity: 0; height: 0; }
.slide-enter-active { transition: all 0.3s ease; }
.slide-enter-to { opacity: 1; }
.slide-leave-from { opacity: 1; }
.slide-leave-active { transition: all 0.2s ease; }
.slide-leave-to { opacity: 0; height: 0; }
</style>
