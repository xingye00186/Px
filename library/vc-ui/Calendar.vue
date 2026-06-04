<template>
  <div style="position:relative;width:340px;height:320px" class="calendar-wrapper">
    <!-- 头部导航 -->
    <div style="position:absolute;left:0;top:0;width:340px;height:40px" class="cal-header">
      <span style="position:absolute;left:12px;top:10px;width:60px;height:20px;font-size:14px;color:#409EFF" @click="prevMonth">&lt; Prev</span>
      <span style="position:absolute;left:80px;top:10px;width:180px;height:20px;font-size:16px;font-weight:bold;color:#303133;text-align:center">{{ currentYear }}-{{ currentMonthStr }}</span>
      <span style="position:absolute;right:60px;top:10px;width:60px;height:20px;font-size:14px;color:#409EFF" @click="nextMonth">Next &gt;</span>
      <span style="position:absolute;right:12px;top:10px;width:40px;height:20px;font-size:12px;color:#67C23A" @click="goToday">Today</span>
    </div>
    <!-- 星期标题 -->
    <div style="position:absolute;left:0;top:40px;width:340px;height:28px" class="cal-weekdays">
      <span v-for="d in weekDays" :key="d" style="width:48.57px;height:28px;font-size:12px;color:#909399" class="weekday">{{ d }}</span>
    </div>
    <!-- 日期网格 -->
    <div style="position:absolute;left:0;top:68px;width:340px;height:252px" class="cal-grid">
      <div v-for="cell in calendarCells" :key="cell.key" :style="'left:' + (cell.col * 48.57) + 'px;top:' + (cell.row * 36) + 'px;width:48.57px;height:36px'" :class="cell.cls" @click="selectDay(cell.day)">
        <span style="left:4px;top:8px;width:40px;height:20px;font-size:14px;text-align:center">{{ cell.day }}</span>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中日期 (v-model, YYYY-MM-DD) */
    public string $modelValue = '';

    /** 是否可编辑 */
    public string $editable = '1';

    /** 星期标题 */
    public array $weekDays = ["Su","Mo","Tu","We","Th","Fr","Sa"];

    /** 当前查看年份 */
    public string $currentYear = '2025';

    /** 当前查看月份 */
    public string $currentMonth = '1';

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
     * 跳转今天
     */
    public function goToday(): void
    {
        $now = getdate();
        $this->currentYear = (string)$now['year'];
        $this->currentMonth = (string)$now['mon'];
        $m = $now['mon'];
        $d = $now['mday'];
        $this->modelValue = $now['year'] . '-' . ($m < 10 ? '0' . $m : (string)$m) . '-' . ($d < 10 ? '0' . $d : (string)$d);
    }

    /**
     * 选择日期
     */
    public function selectDay(string $day): void
    {
        if ($day === '') return;
        $m = (int)$this->currentMonth;
        $y = (int)$this->currentYear;
        $this->modelValue = $y . '-' . ($m < 10 ? '0' . $m : (string)$m) . '-' . ($day < 10 ? '0' . $day : $day);
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
     * 生成日历单元格
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
            $cells[] = ['key' => 'e' . $i, 'day' => '', 'col' => $i % 7, 'row' => (int)($i / 7), 'cls' => 'cal-cell cal-cell-empty'];
        }
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $idx = $firstWday + $d - 1;
            $isToday = ($y === (int)date('Y') && $m === (int)date('n') && $d === (int)date('j'));
            $isSelected = ($this->modelValue !== '' && $this->modelValue === $y . '-' . ($m < 10 ? '0' . $m : (string)$m) . '-' . ($d < 10 ? '0' . $d : (string)$d));
            $cls = 'cal-cell';
            if ($isToday) $cls .= ' cal-cell-today';
            if ($isSelected) $cls .= ' cal-cell-selected';
            $cells[] = ['key' => 'd' . $d, 'day' => (string)$d, 'col' => $idx % 7, 'row' => (int)($idx / 7), 'cls' => $cls];
        }
        return $cells;
    }
</script>

<style>
.calendar-wrapper { background: #FFFFFF; border: 1px solid #DCDFE6; }
.cal-header { background: #F5F7FA; border-bottom: 1px solid #E8E8E8; }
.cal-weekdays { background: #FAFAFA; border-bottom: 1px solid #E8E8E8; display: flex; }
.weekday { text-align: center; line-height: 28px; }
.cal-grid { position: relative; }
.cal-cell { color: #606266; font-size: 14px; text-align: center; cursor: pointer; }
.cal-cell-empty { background: #FAFAFA; }
.cal-cell:hover { background: #ECF5FF; }
.cal-cell-today { color: #409EFF; font-weight: bold; }
.cal-cell-selected { background: #409EFF; color: #FFFFFF; }
</style>