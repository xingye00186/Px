<template>
  <div id="app" style="width:328px;height:420px" title="VueCalc">
    <!-- 应用背景 -->
    <div style="left:0px;top:0px;width:328px;height:420px" class="app-bg"></div>

    <!-- 显示面板（子组件 DisplayPanel） -->
    <display-panel style="left:4px;top:4px" :value="display" />

    <!-- 表达式文本（小号，左上角，灰色） -->
    <span style="left:10px;top:10px;text-align:left" v-model="expression" v-if="expression" class="expr-text">{{ expression }}</span>

    <!-- 数字键盘（子组件 NumPad） -->
    <num-pad style="left:0px;top:80px" />

    <!-- About 按钮（?） -->
    <button style="left:290px;top:38px;width:30px;height:28px" class="btn-func" @click="toggleAboutDialog">?</button>

    <!-- 关于弹窗（子组件 AboutDialog） -->
    <about-dialog style="left:0px;top:0px" overlay v-if="showDialog" />
  </div>
</template>

<script lang="php">

    /** 当前显示值 */
    public string $display = '0';

    /** 表达式(显示在右上角) */
    public string $expression = '';

    /** 第一个操作数 */
    public string $operand1 = '';

    /** 当前运算符 */
    public string $operator = '';

    /** 是否开始新输入 */
    public bool $newInput = true;

    /** 是否已输入小数点 */
    public bool $hasDecimal = false;

    /** 对话框状态 */
    public bool $showDialog = false;

    /** 对话框文本 */
    public string $dialogTitle = 'About VueCalc';
    public string $dialogContent = 'SFC Data-Driven Calculator';
    public string $dialogVersion = 'Version 5.0 (M2)';
    public string $closeHint = '';

    /** 重置计算器状态 */
    public function reset(): void
    {
        $this->display = '0';
        $this->expression = '';
        $this->operand1 = '';
        $this->operator = '';
        $this->newInput = true;
        $this->hasDecimal = false;
    }

    /** 输入数字 */
    public function inputDigit(string $digit): void
    {
        if ($this->newInput) {
            $this->display = $digit;
            $this->newInput = false;
            $this->hasDecimal = false;
        } else {
            if ($this->display === '0' && $digit !== '.') {
                $this->display = $digit;
            } else {
                $this->display .= $digit;
            }
        }
    }

    /** 输入小数点 */
    public function inputDecimal(): void
    {
        if ($this->newInput) {
            $this->display = '0.';
            $this->newInput = false;
            $this->hasDecimal = true;
        } elseif (!$this->hasDecimal) {
            $this->display .= '.';
            $this->hasDecimal = true;
        }
    }

    /** 输入运算符 */
    public function inputOperator(string $op): void
    {
        if ($this->operator !== '' && !$this->newInput) {
            $this->calculate();
        }
        $this->operand1 = $this->display;
        $this->operator = $op;
        $this->expression = $this->operand1 . ' ' . $op;
        $this->newInput = true;
    }

    /** 执行计算 */
    public function calculate(): void
    {
        if ($this->operator === '' || $this->operand1 === '' || $this->newInput) {
            return;
        }

        $a = (float)$this->operand1;
        $b = (float)$this->display;
        $result = 0.0;

        $op = $this->operator;
        if ($op === '+') {
            $result = $a + $b;
        } elseif ($op === '-') {
            $result = $a - $b;
        } elseif ($op === '*') {
            $result = $a * $b;
        } elseif ($op === '/') {
            if ($b == 0.0) {
                $this->display = 'Error';
                $this->expression = '';
                $this->operand1 = '';
                $this->operator = '';
                $this->newInput = true;
                return;
            }
            $result = $a / $b;
        }

        if ($result == (float)(int)$result && abs($result) < 1000000000) {
            $this->display = (string)(int)$result;
        } else {
            $this->display = rtrim(rtrim(sprintf('%.8f', $result), '0'), '.');
        }

        $this->expression = '';
        $this->operand1 = '';
        $this->operator = '';
        $this->newInput = true;
        $this->hasDecimal = strpos($this->display, '.') !== false;
    }

    /** 退格 */
    public function backspace(): void
    {
        if ($this->newInput || $this->display === 'Error') {
            return;
        }
        if (strlen($this->display) <= 1) {
            $this->display = '0';
            $this->newInput = true;
        } else {
            $last = $this->display[strlen($this->display) - 1];
            if ($last === '.') {
                $this->hasDecimal = false;
            }
            $this->display = substr($this->display, 0, -1);
        }
    }

    /** 处理按钮点击 */
    public function handleButton(string $label): void
    {
        if ($label === '') {
            return;
        }
        if ($label === 'C') {
            $this->reset();
        } elseif ($label === '<-') {
            $this->backspace();
        } elseif ($label === '=') {
            $this->calculate();
        } elseif ($label === '+' || $label === '-' || $label === '*' || $label === '/') {
            $this->inputOperator($label);
        } elseif ($label === '.') {
            $this->inputDecimal();
        } else {
            $this->inputDigit($label);
        }
    }

    /** 切换关于弹窗 */
    public function toggleAboutDialog(): void
    {
        $this->showDialog = !$this->showDialog;
        $this->dirty = true;
    }
</script>

<style>
.app-bg       { background: #1e1e1e; }
.display-bg   { background: #2d2d2d; }
.expr-text    { font-size: 16px; color: #969696; }
.display-text { font-size: 32px; color: #ffffff; font-weight: bold; }
.btn-func     { background: #505050; color: #ffffff; }
</style>
