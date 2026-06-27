<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:12px;">Overflow</div>
    <div style="display:flex;gap:12px;">
      <div><div style="font-size:12px;font-weight:600;color:#666;">visible</div>
        <div style="width:100px;height:50px;background:#e3f2fd;border:1px solid #1565c0;overflow:visible;">
          <div style="width:150px;height:20px;background:#bbdefb;"></div></div></div>
      <div><div style="font-size:12px;font-weight:600;color:#666;">hidden</div>
        <div style="width:100px;height:50px;background:#fce4ec;border:1px solid #c62828;overflow:hidden;">
          <div style="width:150px;height:20px;background:#ffcdd2;"></div></div></div>
      <div><div style="font-size:12px;font-weight:600;color:#666;">scroll</div>
        <div style="width:100px;height:50px;background:#e8f5e9;border:1px solid #2e7d32;overflow:scroll;">
          <div style="width:150px;height:70px;background:#c8e6c9;"></div></div></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt10OverflowComponent extends \ReactiveComponent
{
    public function render(): \Px\Rendering\VNode
    {
        return \Px\Rendering\VNode::h('#root', ['style'=>'width:800px'],
            \Px\Rendering\VNode::h('div', ['style'=>'width:760px;box-sizing:border-box;background:#fff;border-radius:8px;padding:16px;border:1px solid #e0e0e0;position:relative;'], [
                \Px\Rendering\VNode::h('div', ['style'=>'position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;','data-px-anchor'=>'tl']),
                \Px\Rendering\VNode::h('div', ['style'=>'font-size:18px;font-weight:600;color:#333;margin-bottom:12px;'], 'Overflow'),
                // TODO: generate VNode tree from inline HTML
                \Px\Rendering\VNode::h('div', ['style'=>'position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;','data-px-anchor'=>'br']),
            ])
        );
    }
    public function dispatchClick(string $handler, ?string $arg = null): void { if ($this->parent !== null) $this->parent->dispatchClick($handler, $arg); }
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void { if ($this->parent !== null) $this->parent->dispatchKey($handler, $action, $keyCode, $char); }
    public function setBindValue(string $bindKey, string $value): void {}
    public function getBindValue(string $bindKey): string { return ''; }
    public function resolveComponent(string $tag): string { $parts=explode('-',$tag); $cn=''; foreach($parts as $p) $cn.=ucfirst($p); return $cn.'Component'; }
    public function getClassStyles(): array { return []; }
    public function __construct(?string $componentId = null) { parent::__construct($componentId ?? 'Prt10Overflow'); }
}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
