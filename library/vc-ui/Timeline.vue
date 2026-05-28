<template>
  <div style="width:300px;height:auto;min-height:200px" class="timeline-wrapper">
    <div style="left:20px;top:0px;width:4px;height:100%" class="timeline-line"></div>
    <div v-for="(item, idx) in items" :key="idx" style="left:0px;top:0px;width:300px;height:60px" class="timeline-item">
      <div :style="'left:12px;top:' + (idx * 60 + 16) + 'px;width:12px;height:12px;border-radius:50%;'" :class="item.cls"></div>
      <div :style="'left:36px;top:' + (idx * 60) + 'px;width:264px;height:60px'" class="timeline-content">
        <span style="left:0px;top:4px;width:264px;height:20px;font-size:14px;font-weight:bold;color:#303133">{{ item.timestamp }}</span>
        <span style="left:0px;top:28px;width:264px;height:28px;font-size:13px;color:#606266">{{ item.content }}</span>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 时间线数据 */
    public array $data = [["timestamp"=>"2025-01-01","content"=>"Event 1"],["timestamp"=>"2025-01-15","content"=>"Event 2"]];

    /** 是否使用圆点 */
    public string $dot = '1';

    /** 排列方向 */
    public string $direction = 'vertical';

    /**
     * 获取时间线项
     */
    public function getItems(): array
    {
        $decoded = $this->data;
        $result = [];
        foreach ($decoded as $item) {
            $cls = 'timeline-dot';
            $type = $item['type'] ?? '';
            if ($type === 'success') $cls .= ' timeline-dot-success';
            elseif ($type === 'warning') $cls .= ' timeline-dot-warning';
            elseif ($type === 'error') $cls .= ' timeline-dot-error';
            else $cls .= ' timeline-dot-primary';
            $result[] = [
                'timestamp' => ($item['timestamp'] ?? ''),
                'content' => ($item['content'] ?? ''),
                'type' => $type,
                'cls' => $cls
            ];
        }
        return $result;
    }
</script>

<style>
.timeline-wrapper { background: transparent; }
.timeline-line { background: #E8E8E8; position: absolute; }
.timeline-item { position: relative; }
.timeline-content { background: transparent; }
.timeline-dot { background: #409EFF; }
.timeline-dot-primary { background: #409EFF; }
.timeline-dot-success { background: #67C23A; }
.timeline-dot-warning { background: #E6A23C; }
.timeline-dot-error { background: #F56C6C; }
</style>