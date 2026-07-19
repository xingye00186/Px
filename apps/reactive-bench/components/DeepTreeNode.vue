<template>
  <div :style="'padding-left:10px'">
    <span :style="'font-size:12px;color:' + textColor">{{ label }}:{{ value }}</span>
    <deep-tree-node v-if="depth > 0" :depth="depth - 1" :label="label + '.child'" />
  </div>
</template>

<script lang="php">
    #[Reactive]
    public string $label = 'node';

    #[Reactive]
    public int $depth = 5;

    #[Reactive]
    public int $value = 0;

    #[Reactive]
    public string $textColor = '#CCCCCC';

    public function updateValue(int $v): void
    {
        $this->value = $v;
        $this->textColor = $v % 2 === 0 ? '#FF9F0A' : '#CCCCCC';
    }
</script>
