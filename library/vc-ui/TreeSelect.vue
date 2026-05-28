<template>
  <div style="width:240px;height:auto;min-height:36px" class="treeselect-wrapper">
    <div style="left:0px;top:0px;width:240px;height:36px" class="treeselect-trigger" @click="toggleOpen">
      <span style="left:12px;top:8px;width:200px;height:20px;font-size:14px" class="treeselect-value">{{ displayText }}</span>
      <span style="right:8px;top:10px;width:16px;height:16px" class="treeselect-arrow">▼</span>
    </div>
    <div v-if="isOpen === '1'" style="left:0px;top:36px;width:240px;height:200px" class="treeselect-panel">
      <div style="left:0px;top:0px;width:240px;height:32px" class="panel-search">
        <span style="left:8px;top:8px;width:80px;height:16px;font-size:12px;color:#909399">Search:</span>
        <span style="left:60px;top:8px;width:172px;height:16px;font-size:12px;color:#409EFF">@click="filterTree"</span>
      </div>
      <div style="left:0px;top:32px;width:240px;height:168px" class="tree-nodes">
        <div v-for="node in treeNodes" :key="node.key" style="left:0px;top:0px;width:240px;height:28px" :class="node.cls" @click="selectNode(node)">
          <span v-if="node.hasChildren" style="left:4px;top:4px;width:12px;height:16px;font-size:10px;color:#C0C4CC">{{ node.expanded ? '-' : '+' }}</span>
          <span style="left:20px;top:4px;width:200px;height:20px;font-size:13px">{{ node.label }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中值 (v-model) */
    public string $modelValue = '';

    /** 树形数据 (JSON) */
    public string $data = '[{"label":"Root","value":"root","children":[{"label":"Child 1","value":"c1"},{"label":"Child 2","value":"c2"}]}]';

    /** 占位符 */
    public string $placeholder = 'Select...';

    /** 是否多选 */
    public string $multiple = '';

    /** 是否可搜索 */
    public string $filterable = '';

    /** 是否展开 */
    public string $isOpen = '';

    /** 展开的节点 */
    public string $expandedKeys = '';

    /**
     * 切换展开
     */
    public function toggleOpen(): void
    {
        if ($this->isOpen === '1') {
            $this->isOpen = '';
        } else {
            $this->isOpen = '1';
        }
    }

    /**
     * 选择节点
     */
    public function selectNode(array $node): void
    {
        $this->modelValue = $node['value'] ?? '';
        $this->isOpen = '';
    }

    /**
     * 过滤树
     */
    public function filterTree(): void
    {
        // 搜索功能演示
    }

    /**
     * 获取显示文字
     */
    public function getDisplayText(): string
    {
        if ($this->modelValue === '') {
            return $this->placeholder;
        }
        $treeData = json_decode($this->data, true);
        return $this->findLabel($treeData, $this->modelValue) ?: $this->placeholder;
    }

    /**
     * 递归查找标签
     */
    private function findLabel(array $nodes, string $value): string
    {
        foreach ($nodes as $node) {
            if (($node['value'] ?? '') === $value) {
                return $node['label'] ?? '';
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $found = $this->findLabel($node['children'], $value);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    /**
     * 获取树节点
     */
    public function getTreeNodes(): array
    {
        $treeData = json_decode($this->data, true);
        if (!is_array($treeData)) return [];
        return $this->flattenTree($treeData, 0);
    }

    /**
     * 扁平化树
     */
    private function flattenTree(array $nodes, int $depth): array
    {
        $result = [];
        $indent = $depth * 16;
        foreach ($nodes as $node) {
            $v = $node['value'] ?? '';
            $hasChildren = isset($node['children']) && is_array($node['children']) && count($node['children']) > 0;
            $isSelected = ($v === $this->modelValue);
            $cls = $isSelected ? 'tree-node tree-node-selected' : 'tree-node';
            $result[] = [
                'key' => $v,
                'value' => $v,
                'label' => ($node['label'] ?? ''),
                'hasChildren' => $hasChildren,
                'expanded' => false,
                'cls' => $cls
            ];
            if ($hasChildren) {
                $children = $this->flattenTree($node['children'], $depth + 1);
                $result = array_merge($result, $children);
            }
        }
        return $result;
    }
</script>

<style>
.treeselect-wrapper { background: transparent; }
.treeselect-trigger { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #DCDFE6; }
.treeselect-value { color: #606266; font-size: 14px; }
.treeselect-arrow { color: #C0C4CC; font-size: 10px; }
.treeselect-panel { background: #FFFFFF; border: 1px solid #DCDFE6; box-shadow: 0 2px 12px rgba(0,0,0,0.15); z-index: 1000; position: absolute; overflow-y: auto; }
.panel-search { background: #F5F7FA; border-bottom: 1px solid #E8E8E8; }
.tree-nodes { background: #FFFFFF; }
.tree-node { color: #606266; font-size: 13px; line-height: 28px; padding-left: 8px; background: #FFFFFF; }
.tree-node:hover { background: #F5F7FA; color: #409EFF; }
.tree-node-selected { background: #ECF5FF; color: #409EFF; font-weight: bold; }
</style>