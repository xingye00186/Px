<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:12px;color:#999;margin-bottom:8px;">caption-side for table caption placement</div>
    <div style="width:400px;background:#f5f5f5;border:1px solid #ccc;"><div style="display:table;width:100%;border-collapse:collapse;"><div style="display:table-caption;caption-side:bottom;padding:6px;font-size:14px;font-weight:600;color:#333;">Table Caption (bottom)</div><div style="display:table-row;background:#e3f2fd;"><div style="display:table-cell;border:1px solid #1565c0;padding:6px;">Data 1</div><div style="display:table-cell;border:1px solid #1565c0;padding:6px;">Data 2</div></div><div style="display:table-row;background:#fce4ec;"><div style="display:table-cell;border:1px solid #c62828;padding:6px;">Data 3</div><div style="display:table-cell;border:1px solid #c62828;padding:6px;">Data 4</div></div></div></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt100TableCaptionComponent extends \ReactiveComponent {
  public function render(): \Px\Rendering\VNode {
    return \Px\Rendering\VNode::h('#root', ['style'=>'width:800px'],
      \Px\Rendering\VNode::h('div', ['style'=>'width:760px;box-sizing:border-box;background:#fff;border-radius:8px;padding:16px;border:1px solid #e0e0e0;position:relative;'], [
        \Px\Rendering\VNode::h('div', ['style'=>'position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;','data-px-anchor'=>'tl']),
        \Px\Rendering\VNode::h('div', ['style'=>'font-size:12px;color:#999;margin-bottom:8px;'], 'caption-side for table caption placement'),
        \Px\Rendering\VNode::h('div', ['style'=>'position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;','data-px-anchor'=>'br']),
      ])
    );
  }
  public function dispatchClick(string $h, ?string $a = null): void { if ($this->parent !== null) $this->parent->dispatchClick($h, $a); }
  public function dispatchKey(string $h, string $a, int $k, string $c): void { if ($this->parent !== null) $this->parent->dispatchKey($h, $a, $k, $c); }
  public function setBindValue(string $k, string $v): void {}
  public function getBindValue(string $k): string { return ''; }
  public function resolveComponent(string $tag): string { $p=explode('-',$tag); $n=''; foreach($p as $x) $n.=ucfirst($x); return $n.'Component'; }
  public function getClassStyles(): array { return []; }
  public function __construct(?string $id = null) { parent::__construct($id ?? 'Prt100TableCaption'); }
}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
