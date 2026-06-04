<template>
  <div style="position:relative;width:240px;height:36px" class="datepicker-wrapper">
    <!-- 输入框 + 图标 -->
    <div style="position:absolute;left:0;top:0;width:240px;height:36px" class="datepicker-trigger" @click="toggleOpen">
      <span style="position:absolute;left:12px;top:8px;width:200px;height:20px;font-size:14px" class="datepicker-value">{{ displayText }}</span>
      <span style="position:absolute;right:8px;top:10px;width:16px;height:16px" class="datepicker-icon">📅</span>
    </div>
    <!-- 日历浮层 -->
    <div v-if="isOpen === '1'" style="position:absolute;left:0;top:40px;width:294px;height:280px" class="datepicker-panel">
      <!-- 月份导航 -->
      <div style="position:absolute;left:0;top:0;width:294px;height:36px" class="panel-header">
        <span style="position:absolute;left:12px;top:8px;width:60px;height:20px" class="panel-nav" @click="prevMonth">&lt;</span>
        <span style="position:absolute;left:80px;top:8px;width:134px;height:20px;font-size:14px;font-weight:bold;color:#303133">{{ currentYear }}-{{ currentMonthStr }}</span>
        <span style="position:absolute;left:222px;top:8px;width:60px;height:20px" class="panel-nav" @click="nextMonth">&gt;</span>
      </div>
      <!-- 星期头 -->
      <div style="position:absolute;left:0;top:36px;width:294px;height:24px" class="week-header">
        <span v-for="d in weekDays" :key="d" style="width:42px;height:24px;font-size:12px;color:#909399" class="week-day">{{ d }}</span>
      </div>
      <!-- 日期网格 -->
      <div style="position:absolute;left:0;top:60px;width:294px;height:180px" class="date-grid">
        <span v-for="cell in calendarCells" :key="cell.key" :style="'width:42px;height:30px;left:' + cell.x + 'px;top:' + cell.y + 'px'" :class="cell.cls" @click="selectDate(cell.day)">{{ cell.day }}</span>
      </div>
      <!-- 快捷选项 -->
      <div style="position:absolute;left:0;top:240px;width:294px;height:36px" class="shortcuts">
        <span style="position:absolute;left:12px;top:8px;width:60px;height:20px;font-size:12px;color:#409EFF" @click="selectToday">Today</span>
        <span style="position:absolute;left:82px;top:8px;width:60px;height:20px;font-size:12px;color:#409EFF" @click="clearDate">Clear</span>
        <span style="position:absolute;left:152px;top:8px;width:60px;height:20px;font-size:12px;color:#409EFF" @click="selectWeek">Week</span>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中值 (v-model, YYYY-MM-DD) */
    public string $modelValue = '';

    /** 类型: date | datetime | daterange */
    public string $type = 'date';

    /** 显示格式 */
    public string $format = 'YYYY-MM-DD';

    /** 占位符 */
    public string $placeholder = 'Select date...';

    /** 是否可清空 */
    public string $clearable = '1';

    /** 是否展开 */
    public string $isOpen = '';

    /** 当前查看年份 */
    public string $currentYear = '2025';

    /** 当前查看月份 (1-12) */
    public string $currentMonth = '1';

    /** 星期标题 */
    public array $weekDays = ["Su","Mo","Tu","We","Th","Fr","Sa"];

    /**
     * 切换日历
     */
    public function toggleOpen(): void
    {
        if ($this->isOpen === '1') {
            $this->isOpen = '';
        } else {
            // 解析选中日期初始化视图
            if ($this->modelValue !== '') {
                $parts = explode('-', $this->modelValue);
                if (count($parts) >= 2) {
                    $this->currentYear = $parts[0];
                    $this->currentMonth = (int)($parts[1]);
                }
            } else {
                $now = getdate();
                $this->currentYear = (string)$now['year'];
                $this->currentMonth = (string)$now['mon'];
            }
            $this->isOpen = '1';
        }
    }

    /**
     * 上月
     */
    public function prevMonth(): void
    {
        $m = (int)$this->currentMonth;
        $y = (int)$this->currentYear;
        if ($m <= 1) {
            $this->currentMonth = '12';
            $this->currentYear = (string)($y - 1);
        } else {
            $this->currentMonth = (string)($m - 1);
        }
    }

    /**
     * 下月
     */
    public function nextMonth(): void
    {
        $m = (int)$this->currentMonth;
        $y = (int)$this->currentYear;
        if ($m >= 12) {
            $this->currentMonth = '1';
            $this->currentYear = (string)($y + 1);
        } else {
            $this->currentMonth = (string)($m + 1);
        }
    }

    /**
     * 选择日期
     */
    public function selectDate(string $day): void
    {
        if ($day === '') return;
        $m = (int)$this->currentMonth;
        $this->modelValue = $this->currentYear . '-' . ($m < 10 ? '0' . $m : (string)$m) . '-' . ($day < 10 ? '0' . $day : $day);
        $this->isOpen = '';
    }

    /**
     * 今天
     */
    public function selectToday(): void
    {
        $now = getdate();
        $this->currentYear = (string)$now['year'];
        $this->currentMonth = (string)$now['mon'];
        $m = $now['mon'];
        $d = $now['mday'];
        $this->modelValue = $now['year'] . '-' . ($m < 10 ? '0' . $m : (string)$m) . '-' . ($d < 10 ? '0' . $d : (string)$d);
        $this->isOpen = '';
    }

    /**
     * 清空
     */
    public function clearDate(): void
    {
        $this->modelValue = '';
        $this->isOpen = '';
    }

    /**
     * 本周
     */
    public function selectWeek(): void
    {
        $now = getdate();
        $wday = $now['wday'];
        $mday = $now['mday'];
        $mon = $now['mon'];
        $year = $now['year'];
        $diff = $wday;
        $startDay = $mday - $diff;
        if ($startDay < 1) {
            $mon = $mon - 1;
            if ($mon < 1) {
                $mon = 12;
                $year = $year - 1;
            }
            $daysInPrev = cal_days_in_month(CAL_GREGORIAN, $mon, $year);
            $startDay = $daysInPrev + $startDay;
        }
        $m = $mon;
        $d = $startDay;
        $this->modelValue = $year . '-' . ($m < 10 ? '0' . $m : (string)$m) . '-' . ($d < 10 ? '0' . $d : (string)$d);
        $this->isOpen = '';
    }

    /**
     * 获取显示文字
     */
    public function getDisplayText(): string
    {
        if ($this->modelValue === '') {
            return $this->placeholder;
        }
        return $this->modelValue;
    }

    /**
     * 获取月份字符串
     */
    public function getCurrentMonthStr(): string
    {
        $m = (int)$this->currentMonth;
        return $m < 10 ? '0' . $m : (string)$m;
    }

    /**
     * 生成日历网格
     */
    public function getCalendarCells(): array
    {
        $y = (int)$this->currentYear;
        $m = (int)$this->currentMonth;
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $m, $y);
        $firstWday = jddayofweek(gregoriantojd($m, 1, $y), 0);
        $cells = [];
        $totalCells = $daysInMonth + $firstWday;
        $rows = (int)ceil($totalCells / 7);

        for ($i = 0; $i < $firstWday; $i++) {
            $col = $i % 7;
            $row = (int)($i / 7);
            $cells[] = ['key' => 'e' . $i, 'day' => '', 'x' => $col * 42, 'y' => $row * 30, 'cls' => 'date-cell-empty'];
        }
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $idx = $firstWday + $d - 1;
            $col = $idx % 7;
            $row = (int)($idx / 7);
            $isToday = ($y === (int)date('Y') && $m === (int)date('n') && $d === (int)date('j'));
            $isSelected = ($this->modelValue !== '' && $this->modelValue === $y . '-' . ($m < 10 ? '0' . $m : (string)$m) . '-' . ($d < 10 ? '0' . $d : (string)$d));
            $cls = 'date-cell';
            if ($isToday) $cls .= ' date-cell-today';
            if ($isSelected) $cls .= ' date-cell-selected';
            $cells[] = ['key' => 'd' . $d, 'day' => (string)$d, 'x' => $col * 42, 'y' => $row * 30, 'cls' => $cls];
        }
        return $cells;
    }
</script>

<style>
.datepicker-wrapper { background: transparent; }
.datepicker-trigger { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #DCDFE6; }
.datepicker-value { color: #606266; font-size: 14px; }
.datepicker-icon { color: #C0C4CC; font-size: 14px; }
.datepicker-panel { background: #FFFFFF; border: 1px solid #DCDFE6; box-shadow: 0 2px 12px rgba(0,0,0,0.15); z-index: 1000; position: absolute; }
.panel-header { background: #F5F7FA; }
.panel-nav { color: #409EFF; font-size: 14px; text-align: center; }
.week-header { background: #F5F7FA; border-bottom: 1px solid #E8E8E8; }
.week-day { text-align: center; line-height: 24px; }
.date-grid { position: relative; }
.date-cell { position: absolute; color: #606266; font-size: 14px; text-align: center; line-height: 30px; }
.date-cell-empty { position: absolute; }
.date-cell-today { color: #409EFF; font-weight: bold; }
.date-cell-selected { background: #409EFF; color: #FFFFFF; }
.shortcuts { border-top: 1px solid #E8E8E8; }
</style>