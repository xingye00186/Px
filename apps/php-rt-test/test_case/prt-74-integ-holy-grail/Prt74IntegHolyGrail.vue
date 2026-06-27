<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:8px;">Holy Grail Layout</div>
    <div style="display:flex;flex-direction:column;width:620px;background:#f5f5f5;border:1px solid #ccc;">
      <div style="height:48px;background:#1e1e2e;display:flex;align-items:center;justify-content:center;">
        <span style="font-size:16px;font-weight:600;color:#cdd6f4;">Header</span>
      </div>
      <div style="display:flex;min-height:200px;">
        <div style="width:120px;background:#181825;display:flex;flex-direction:column;align-items:center;justify-content:center;">
          <span style="font-size:14px;color:#6c7086;">Left Nav</span>
        </div>
        <div style="flex:1;background:#fff;display:flex;align-items:center;justify-content:center;">
          <span style="font-size:16px;font-weight:600;color:#333;">Main Content</span>
        </div>
        <div style="width:120px;background:#f5f5f5;border-left:1px solid #e0e0e0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
          <span style="font-size:14px;color:#666;">Right Sidebar</span>
        </div>
      </div>
      <div style="height:40px;background:#1e1e2e;display:flex;align-items:center;justify-content:center;">
        <span style="font-size:14px;color:#6c7086;">Footer</span>
      </div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt74IntegHolyGrailComponent extends \ReactiveComponent
{
    public function render(): \Px\Rendering\VNode
    {
        return \Px\Rendering\VNode::h('#root', ['style'=>'width:800px'],
            \Px\Rendering\VNode::h('div', ['style'=>'width:760px;box-sizing:border-box;background:#fff;border-radius:8px;padding:16px;border:1px solid #e0e0e0;position:relative;'], [
                \Px\Rendering\VNode::h('div', ['style'=>'position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;','data-px-anchor'=>'tl']),
                \Px\Rendering\VNode::h('div', ['style'=>'font-size:18px;font-weight:600;color:#333;margin-bottom:12px;'], 'Holy Grail Layout'),
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
    public function __construct(?string $componentId = null) { parent::__construct($componentId ?? 'Prt74IntegHolyGrail'); }
}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
