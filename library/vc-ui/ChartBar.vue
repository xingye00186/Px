<template>
  <div style="width:400px;height:280px" class="chart-bar-wrapper">
    <div style="left:0px;top:0px;width:400px;height:280px" class="chart-bg"></div>
    <!-- Y 轴标签 -->
    <div style="left:0px;top:0px;width:40px;height:220px" class="chart-yaxis">
      <span v-for="(label, idx) in yLabels" :key="idx" :style="'left:0px;top:' + (idx * 44) + 'px;width:40px;height:16px;font-size:10px;color:#606266;text-align:right'">{{ label }}</span>
    </div>
    <!-- X 轴标签 -->
    <div style="left:40px;top:220px;width:360px;height:20px" class="chart-xaxis">
      <span v-for="(label, idx) in xLabels" :key="idx" :style="'left:' + (idx * 72 + 24) + 'px;top:0px;width:72px;height:20px;font-size:10px;color:#606266;text-align:center'">{{ label }}</span>
    </div>
    <!-- 柱状图绘制区 -->
    <div style="left:40px;top:0px;width:360px;height:220px" class="chart-plot">
      <div v-for="(bar, idx) in bars" :key="idx" :style="'left:' + bar.x + 'px;top:' + bar.y + 'px;width:' + bar.w + 'px;height:' + bar.h + 'px'" :class="bar.cls"></div>
    </div>
    <!-- 图例 -->
    <div style="left:40px;top:240px;width:360px;height:20px" class="chart-legend">
      <span style="left:0px;top:2px;width:60px;height:16px;font-size:10px;color:#606266">{{ legend }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 数据 (数组) */
    public array $data = [30,45,60,35,80,55,40];

    /** X 轴标签 (数组) */
    public array $xLabels = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"];

    /** Y 轴最大值 */
    public string $maxValue = '100';

    /** Y 轴最小值 */
    public string $minValue = '0';

    /** 图表标题 */
    public string $title = 'Bar Chart';

    /** 图例名称 */
    public string $legend = 'Value';

    /** 柱状图颜色 */
    public string $color = '#409EFF';

    /**
     * 计算柱状图数据
     */
    public function getBars(): array
    {
        $vals = $this->data;
        $max = (float)$this->maxValue;
        $min = (float)$this->minValue;
        $count = count($vals);
        $plotW = 360;
        $plotH = 220;
        $barW = (int)min(48, $plotW / $count - 8);
        $gap = ($plotW - $barW * $count) / ($count + 1);
        $bars = [];
        for ($i = 0; $i < $count; $i++) {
            $v = (float)($vals[$i] ?? 0);
            $normalized = ($v - $min) / ($max - $min);
            $h = (int)($normalized * $plotH);
            if ($h < 2) $h = 2;
            $x = (int)($gap + $i * ($barW + $gap));
            $y = $plotH - $h;
            $bars[] = [
                'x' => $x,
                'y' => $y,
                'w' => $barW,
                'h' => $h,
                'value' => $v,
                'cls' => 'bar-rect'
            ];
        }
        return $bars;
    }

    /**
     * 获取 Y 轴标签
     */
    public function getYLabels(): array
    {
        $max = (float)$this->maxValue;
        $min = (float)$this->minValue;
        $step = ($max - $min) / 5;
        $labels = [];
        for ($i = 5; $i >= 0; $i--) {
            $val = $min + ($i * $step);
            $labels[] = (string)round($val);
        }
        return $labels;
    }
</script>

<style>
.chart-bar-wrapper { background: #FFFFFF; border: 1px solid #E8E8E8; position: relative; }
.chart-bg { background: #FAFAFA; }
.chart-yaxis { position: absolute; background: transparent; }
.chart-xaxis { position: absolute; bottom: 0; background: transparent; }
.chart-plot { position: absolute; background: transparent; }
.bar-rect { background: #409EFF; }
.bar-rect:hover { background: #66B1FF; }
.chart-legend { position: absolute; bottom: 0; background: transparent; }
</style>