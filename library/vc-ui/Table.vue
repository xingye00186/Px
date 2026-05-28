<template>
  <div style="width:100%;height:100%">
    <!-- 表头 -->
    <div style="left:0px;top:0px;width:100%;height:36px" class="table-header">
      <div v-for="col in colList" :key="col.prop" :style="'width:' . $col['width'] . 'px;height:36px'" class="th-cell">{{ col.label }}</div>
    </div>
    <!-- 表体 -->
    <div style="left:0px;top:36px;width:100%;height:calc(100%-36px)" class="table-body">
      <div v-for="(row, idx) in rowList" :key="idx" :style="'left:0px;top:' . ($idx * 36) . 'px;width:100%;height:36px'" class="tr-row">
        <div v-for="col in colList" :key="col.prop" :style="'width:' . $col['width'] . 'px;height:36px'" class="td-cell">{{ getCell(row, col.prop) }}</div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 表格数据 */
    public array $data = [];

    /** 列配置 */
    public array $columns = [];

    /** 当前页 */
    public string $currentPage = '1';

    /** 每页条数 */
    public string $pageSize = '10';

    /**
     * 获取列列表
     */
    public function getColList(): array
    {
        $cols = $this->columns;
        $result = [];
        foreach ($cols as $col) {
            $result[] = [
                'prop' => $col['prop'] ?? '',
                'label' => $col['label'] ?? '',
                'width' => (int)($col['width'] ?? 120),
            ];
        }
        return $result;
    }

    /**
     * 获取行列表
     */
    public function getRowList(): array
    {
        $rows = $this->data;
        $page = max(1, (int)$this->currentPage);
        $size = max(1, (int)$this->pageSize);
        $offset = ($page - 1) * $size;
        return array_slice($rows, $offset, $size);
    }

    /**
     * 获取单元格值
     */
    public function getCell(array $row, string $prop): string
    {
        return $row[$prop] ?? '';
    }
</script>

<style>
.table-header { background: #F5F7FA; }
.th-cell { background: #F5F7FA; color: #909399; font-size: 13px; font-weight: bold; border-bottom: 1px solid #DCDFE6; }
.table-body { overflow-y: auto; }
.tr-row { border-bottom: 1px solid #F2F6FC; }
.td-cell { background: #FFFFFF; color: #606266; font-size: 13px; border-right: 1px solid #F2F6FC; }
</style>