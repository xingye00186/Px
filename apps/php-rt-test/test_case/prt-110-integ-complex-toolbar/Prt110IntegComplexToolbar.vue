<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:12px;color:#999;margin-bottom:8px;">flex toolbar with tags, search, avatar, absolute badge</div>
    <div style="width:620px;background:#f5f5f5;border:1px solid #ccc;"><div style="display:flex;align-items:center;height:44px;background:#1e1e2e;padding:0 8px;gap:4px;"><div style="width:28px;height:28px;background:#45475a;border-radius:4px;"></div><div style="width:1px;height:24px;background:#585b70;"></div><div style="width:28px;height:28px;background:#45475a;border-radius:4px;"></div><div style="width:28px;height:28px;background:#45475a;border-radius:4px;"></div><div style="width:28px;height:28px;background:#45475a;border-radius:4px;"></div><div style="width:1px;height:24px;background:#585b70;"></div><div style="width:120px;height:28px;background:#313244;border:1px solid #585b70;border-radius:4px;margin:0 4px;"></div><div style="flex:1;"></div><div style="position:relative;"><div style="width:28px;height:28px;background:#585b70;border-radius:50%;"></div><div style="width:8px;height:8px;background:#f5576c;border:1px solid #fff;border-radius:50%;position:absolute;top:-2px;right:-2px;"></div></div></div><div style="display:flex;flex-wrap:wrap;gap:4px;padding:8px;background:#fff;border-top:1px solid #e0e0e0;"><div style="height:28px;padding:0 10px;background:#e3f2fd;border:1px solid #1565c0;border-radius:4px;display:flex;align-items:center;"><span style="font-size:12px;color:#1565c0;">Tag1</span><div style="width:14px;height:14px;margin-left:4px;background:rgba(21,101,192,0.2);border-radius:3px;"></div></div><div style="height:28px;padding:0 10px;background:#fce4ec;border:1px solid #c62828;border-radius:4px;display:flex;align-items:center;"><span style="font-size:12px;color:#c62828;">LongTag</span><div style="width:14px;height:14px;margin-left:4px;background:rgba(198,40,40,0.2);border-radius:3px;"></div></div><div style="height:28px;padding:0 10px;background:#e8f5e9;border:1px solid #2e7d32;border-radius:4px;display:flex;align-items:center;"><span style="font-size:12px;color:#2e7d32;">Tag3</span></div></div></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt110IntegComplexToolbarComponent extends \ReactiveComponent {
  public function render(): \Px\Rendering\VNode {
    return \Px\Rendering\VNode::h('#root', ['style'=>'width:800px'],
      \Px\Rendering\VNode::h('div', ['style'=>'width:760px;box-sizing:border-box;background:#fff;border-radius:8px;padding:16px;border:1px solid #e0e0e0;position:relative;'], [
        \Px\Rendering\VNode::h('div', ['style'=>'position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;','data-px-anchor'=>'tl']),
        \Px\Rendering\VNode::h('div', ['style'=>'font-size:12px;color:#999;margin-bottom:8px;'], 'flex toolbar with tags, search, avatar, absolute badge'),
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
  public function __construct(?string $id = null) { parent::__construct($id ?? 'Prt110IntegComplexToolbar'); }
}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
