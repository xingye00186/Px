<template>
  <div style="width:400px;height:280px;position:relative" class="chart-line-wrapper">
    <div style="left:0px;top:0px;width:400px;height:280px;position:absolute" class="chart-bg"></div>
    <!-- Y 轴标签 -->
    <div style="left:0px;top:0px;width:40px;height:220px;position:absolute" class="chart-yaxis">
      <span v-for="(label, idx) in yLabels" :key="idx" :style="'left:0px;top:' + (idx * 44) + 'px;width:40px;height:16px;font-size:10px;color:#606266;text-align:right;position:absolute'">{{ label }}</span>
    </div>
    <!-- X 轴标签 -->
    <div style="left:40px;top:220px;width:360px;height:20px;position:absolute" class="chart-xaxis">
      <span v-for="(label, idx) in xLabels" :key="idx" :style="'left:' + (idx * 72) + 'px;top:0px;width:72px;height:20px;font-size:10px;color:#606266;text-align:center;position:absolute'">{{ label }}</span>
    </div>
    <!-- 折线绘制区 -->
    <div style="left:40px;top:0px;width:360px;height:220px;position:absolute" class="chart-plot">
      <!-- 模拟折线点 -->
      <div v-for="(point, idx) in dataPoints" :key="idx" :style="'left:' + point.x + 'px;top:' + point.y + 'px;width:8px;height:8px;border-radius:50%;background:#409EFF;position:absolute'" class="data-point"></div>
    </div>
    <!-- 图例 -->
    <div style="left:40px;top:240px;width:360px;height:20px;position:absolute" class="chart-legend">
      <span style="left:0px;top:2px;width:60px;height:16px;font-size:10px;color:#606266;position:absolute">{{ legend }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 数据 (数组) */
    public array $data = [120,200,150,80,70,110,130];

    /** X 轴标签 (数组) */
    public array $xLabels = ["Jan","Feb","Mar","Apr","May","Jun","Jul"];

    /** Y 轴最大值 */
    public string $maxValue = '300';

    /** Y 轴最小值 */
    public string $minValue = '0';

    /** 图表标题 */
    public string $title = 'Line Chart';

    /** 图例名称 */
    public string $legend = 'Value';

    /**
     * 计算数据点坐标
     */
    public function getDataPoints(): array
    {
        $vals = $this->data;
        $max = (float)$this->maxValue;
        $min = (float)$this->minValue;
        $count = count($vals);
        if ($count <= 1) return [];
        $plotW = 360;
        $plotH = 220;
        $points = [];
        $stepX = $plotW / ($count - 1);
        for ($i = 0; $i < $count; $i++) {
            $v = (float)($vals[$i] ?? 0);
            $normalized = ($v - $min) / ($max - $min);
            $x = (int)($i * $stepX);
            $y = (int)($plotH - ($normalized * $plotH));
            if ($y < 0) $y = 0;
            if ($y > $plotH) $y = $plotH;
            $points[] = ['x' => $x, 'y' => $y, 'value' => $v];
        }
        return $points;
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
.chart-line-wrapper { background: #FFFFFF; border: 1px solid #E8E8E8; position: relative; }
.chart-bg { background: #FAFAFA; }
.chart-yaxis { position: absolute; background: transparent; }
.chart-xaxis { position: absolute; bottom: 0; background: transparent; }
.chart-plot { position: absolute; background: transparent; }
.data-point { position: absolute; }
.chart-legend { position: absolute; bottom: 0; background: transparent; }
</style>