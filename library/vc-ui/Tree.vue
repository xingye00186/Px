<template>
  <div style="width:280px;height:auto;min-height:200px" class="tree-wrapper">
    <div style="left:0px;top:0px;width:280px;height:200px" class="tree-nodes">
      <div v-for="node in flattenedNodes" :key="node.key" :style="'left:' + (node.depth * 20) + 'px;top:' + (node.index * 28) + 'px;width:' + (280 - node.depth * 20) + 'px;height:28px'" :class="node.cls" @click="onNodeClick(node)">
        <span v-if="node.hasChildren" style="left:0px;top:4px;width:16px;height:20px;font-size:12px;color:#C0C4CC">{{ node.expanded ? '-' : '+' }}</span>
        <span v-if="showCheckbox === '1'" :style="'left:' + (node.depth > 0 ? 20 : 0) + 'px;top:4px;width:16px;height:20px;font-size:12px;color:#409EFF'" @click.stop="onCheck(node)">☑</span>
        <span :style="'left:' + ((node.depth > 0 ? 20 : 0) + 20) + 'px;top:4px;width:200px;height:20px;font-size:13px'">{{ node.label }}</span>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 树形数据 */
    public array $data = [["label"=>"Root","value"=>"root","children"=>[["label"=>"Node 1","value"=>"n1"],["label"=>"Node 2","value"=>"n2"]]]];

    /** 是否显示复选框 */
    public string $showCheckbox = '';

    /** 展开的节点 keys (v-model) */
    public string $expandedKeys = '';

    /** 选中的节点 keys (v-model) */
    public string $checkedKeys = '';

    /** 点击节点是否展开 */
    public string $expandOnClickNode = '1';

    /** 懒加载模式 */
    public string $lazyLoad = '';

    /**
     * 节点点击
     */
    public function onNodeClick(array $node): void
    {
        if ($this->expandOnClickNode === '1' && ($node['hasChildren'] ?? false)) {
            $key = $node['key'] ?? '';
            $keys = $this->parseKeys($this->expandedKeys);
            if (in_array($key, $keys)) {
                $keys = array_filter($keys, fn($k) => $k !== $key);
            } else {
                $keys[] = $key;
            }
            $this->expandedKeys = json_encode(array_values($keys));
        }
    }

    /**
     * 复选框点击
     */
    public function onCheck(array $node): void
    {
        $key = $node['key'] ?? '';
        $keys = $this->parseKeys($this->checkedKeys);
        if (in_array($key, $keys)) {
            $keys = array_filter($keys, fn($k) => $k !== $key);
        } else {
            $keys[] = $key;
        }
        $this->checkedKeys = json_encode(array_values($keys));
    }

    /**
     * 解析 keys 字符串
     */
    private function parseKeys(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 扁平化树结构
     */
    public function getFlattenedNodes(): array
    {
        $treeData = $this->data;
        $expandedKeys = $this->parseKeys($this->expandedKeys);
        return $this->flattenRecursive($treeData, 0, 0, $expandedKeys);
    }

    /**
     * 递归扁平化
     */
    private function flattenRecursive(array $nodes, int $depth, int $index, array $expandedKeys): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $key = $node['value'] ?? '';
            $hasChildren = isset($node['children']) && is_array($node['children']) && count($node['children']) > 0;
            $expanded = in_array($key, $expandedKeys);
            $checked = in_array($key, $this->parseKeys($this->checkedKeys));
            $cls = $checked ? 'tree-node tree-node-checked' : 'tree-node';
            $result[] = [
                'key' => $key,
                'label' => ($node['label'] ?? ''),
                'depth' => $depth,
                'index' => $index,
                'hasChildren' => $hasChildren,
                'expanded' => $expanded,
                'cls' => $cls
            ];
            $index++;
            if ($hasChildren && $expanded) {
                $children = $this->flattenRecursive($node['children'], $depth + 1, $index, $expandedKeys);
                $result = array_merge($result, $children);
                $index = $result[count($result) - 1]['index'] + 1;
            }
        }
        return $result;
    }
</script>

<style>
.tree-wrapper { background: transparent; }
.tree-nodes { background: #FFFFFF; position: relative; }
.tree-node { color: #606266; font-size: 13px; line-height: 28px; padding-left: 8px; background: #FFFFFF; cursor: pointer; }
.tree-node:hover { background: #F5F7FA; color: #409EFF; }
.tree-node-checked { color: #409EFF; font-weight: bold; }
</style>