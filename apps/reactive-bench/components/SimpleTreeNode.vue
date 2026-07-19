<template>
  <div style="display:flex;flex-direction:column;gap:2px;padding-left:16px">
    <div style="display:flex;align-items:center;gap:6px;height:22px;cursor:pointer" @click="toggle">
      <span :style="'font-size:12px;color:' + (expanded ? '#FF9F0A' : '#8E8E93')">{{ expanded ? '▼' : '▶' }}</span>
      <span style="font-size:13px;color:#FFF;font-weight:bold">{{ label }}</span>
      <span style="font-size:11px;color:#8E8E93">[{{ childCount }}]</span>
    </div>

    <div v-if="expanded" style="display:flex;flex-direction:column;gap:2px;padding-left:8px;border-left:1px solid #333">
      <simple-tree-node v-for="child in children" :key="child.id"
        :label="child.label" :children="child.children" :depth="depth - 1" />
    </div>
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $label = 'node';

    #[Reactive]
    public int $depth = 3;

    #[Reactive]
    public bool $expanded = false;

    #[Reactive]
    public int $childCount = 0;

    #[Reactive]
    public array $children = [];

    public function toggle(): void
    {
        $this->expanded = !$this->expanded;
    }

    public function initNode(string $name, int $d, int $fanout): void
    {
        $this->label = $name;
        $this->depth = $d;
        $this->expanded = ($d >= 2);
        if ($d <= 0) { $this->children = []; $this->childCount = 0; return; }
        $children = [];
        for ($i = 0; $i < $fanout; $i++) {
            $children[] = ['id' => $name . '.' . $i, 'label' => $name . '-' . $i, 'children' => []];
        }
        $this->children = $children;
        $this->childCount = count($children);
    }
</script>
