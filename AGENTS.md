# Px Framework 鈥� AI-Friendly 鍏ㄦ祦绋嬫寚鍗�

## 涓€銆佷竴鍙ヨ瘽姒傝堪

Px 鏄�竴涓� **PHP 鈫� 鍘熺敓 exe** 鐨勬�闈� GUI 妗嗘灦锛屾ā鏉胯�娉曞�鏍� **Vue 3**锛屾覆鏌撳紩鎿庡熀浜� **Win32 GDI**锛岄€氳繃 **Swoole Compiler** 瀹炵幇 AOT 缂栬瘧銆�

```
.vue 鏂囦欢 鈫� sfc-compiler.php 鈫� 鐢熸垚 PHP 绫� 鈫� Swoole Compiler 鈫� C++ 鈫� MSVC 鈫� .exe
```

---

## 浜屻€佺洰褰曠粨鏋勯€熸煡

```
d:/Px/
鈹溾攢鈹€ framework/              鏍稿績妗嗘灦锛堝彧璇伙紝鎵€鏈夊簲鐢ㄥ叡浜�級
鈹�   鈹溾攢鈹€ Core/
鈹�   鈹�   鈹溾攢鈹€ Application.php     浜嬩欢寰�幆銆佺粍浠舵敞鍐屻€乂Node 鏍戝睍寮€銆乥ind 瑙ｆ瀽
鈹�   鈹�   鈹溾攢鈹€ ScrollManager.php   婊氬姩鏈嶅姟锛堢姸鎬佺�鐞嗐€佹嫋鎷姐€佹粴杞�€佹按骞虫粴鍔�級
鈹�   鈹�   鈹斺攢鈹€ Scheduler.php       寰�换鍔�/瀹忎换鍔¤皟搴�
鈹�   鈹溾攢鈹€ Rendering/
鈹�   鈹�   鈹溾攢鈹€ VNode.php           铏氭嫙 DOM 鑺傜偣锛堝竷灞€瀛楁� + 婊氬姩瀛楁� + 缁勪欢鍗犱綅瀛楁�锛�
鈹�   鈹�   鈹溾攢鈹€ VNodeRenderer.php   鏍戦亶鍘� 鈫� 鏀堕泦鍏冪礌 鈫� 鎸� layer 鍒嗙粍 鈫� 璋冪敤 GDI
鈹�   鈹�   鈹溾攢鈹€ LayoutResolver.php  CSS 甯冨眬寮曟搸锛坆lock/flex/grid/scroll锛�
鈹�   鈹�   鈹溾攢鈹€ GdiRenderContext.php Win32 GDI 缁樺埗鍘熻�
鈹�   鈹�   鈹溾攢鈹€ CssMappings.php     CSS 灞炴€� 鈫� GDI 灞炴€ф槧灏�
鈹�   鈹�   鈹斺攢鈹€ RenderContext.php   娓叉煋涓婁笅鏂囨帴鍙�
鈹�   鈹溾攢鈹€ Platform/
鈹�   鈹�   鈹溾攢鈹€ Platform.php        骞冲彴鎶借薄鎺ュ彛
鈹�   鈹�   鈹溾攢鈹€ Win32Platform.php    Win32 娑堟伅娉� + 浜嬩欢瑙ｇ爜
鈹�   鈹�   鈹溾攢鈹€ PlatformEvent.php   浜嬩欢绫诲瀷灞傜骇锛圡ouse/Keyboard/Window/Timer锛�
鈹�   鈹�   鈹溾攢鈹€ PlatformFactory.php 骞冲彴宸ュ巶
鈹�   鈹�   鈹斺攢鈹€ WinMsg.php          Win32 娑堟伅甯搁噺
鈹�   鈹溾攢鈹€ interfaces/
鈹�   鈹�   鈹斺攢鈹€ ComponentInterface.php  缁勪欢鎺ュ彛濂戠害
鈹�   鈹溾攢鈹€ compiler/
鈹�   鈹�   鈹溾攢鈹€ sfc-compiler.php    涓荤紪璇戝櫒锛�.vue 鈫� PHP 浠ｇ爜鐢熸垚锛�
鈹�   鈹�   鈹溾攢鈹€ template-parser.php 妯℃澘瑙ｆ瀽鍣�紙HTML 鈫� VNode 鏍戯級
鈹�   鈹�   鈹溾攢鈹€ script-analyzer.php  鑴氭湰鍒嗘瀽鍣�紙鑷�姩娉ㄥ叆 markDirty锛�
鈹�   鈹�   鈹溾攢鈹€ component-registry.php 缁勪欢娉ㄥ唽琛�
鈹�   鈹�   鈹斺攢鈹€ aot-validator.php    AOT 鍏煎�鎬ф�鏌�
鈹�   鈹溾攢鈹€ BaseComponent.php        缁勪欢鍩虹被锛堢敓鍛芥湡 + 鐖跺瓙灞傜骇锛�
鈹�   鈹溾攢鈹€ ReactiveComponent.php    鍝嶅簲寮忕粍浠跺熀绫伙紙dirty + VNode 缂撳瓨锛�
鈹�   鈹斺攢鈹€ aot-checker.php          AOT 瑙勫垯妫€鏌ュ伐鍏凤紙20K 琛岋級
鈹溾攢鈹€ apps/                  姣忎釜搴旂敤涓€涓�瓙鐩�綍
鈹�   鈹溾攢鈹€ calculator/            璁＄畻鍣ㄧず渚嬶紙4 涓�粍浠躲€丆SS Grid 甯冨眬锛�
鈹�   鈹溾攢鈹€ list-test/             鍒楄〃婊氬姩娴嬭瘯锛坴-for + scroll-container锛�
鈹�   鈹斺攢鈹€ test/                  鍩虹�娴嬭瘯搴旂敤
鈹溾攢鈹€ stub/                   PHP stub 鏂囦欢锛圕++ 鍘熺敓鍑芥暟澹版槑锛�
鈹溾攢鈹€ cpp/                    C++ 妗ユ帴灞傚疄鐜�
鈹溾攢鈹€ docs/                   璁捐�鏂囨。
鈹溾攢鈹€ tests/                  鍗曞厓娴嬭瘯锛圥HPUnit 椋庢牸 + 鎴�浘娴嬭瘯锛�
鈹�   鈹溾攢鈹€ unit/               鍗曞厓娴嬭瘯
鈹�   鈹溾攢鈹€ screenshot/         鎴�浘鑷�姩鍖栨祴璇曪紙PowerShell锛�
鈹�   鈹斺攢鈹€ run_all_tests.php   缁熶竴娴嬭瘯杩愯�鍣�
鈹溾攢鈹€ build.bat               闈炰氦浜掓瀯寤鸿剼鏈�
鈹溾攢鈹€ sfc-compiler.php        缂栬瘧鍣ㄥ叆鍙ｏ紙妗嗘灦鏍圭洰褰曪級
鈹溾攢鈹€ config.yml              缂栬瘧鍣ㄨ矾寰勯厤缃�
鈹斺攢鈹€ vendor/                 渚濊禆锛圕omposer锛�
```

### 搴旂敤鐩�綍妯℃澘

```
apps/<app-name>/
鈹溾攢鈹€ App.vue                 鏍圭粍浠� SFC
鈹溾攢鈹€ main.php                鍏ュ彛锛�4 涓� AOT 甯搁噺 + main() 鍑芥暟
鈹溾攢鈹€ project.yml             鏋勫缓閰嶇疆
鈹溾攢鈹€ components/             瀛愮粍浠讹紙鍙�€夛級
鈹�   鈹斺攢鈹€ *.vue
鈹溾攢鈹€ gen/                    鑷�姩鐢熸垚鐨� PHP 缁勪欢锛堢敱 sfc-compiler 浜у嚭锛�
鈹�   鈹溾攢鈹€ AppComponent.php
鈹�   鈹溾攢鈹€ *Component.php
鈹�   鈹斺攢鈹€ ComponentFactory.php
鈹斺攢鈹€ bin/                    鏋勫缓杈撳嚭锛�.exe + .dll锛�
```

---

## 涓夈€佹牳蹇冩灦鏋�

### 3.1 瀹屾暣鏁版嵁娴�

```
鐢ㄦ埛鍦ㄧ獥鍙ｄ腑鎿嶄綔
    鈹�
    鈻�
Platform (Win32Platform::pollEvents)
    鈹�   WM_LBUTTONDOWN 鈫� MouseEvent(action='down', x, y)
    鈹�   WM_MOUSEWHEEL  鈫� MouseEvent(action='wheel', x, y, delta)
    鈹�   WM_KEYDOWN      鈫� KeyboardEvent(action='down', keyCode, char)
    鈻�
Application::handleMouseEvent / handleKeyboardEvent
    鈹�
    鈹溾攢 婊氳疆锛� findScrollContainerAt 鈫� handleScrollWheel 鈫� applyScrollTop 鈫� requestRender
    鈹溾攢 鎷栨嫿锛� hitTestScrollbar 鈫� handleScrollbarDown 鈫� handleScrollbarDrag 鈫� directRender
    鈹斺攢 鐐瑰嚮锛� hitTest 鈫� resolveComponent 鈫� dispatchClick(handler, arg)
         鈹�
         鈻�
    Component 鏂规硶锛堝� deleteItem锛�
         鈹�  淇�敼 $this->todoItems 鈫� $this->markDirty()
         鈻�
    Scheduler::flushMicrotasks
         鈹�  performUpdate 鈫� renderCallback 鈫� Application::requestRender
         鈻�
    Application::render
         鈹�
         鈹溾攢 rebuildVNodeTree
         鈹�   鈹溾攢 rootComponent->getVNodeTree()    // 璋冪敤 render()锛岃繑鍥� VNode 鏍�
         鈹�   鈹溾攢 expandComponentTree()             // 灞曞紑瀛愮粍浠跺崰浣嶈妭鐐�
         鈹�   鈹斺攢 resolveVNodeBindings()            // 灏嗙粍浠� bind 鍊煎啓鍏� VNode
         鈹�
         鈹溾攢 LayoutResolver::resolve
         鈹�   鈹溾攢 瑙ｆ瀽 CSS styles锛坈lass + inline 鍚堝苟锛�
         鈹�   鈹溾攢 鎸� display 妯″紡璁＄畻 x/y/w/h
         鈹�   鈹溾攢 auto-stack 鍨傜洿鎺掑垪瀛愯妭鐐�
         鈹�   鈹斺攢 clamp scrollTop + 瀛愯妭鐐归噸瀹氫綅
         鈹�
         鈹斺攢 VNodeRenderer::render
             鈹溾攢 collectElements锛堟寜 layer 鍒嗙粍锛宻croll/overflow:hidden 鐢熸垚 clip-push/clip-pop锛�
             鈹斺攢 GdiRenderContext::drawElement锛堥€� element 璋冪敤 GDI 鍘熻�锛�
```

### 3.2 鑱岃矗杈圭晫锛圫OLID锛�

```
鈹屸攢鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹�
鈹� 妯″潡              璐熻矗                        涓嶈礋璐�       鈹�
鈹溾攢鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹�
鈹� Component         澹版槑鐘舵€� + 缁戝畾閿�            涓嶅弬涓庡潗鏍� 鈹�
鈹� Application       浜嬩欢璺�敱 + bind 瑙ｆ瀽         涓嶅弬涓庡竷灞€ 鈹�
鈹� LayoutResolver    鎵€鏈夊潗鏍囪�绠�                 涓嶅弬涓庢覆鏌� 鈹�
鈹� VNodeRenderer     鏀堕泦鍏冪礌 + clip 瑁佸垏锛坰croll + overflow:hidden锛� 涓嶄慨鏀瑰潗鏍� 鈹�
鈹� GdiRenderContext  GDI 璋冪敤                    涓嶅弬涓庡竷灞€ 鈹�
鈹斺攢鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹�
```

> **鏍稿績鍘熷垯**锛歏Node 鐨� x/y 鍧愭爣鐢� LayoutResolver 涓€瀹惰�浜嗙畻銆侫pplication 鍙�€氳繃 bind 鏈哄埗锛坄:scroll-top`锛夐棿鎺ュ奖鍝嶅竷灞€锛屼笉鐩存帴鎿嶄綔鍧愭爣銆�

---

## 鍥涖€佸叧閿�被閫熸煡

### 4.1 VNode锛坒ramework/Rendering/VNode.php锛�

**鏈€閲嶈�鐨勫瓧娈�**锛堟寜浣跨敤棰戠巼鎺掑簭锛夛細

```php
// 鈥斺€� 鏍戠粨鏋� 鈥斺€�
string  $type;         // 'div'|'span'|'button'|'input'|'#root'|'#component'|'#text'
?array  $props;        // HTML 灞炴€� + Vue 鎸囦护锛園click, :bind, v-for, :scroll-top 绛夛級
mixed   $children;     // VNode[] | VNode | string | null
?string $key;          // v-for key

// 鈥斺€� 甯冨眬缁撴灉锛堢敱 LayoutResolver 濉�叆锛夆€斺€�
int $x, $y, $w, $h;           // 缁濆�鍧愭爣
array $computedStyle;         // 鍚堝苟鍚庣殑 CSS 灞炴€�
int  $layer;                  // z-order
string $groupId = 'app';      // 浜嬩欢璺�敱 key

// 鈥斺€� 婊氬姩瀹瑰櫒 鈥斺€�
bool $isScrollContainer;
int  $scrollTop;               // 鍨傜洿婊氬姩鍋忕Щ (px)
int  $contentHeight;           // 鍙�粴鍔ㄥ唴瀹规€婚珮搴� (px)
int  $scrollLeft;              // 姘村钩婊氬姩鍋忕Щ (px)
int  $contentWidth;            // 鍙�粴鍔ㄥ唴瀹规€诲�搴� (px)

// 鈥斺€� 缁勪欢鍗犱綅 鈥斺€�
bool $isComponent;
?string $componentClass;
?ReactiveComponent $componentInstance;
?array $componentProps;       // 瀛愮粍浠跺睘鎬ф槧灏�
```

**宸ュ巶鏂规硶**锛�
```php
VNode::h('div', ['style'=>'width:100px;height:50px'], [$child])
VNode::hKey('div', [...], $children, 'item-1')       // 甯� v-for key
VNode::hComponent('MyComponent', [...props], [...bindings])  // 瀛愮粍浠跺崰浣�
```

**甯哥敤杈呭姪鏂规硶**锛歚getProp(name, default)`, `getClass()`, `getInlineStyle()`, `isRoot()`, `isComponent()`

### 4.2 ReactiveComponent锛坒ramework/ReactiveComponent.php锛�

**鍏抽敭鐘舵€�**锛�
```php
bool $dirty;            // true 鈫� 涓嬫� getVNodeTree() 浼氶噸鏂� render()
?VNode $vnodeCache;     // 缂撳瓨鐨勪笂娆℃覆鏌撶粨鏋�
bool $isMounted;        // mount() 涔嬪悗涓� true
```

**鏍稿績娴佺▼**锛�
```php
// 鐘舵€佸彉鏇� 鈫� 瑙﹀彂閲嶆覆鏌撶殑鏍囧噯鏂瑰紡
$this->markDirty();
// 绛変环浜庯細
//   $this->vnodeCache = null;
//   $this->scheduleUpdate();  // 鎶� performUpdate() 鍔犲叆寰�换鍔￠槦鍒�

// 鍦ㄥ井浠诲姟涓� 鈫� performUpdate() 鈫� renderCallback() 鈫� Application::requestRender()
// 鍦ㄤ簨浠跺惊鐜�殑涓嬩竴涓� tick 鈫� Application::render() 鈫� getVNodeTree() 鈫� $this->render()
```

**蹇呴』瀹炵幇鐨勬娊璞℃柟娉�**锛�
```php
abstract public function render(): VNode;                       // 杩斿洖 VNode 鏍�
abstract public function setBindValue(string $key, string $val);  // 鍐欏叆缁戝畾鍊�
abstract public function getBindValue(string $key): string;      // 璇诲彇缁戝畾鍊�
```

**瀛愨啋鐖堕€氫俊**锛�
```php
// 瀛愮粍浠�
$this->emit('itemSelected', ['id' => 5]);
// 鐖剁粍浠�
$this->on($child, 'itemSelected', function($payload) { ... });
```

### 4.3 Application锛坒ramework/Core/Application.php锛�

**閲嶈�鏂规硶閫熸煡**锛�
```php
// 鍏ュ彛
Application::create()->mount($root)->run();

// 缁勪欢娉ㄥ唽
registerComponent(string $groupId, ReactiveComponent $comp)

// 鍛戒腑娴嬭瘯
hitTest(int $x, int $y, VNode $node): ?VNode   // 杩斿洖鏈€涓婂眰鍙�偣鍑� VNode

// 婊氬姩绯荤粺
findScrollContainerAt(int $x, int $y, VNode $node): ?VNode
hitTestScrollbar(int $x, int $y, VNode $node): ?array
applyScrollTop(VNode $node, int $value, bool $persist): void
directRender(VNode $tree): void    // 璺宠繃鏍戦噸寤猴紝浠呴噸鏂� layout + render
```

---

## 浜斻€佷簨浠剁郴缁�

### 5.1 鐐瑰嚮浜嬩欢澶勭悊閾�

```
榧犳爣鎸変笅 鈫� hitTest(x, y) 鍙嶅簭閬嶅巻瀛愯妭鐐�
    鈫� 妫€鏌� @click 灞炴€�
    鈫� resolveComponent(node) 閫氳繃 groupId 鏌ユ壘缁勪欢
    鈫� component->dispatchClick(handler, arg)
    鈫� 缁勪欢鍐� match 鍒嗗彂
    鈫� 鏈�尮閰嶇殑 handler 鈫� parent::dispatchClick 鍐掓场
```

### 5.2 缁勪欢涓�畾涔変簨浠跺�鐞嗗櫒

鍦� `.vue` 鐨� `<script>` 涓�畾涔夋柟娉曪紝SFC 缂栬瘧鍣ㄨ嚜鍔ㄧ敓鎴愬�搴旂殑 `dispatchClick`锛�

```php
// App.vue <script>
public function deleteItem(string $id): void {
    unset($this->todoItems[$id]);
    $this->markDirty();  // SFC 缂栬瘧鍣ㄤ細鑷�姩娉ㄥ叆姝よ�
}

// 缂栬瘧鍣ㄧ敓鎴愮殑 dispatchClick锛�
public function dispatchClick(string $handler, ?string $arg = null): void {
    switch ($handler) {
        case 'deleteItem': $this->deleteItem($arg); break;
        default:
            if ($this->parent !== null) {
                $this->parent->dispatchClick($handler, $arg);
            }
    }
}
```

### 5.3 閿�洏浜嬩欢

褰撳墠浠呮敮鎸佽仛鐒� input 鍏冪礌鐨� @keydown / @keyup / @enter銆�

---

## 鍏�€佹粴鍔ㄧ郴缁�

### 6.1 鑱岃矗鏋舵瀯

```
婊氬姩浜嬩欢 鈫� Application::handleMouseEvent (璺�敱)
         鈫� ScrollManager (鐘舵€佺�鐞� + 閫昏緫)
              鈹溾攢 handleScrollWheel()      婊氳疆
              鈹溾攢 hitTestScrollbar()       鍛戒腑娴嬭瘯锛堝瀭鐩存潯 + 姘村钩鏉★級
              鈹溾攢 handleScrollbarDown()    鎷栨嫿寮€濮�
              鈹溾攢 handleScrollbarDrag()    鎷栨嫿涓�
              鈹溾攢 handleMouseUp()          鎷栨嫿閲婃斁
              鈹溾攢 applyScrollTop()         鍨傜洿婊氬姩
              鈹斺攢 applyScrollLeft()        姘村钩婊氬姩
```

> 婊氬姩鐘舵€侊紙target銆乻tart 鍧愭爣銆乻tart scroll 浣嶇疆銆乮sHorizontal锛夊叏閮ㄥ湪 ScrollManager 涓�€�
> Application 鍙�礋璐ｅ皢浜嬩欢璺�敱缁� ScrollManager锛屼笉鍐嶇洿鎺ユ寔鏈夋粴鍔ㄧ姸鎬併€�

### 6.2 浣垮�鍣ㄥ彲婊氬姩

鍦� `.vue` 妯℃澘涓�細
```html
<!-- 浠呭瀭鐩存粴鍔� -->
<div style="overflow-y:auto;left:10px;top:50px;width:380px;height:400px"
     :scroll-top="scrollTop">
  <template v-for="item in items" :key="item.id">
    <div @click="deleteItem(item.id)">{{ item.text }}</div>
  </template>
</div>

<!-- 妯�悜+绾靛悜婊氬姩锛坥verflow:auto 鍚屾椂鍚�敤涓よ酱锛� -->
<div style="overflow:auto;left:10px;top:50px;width:390px;height:570px"
     :scroll-top="scrollTop"
     :scroll-left="scrollLeft">
  <!-- 瀛愬厓绱犲�搴﹁秴杩囧�鍣ㄥ�搴︽椂鍑虹幇姘村钩婊氬姩鏉� -->
  <div style="left:0;top:0;width:800px;height:36px">瀹藉唴瀹�</div>
</div>
```

缁勪欢涓�細
```php
public string $scrollTop = "0";   // 鍨傜洿婊氬姩浣嶇疆
public string $scrollLeft = "0";  // 姘村钩婊氬姩浣嶇疆锛堜粎妯�悜瀹瑰櫒闇€瑕侊級
```

**妯�悜婊氬姩浜や簰**锛歚Shift + 婊氳疆` 瑙﹀彂妯�悜婊氬姩銆傛按骞虫粴鍔ㄦ潯浣嶄簬瀹瑰櫒搴曢儴 12px 鍖哄煙銆�

### 6.3 婊氬姩浜や簰娴佺▼

```
婊氳疆 鈫� ScrollManager::handleScrollWheel (鍚� Shift 閿��娴� 鈫� 妯�悜)
     鈫� applyScrollTop / applyScrollLeft (persist=true)
     鈫� setBindValue 鈫� markDirty 鈫� requestRender

杞ㄩ亾鐐瑰嚮 鈫� ScrollManager::hitTestScrollbar (杩斿洖 {scrollNode, type, isHorizontal})
       鈫� handleScrollbarDown 鈫� applyScroll*(jumped_value, persist=true)

婊戝潡鎷栨嫿 鈫� hitTestScrollbar 鈫� handleScrollbarDown(type='thumb')
       鈫� handleScrollbarDrag (楂橀�) 鈫� applyScroll*(persist=false) 鈫� directRender
       鈫� 榧犳爣閲婃斁 鈫� applyScroll*(persist=true) 鈫� requestRender
```

### 6.4 鏍稿績鏈哄埗

1. **Bind 鍚屾�**锛歚resolveVNodeBindings` 鍦ㄦ瘡娆� rebuild 鏃跺皢缁勪欢 `scrollTop`/`scrollLeft` 鍊煎啓鍏� `VNode`
2. **甯冨眬鍋忕Щ**锛歀ayoutResolver 鐢� `childOffsetY = node.y - scrollTop` 鍜� `childOffsetX = node.x - scrollLeft` 瀹氫綅瀛愯妭鐐�
3. **鑷�姩 clamp**锛歛uto-stack 鍚庤嫢 `scrollTop > maxScroll` 鎴� `scrollLeft > maxScrollX`锛孡ayoutResolver 鑷�姩淇��骞堕噸瀹氫綅瀛愯妭鐐�
4. **鎷栨嫿浼樺寲**锛氭嫋鎷借繃绋嬩腑璧� `directRender`锛岃烦杩� VNode 鏍戦噸寤�
5. **妯�悜婊氬姩妫€娴�**锛歚overflow-x:auto` / `overflow-x:scroll` 鎴� `overflow:auto` 缁ф壙涓よ酱

### 6.5 澶氭粴鍔ㄥ�鍣ㄦ敞鎰忎簨椤�

- 婊氳疆浜嬩欢鎵�**榧犳爣涓嬫柟鏈€娣辩殑**婊氬姩瀹瑰櫒
- 婊氬姩鏉℃嫋鎷戒竴娆�**鍙�兘鎿嶄綔涓€涓�**瀹瑰櫒
- 鎷栨嫿鐘舵€佺敱 ScrollManager 鎸佹湁锛屾嫋鎷借繃绋嬩腑**涓嶈�**瑙﹀彂鏍戦噸寤�

---

## 涓冦€丄OT 缂栬瘧绾︽潫

### 7.1 绂佹�鐨� PHP 妯″紡

| 妯″紡 | 鍘熷洜 |
|------|------|
| `$obj->$prop` 鍔ㄦ€佸睘鎬� | AOT 鏃犳硶闈欐€佹帹瀵� |
| `$fn()` 闈為棴鍖呰皟鐢� | 瀛楃�涓插嚱鏁板悕涓嶅彲缂栬瘧 |
| `$obj->$method()` 鍔ㄦ€佹柟娉� | 鍚屼笂 |
| 椤跺眰 `require_once` / `include` | 蹇呴』鍦ㄥ嚱鏁�/绫诲唴 |
| `eval()` / `create_function()` | 瀹屽叏涓嶅彲缂栬瘧 |
| `compact()` / `extract()` | 鍔ㄦ€佸彉閲� |

### 7.2 蹇呴』閬靛畧鐨勬ā寮�

| 妯″紡 | 璇存槑 |
|------|------|
| `$x->toObject(ClassName::class)` | AOT 鏄惧紡绫诲瀷鏍囨敞锛�**蹇呴』浣跨敤** |
| `ComponentFactory::create($className)` | 鍏佽�瀛楃�涓茬被鍚嶄綔涓哄伐鍘傚弬鏁� |
| `match` 琛ㄨ揪寮� | 浠� swoole_compiler 鑷�甫鐨� PHP 8.x 鏀�寔 |

### 7.3 鏋勫缓鍓嶆�鏌�

```bash
# 浣跨敤 compiler 鑷�甫鐨� PHP 鍋氳�娉曟�鏌�
D:\swoole_compiler\php.exe -l framework/Core/Application.php

# AOT 闈欐€佹�鏌ワ紙build.bat Step 0.5 鑷�姩杩愯�锛�
D:\swoole_compiler\php.exe framework/aot-checker.php --project apps/list-test --skip direct_cpp_call
```

### 7.4 闂�寘浣跨敤闄愬埗

**闂��**锛歚v-for` 寰�幆鍐呬娇鐢ㄩ棴鍖咃紙濡傛潯浠� class锛夋椂锛孉OT 缂栬瘧浼氫涪澶遍棴鍖呭�閮ㄥ彉閲忕殑浣滅敤鍩燂紝瀵艰嚧 `$ch` 绛夊惊鐜�彉閲忔棤娉曡�闂�€�

**閿欒�绀轰緥**锛�
```php
// 鉂� 閿欒�锛欰OT 涓�棴鍖呮棤娉曡�闂� $ch
$children[] = VNode::h('div', [...], (function() {
    $c = [];
    $c[] = VNode::h('span', [..., 'bind'=>$ch['name']], $ch['name']);
    return $c;
})());
```

**姝ｇ‘鍋氭硶**锛氫笉浣跨敤闂�寘锛岀洿鎺ュ湪寰�幆涓�瀯寤� VNode锛�
```php
// 鉁� 姝ｇ‘锛氬惊鐜�彉閲忕洿鎺ュ湪 foreach 涓�娇鐢�
foreach ($this->items as $item) {
    $children[] = VNode::h('div', [...], $item['name']);
}
```

**鏉′欢娓叉煋鐨勬浛浠ｆ柟妗�**锛�
- 涓嶄娇鐢� `v-if` / `v-else`锛屾敼鐢�**涓や釜鐙�珛鐨� `v-for`** 閬嶅巻涓嶅悓鏁版嵁婧�
- 鍦ㄧ粍浠朵腑鎻愪緵鍒嗙�鐨勬柟娉曡繑鍥炰笉鍚岀被鍨嬬殑鏁版嵁

```php
// 鉁� 鍦� script 涓�彁渚涘垎绂荤殑鏁版嵁鏂规硶
public function getUserMessages(): array { /* 杩囨护 user 绫诲瀷 */ }
public function getSystemMessages(): array { /* 杩囨护 system 绫诲瀷 */ }

// 鉁� 鍦� template 涓�嫭绔嬮亶鍘�
<template v-for="msg in userMessages" :key="'u-' . msg.id">
  <!-- 鐢ㄦ埛娑堟伅 -->
</template>
<template v-for="msg in systemMessages" :key="'s-' . msg.id">
  <!-- 绯荤粺娑堟伅 -->
</template>
```

### 7.5 `use native_types` 涓嬬殑 C2440 绫诲瀷杞�崲閿欒�

**鏍瑰洜**锛氭枃浠跺０鏄庝簡 `use native_types`锛圓OT 妯″紡锛夛紝浣嗕互涓嬫搷浣滃�缁堣繑鍥� `php::Variant` 绫诲瀷锛岃祴鍊肩粰宸插０鏄庝负 `php::Int` 鐨勫彉閲�/灞炴€ф椂锛孉OT 缂栬瘧鍣ㄦ棤娉曢殣寮忚浆鎹�細

| 鎿嶄綔 | 杩斿洖鍊� | 瑙﹀彂鏉′欢 |
|------|--------|---------|
| `$arr['key']` 鏁扮粍鍏冪礌璁块棶 | `php::Variant` | 璧嬬粰 `int` 灞炴€ф垨宸茬被鍨嬪寲鐨勫眬閮ㄥ彉閲� |
| `$arr['key'] ?? default` 鍖呭惈鏁扮粍璁块棶鐨� ?? | `php::Variant` | 鍚屼笂 |
| `max(...)` / `min(...)` | `php::Variant` | 鍚屼笂 |

**閿欒�淇″彿**锛�
```
D:\Px/build/...cc(error): error C2440: '=': cannot convert from 'php::Var' to 'php::Int'
```

**涓夌�鍙樹綋鍙婁慨澶�**锛�

**鍙樹綋 A 鈥� max/min 杩斿洖 Variant**
```php
// 鉂� 閿欒�锛歮ax() 杩斿洖 php::Variant锛岀洰鏍囧彉閲忓凡绫诲瀷鍖栦负 php::Int
$newScrollTop = max(0, min($max, $x));

// 鉁� 姝ｇ‘锛氬�灞傚姞 (int) 杞�瀷
$newScrollTop = (int)max(0, min($max, $x));
```

**鍙樹綋 B 鈥� 鍏� int 瀛楅潰閲忓垵濮嬪寲锛屽悗鏁扮粍璁块棶閲嶆柊璧嬪€�**
```php
// 鉂� 閿欒�锛�$borderColor 琚� =0 鍒濆�鍖栦负 php::Int
//           鍙堣� $style['borderColor'] ?? ... 璧嬪€间负 php::Variant
$borderColor = 0;
if (...) {
    $borderColor = $style['borderColor'] ?? ...;
}

// 鉁� 姝ｇ‘锛氬�灞傚姞 (int) 杞�瀷
$borderColor = 0;
if (...) {
    $borderColor = (int)($style['borderColor'] ?? ...);
}
```

**鍙樹綋 C 鈥� 绫诲睘鎬у０鏄庝负 `int`锛屼粠鏁扮粍璧嬪€�**
```php
public int $primary;  // 澹版槑涓� php::Int

// 鉂� 閿欒�锛�$colors['primary'] ?? 0x1976D2 杩斿洖 php::Variant
$this->primary = $colors['primary'] ?? 0x1976D2;

// 鉁� 姝ｇ‘锛氬�灞傚姞 (int) 杞�瀷
$this->primary = (int)($colors['primary'] ?? 0x1976D2);
```

**鍏ㄥ簱鎵�弿**锛氬凡閫氳繃 Python 鑴氭湰瀵规墍鏈� 11 涓� `use native_types` 鏂囦欢杩涜�鎵�弿锛岀‘璁ゆ棤鏇村�鍗遍櫓妯″紡銆傛秹鍙婃枃浠讹細`ScrollManager.php`(max/min)銆乣VNodeRenderer.php`(鏁扮粍閲嶆柊璧嬪€�)銆乣ColorScheme.php`(绫诲睘鎬ф暟缁勮祴鍊�)銆�

### 7.6 `use native_types` 涓嬫柟娉曞唴鏁扮粍灞炴€ц祴鍊兼棤鏁�

**鏍瑰洜**锛氭枃浠跺０鏄庝簡 `use native_types` 鏃讹紝鍦ㄦ柟娉曪紙濡� `onMount()`銆乣initData()`锛変腑瀵瑰凡澹版槑涓� `public array` / `private array` 鐨勫睘鎬у仛 `$this->prop = [...]` 璧嬪€硷紝AOT 缂栬瘧鍣ㄧ敓鎴愮殑 C++ 浠ｇ爜**涓嶄細鐪熸�灏嗘暟鎹�啓鍏ュ睘鎬�**鈥斺€旇繍琛屾椂璇ュ睘鎬т繚鎸佸垵濮嬬┖鍊� `[]`銆�

**閿欒�淇″彿**锛氭病鏈夌紪璇戦敊璇�紝浣嗚繍琛屾椂灞炴€ф暟鎹�负绌猴紙`foreach` 杩�唬 0 娆★級銆傚父瑙佷簬灏嗘暟鎹�垵濮嬪寲鏀惧叆绫讳技 `initData()` 鏂规硶鐨勮�璁℃ā寮忋€�

**閿欒�绀轰緥**锛�
```php
// 鉂� 閿欒�锛欰OT 缂栬瘧鍚� $this->sidebarItems 淇濇寔绌烘暟缁�
public array $sidebarItems = [];

public function onMount(): void {
    parent::onMount();
    $this->initData();
}

private function initData(): void {
    // 姝よ祴鍊煎湪 AOT 涓嬫棤鏁�
    $this->sidebarItems = [
        ['id' => 's1', 'title' => '瑙嗛�1'],
        ['id' => 's2', 'title' => '瑙嗛�2'],
    ];
}
```

**姝ｇ‘鍋氭硶**锛氭暟缁勬暟鎹�**蹇呴』鍦ㄥ睘鎬у０鏄庡�鍐呰仈鍒濆�鍖�**锛�
```php
// 鉁� 姝ｇ‘锛氬湪澹版槑澶勭洿鎺ヨ祴鍊�
public array $sidebarItems = [
    ['id' => 's1', 'title' => '瑙嗛�1'],
    ['id' => 's2', 'title' => '瑙嗛�2'],
];
```

**褰卞搷鑼冨洿**锛歚public array` 鍜� `private array` 鍧囧彈褰卞搷銆俙string` / `int` 绫诲瀷灞炴€х殑鏂规硶鍐呰祴鍊间笉鍙楁�闄愬埗銆�

**濡備綍妫€娴�**锛歚aot-checker.php` 鏆傛湭瑕嗙洊姝ゆā寮忋€傚彲鎼滅储 `use native_types` 鏂囦欢涓�墍鏈� `$this->xxx = [` 妯″紡锛堟柟娉曞唴鏁扮粍灞炴€ц祴鍊硷級杩涜�浜哄伐瀹℃牳銆�

---

## 鍏�€佹瀯寤烘祦绋�

### 8.1 鍛戒护

```bash
# 鏋勫缓
build.bat list-test

# 鏋勫缓骞惰繍琛�
build.bat list-test --run
```

### 8.2 鍚勬�楠�

```
Step 0:   MSVC 鐜�� (vcvarsall.bat x64)
Step 0.5: AOT 闈欐€佹�鏌� 鈫� 妫€鏌ョ�姝㈡ā寮�
Step 1:   SFC 缂栬瘧锛堢紪璇戞牴缁勪欢 App.vue锛岃嚜鍔� BFS 鍙戠幇骞剁紪璇戞墍鏈夊瓙缁勪欢鍒� gen/*.php锛�
Step 2:   AOT 缂栬瘧 (PHP 鈫� C++ 鈫� link 鈫� .exe)
Step 3:   鎵撳寘 (exe + php8ts.dll + phpx.dll 鈫� bin/)
```

### 8.3 甯歌�澶辫触

| 閿欒� | 瑙ｅ喅 |
|------|------|
| `cl.exe` 鎵句笉鍒� | 浠� Developer Command Prompt for VS 杩愯� |
| `php8embed.lib` 鎵句笉鍒� | 澶嶅埗鍒� `D:\swoole_compiler\` 鏍圭洰褰� |
| AOT Checker 鎶ラ敊 | 妫€鏌ヤ唬鐮佹槸鍚︿娇鐢ㄤ簡绂佹�妯″紡 |
| Step 2 Swoole 缂栬瘧鍣ㄦ姤閿� | 鍏堢敤鎵嬪姩 `php -l` 妫€鏌� PHP 璇�硶 |
| 绯荤粺 `php -l` 鎶ヨ�娉曢敊 | 鐢� `D:\swoole_compiler\php.exe` 鑰岄潪绯荤粺 PATH 涓�殑 PHP |
| 缂栬瘧瀛愮粍浠� .vue 鍚� gen/ 鏈�洿鏂板埌姝ｇ‘浣嶇疆 | 蹇呴』缂栬瘧鏍圭粍浠� App.vue锛屽瓙缁勪欢涓嶄細琚�崟鐙�紪璇戝埌 apps/<name>/gen/ |
| `C2440: cannot convert from 'php::Var' to 'php::Int'` | `use native_types` 鏂囦欢涓�殑 `int` 鍙橀噺浠庢暟缁勮�闂�/max/min 璧嬪€兼椂锛屽�灞傚姞 `(int)` 杞�瀷锛堣�瑙� 7.5锛� |
| `Call to a member function toString() on string` | SFC 缂栬瘧鍣ㄧ敓鎴� `$this->prop->toString()`锛屼絾 PHP CLI 涓� string 鏄�師鐢熺被鍨嬨€傞噸鏂拌繍琛� `php sfc-compiler.php` 閲嶆柊缂栬瘧锛屾柊鐗堢紪璇戝櫒鐢熸垚 `(string)$this->prop` |

### 8.4 澶氭満鍣� vcvarsall 璺�緞閰嶇疆

`build.bat` 鐨� Step 0 闇€瑕佹壘鍒� `vcvarsall.bat` 鏉ュ垵濮嬪寲 MSVC 缂栬瘧鐜��銆備笉鍚屾満鍣ㄤ笂 Visual Studio 瀹夎�璺�緞鍙�兘涓嶅悓锛堝� VS 2017/2019/2022銆丆ommunity/Professional/Enterprise锛夛紝妗嗘灦閲囩敤**涓夌骇浼樺厛绾ц嚜鍔ㄦ�娴�**锛�

| 浼樺厛绾� | 鏉ユ簮 | 璇存槑 |
|--------|------|------|
| 1 | 褰撳墠 PATH | 濡傛灉 `cl.exe` 宸插湪 PATH 涓�紙濡傛墜鍔ㄦ墦寮€ VS Dev Cmd锛夛紝鐩存帴璺宠繃 vcvarsall |
| 2 | `config.yml` | 鍦ㄩ」鐩�牴鐩�綍 `config.yml` 涓�厤缃� `vcvarsall` 閿�紝鏄惧紡鎸囧畾璺�緞 |
| 3 | 鑷�姩鎼滅储 | 閫掑綊鎼滅储 `C:\Program Files\Microsoft Visual Studio\` 涓嬫墍鏈� `vcvarsall.bat`锛屽彇绗�竴涓� |

**閰嶇疆绀轰緥**锛坄config.yml`锛夛細

```yaml
# 瀹剁洰褰曠數鑴� VS 2022 Community
vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat

# 绗旇�鏈� VS 2019 Professional锛堟敞閲婃帀涓嶉渶瑕佺殑琛岋級
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2019\Professional\VC\Auxiliary\Build\vcvarsall.bat
```

> **鎻愮ず**锛氱粷澶у�鏁版儏鍐典笅**鏃犻渶閰嶇疆**锛岃嚜鍔ㄦ悳绱㈠嵆鍙��鐩� VS 2017/2019/2022 鐨勬墍鏈夌増鏈�€傚彧鏈夊湪鑷�姩鎼滅储澶辫触鎴栭渶瑕佹寚瀹氱壒瀹氱増鏈�椂鎵嶉渶瑕佹墜鍔ㄩ厤缃�€�

### 8.5 config.yml 閰嶇疆鏂囦欢

`config.yml` 鏄�瀯寤虹郴缁熺殑鏍稿績閰嶇疆鏂囦欢锛屽繀椤讳綅浜庨」鐩�牴鐩�綍銆傞�娆′娇鐢ㄦ椂鍙�粠妯℃澘澶嶅埗锛�

```bash
cp config.example.yml config.yml
```

**閰嶇疆椤硅�鏄�**锛�

| 閰嶇疆椤� | 璇存槑 | 绀轰緥 |
|--------|------|------|
| `swoole_compiler` | Swoole Compiler 宸ュ叿閾剧洰褰� | `F:\work\swoole_compiler` |
| `vcvarsall` | MSVC 鐜��鍒濆�鍖栬剼鏈�紙鍙�€夛級 | `C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat` |

**閰嶇疆绀轰緥**锛�

```yaml
# Swoole Compiler 璺�緞锛堝繀闇€锛�
swoole_compiler: F:\work\swoole_compiler

# MSVC 璺�緞锛堝彲閫夛紝閫氬父鑷�姩妫€娴嬪嵆鍙�級
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat
```

**甯歌�闂��**锛�

| 閿欒�淇℃伅 | 鍘熷洜 | 瑙ｅ喅 |
|----------|------|------|
| `swoole_compiler path not found in config.yml` | config.yml 涓嶅瓨鍦ㄦ垨璺�緞閿欒� | 浠� `config.example.yml` 澶嶅埗骞朵慨鏀硅矾寰� |
| `swoole_compiler directory not found` | 璺�緞鎸囧悜鐨勭洰褰曚笉瀛樺湪 | 妫€鏌ュ苟淇�� `swoole_compiler` 閰嶇疆 |

---

## 涔濄€佸父瑙佸紑鍙戜换鍔�

### 9.1 鏂板缓搴旂敤

1. 鍦� `apps/` 涓嬪垱寤虹洰褰�
2. 鍒涘缓 `main.php`锛�4 涓�父閲� + main()锛夛細
```php
<?php
use Px\Core\Application;
const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 400;
const WINDOW_HEIGHT = 500;
const WINDOW_TITLE  = 'My App';
function main(): int {
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}
```
3. 鍒涘缓 `App.vue`锛坱emplate + script + style锛�
4. 鍒涘缓 `project.yml`锛�
```yaml
name: my_app
mode: bin
no-console: false
platform: win32
entry: main.php
sources:
  - main.php
  - ./gen
  - ../../framework
  - ../../stub
  - ../../cpp
ignore:
  - ../../framework/compiler
  - ../../framework/aot-checker.php
```

### 9.2 娣诲姞甯� bind 鐨勫睘鎬�

鍦� `.vue` script 涓�０鏄庡睘鎬э細
```php
public string $myValue = "0";
```

鍦ㄦā鏉夸腑浣跨敤锛�
```html
<span :bind="myValue">{{ myValue }}</span>
<div :scroll-top="myValue" style="overflow:auto;...">
```

SFC 缂栬瘧鍣ㄤ細鑷�姩涓� `myValue` 鐢熸垚 `getBindValue` / `setBindValue` 鐨� case 鍒嗘敮銆�

### 9.3 娣诲姞鐐瑰嚮浜嬩欢

妯℃澘涓�細
```html
<button @click="handleAction" click-arg="someId">Click</button>
```

script 涓�細
```php
public function handleAction(string $id): void {
    // 淇�敼鐘舵€�...
    $this->markDirty();  // 缂栬瘧鍣ㄨ嚜鍔ㄦ敞鍏�
}
```

### 9.4 浣跨敤 v-for

鏀�寔 **Vue 3 椋庢牸**锛歚v-for` 鍙�互鍐欏湪 `<template>` 鎴栦换鎰� HTML 鍏冪礌锛坄<div>`銆乣<span>` 绛夛級涓娿€�

**`<template v-for>`** 鈥� 浠呴噸澶嶅瓙鑺傜偣锛屼笉浜х敓棰濆�鍖呰�鍏冪礌锛�

```html
<template v-for="item in items" :key="item.id">
  <div @click="handleClick(item.id)">
    <span>{{ item.text }}</span>
  </div>
</template>
```

**鍏冪礌 v-for**锛圴ue 3 椋庢牸锛� 鈥� 鍏冪礌鏈�韩鍙備笌寰�幆锛�

```html
<div v-for="item in items" :key="item.id" @click="handleClick(item.id)">
  <span>{{ item.text }}</span>
</div>
```

涓ょ�鍐欐硶鍧囦細琚�紪璇戝櫒鎻愬彇涓虹嫭绔嬬殑 render 杈呭姪鏂规硶锛宍{{ item.text }}` 绛夊惊鐜�彉閲忎細琚��
纭��鐞嗕负灞€閮ㄥ彉閲忚€岄潪缁勪欢绾� bind key銆�

### 9.5 浣跨敤 v-if / v-else-if / v-else

鏀�寔 Vue 3 椋庢牸鐨勬潯浠舵覆鏌撻摼锛�

```html
<div v-if="status === 'A'" style="background:#4CAF50">
  <span>Status A</span>
</div>
<div v-else-if="status === 'B'" style="background:#FFC107">
  <span>Status B</span>
</div>
<div v-else style="background:#F44336">
  <span>Status C</span>
</div>
```

**娉ㄦ剰**锛�
- `v-else-if` 鍜� `v-else` 蹇呴』绱ц窡鍦� `v-if` 涔嬪悗锛屼腑闂翠笉鑳芥湁鍏朵粬闈炴潯浠跺厓绱�
- 缂栬瘧鍣ㄤ娇鐢� `ExpressionParser` 瑙ｆ瀽鏉′欢琛ㄨ揪寮忥紝鏀�寔涓夊厓琛ㄨ揪寮忋€佹瘮杈冭繍绠椼€侀€昏緫杩愮畻

### 9.6 浣跨敤 :class 鍔ㄦ€佺被缁戝畾

鏀�寔涓夊厓琛ㄨ揪寮忓姩鎬佺粦瀹� CSS 绫伙細

```html
<div :class="isActive ? 'active' : 'inactive'">
  Content
</div>
```

缂栬瘧涓猴細
```php
['class' => $this->isActive ? 'active' : 'inactive']
```

### 9.7 浣跨敤 v-show 鏉′欢鏄剧ず/闅愯棌

閫氳繃 `visibility:hidden` 鎺у埗鍏冪礌鍙��鎬э細

```html
<div v-show="isVisible" style="background:#2196F3">
  Toggle Me
</div>
```

缂栬瘧涓猴細
```php
['style' => ($this->isVisible) ? '...' : '...;visibility:hidden']
```

### 9.8 浣跨敤瀛愮粍浠�

1. 鍒涘缓瀛愮粍浠� `.vue` 鏂囦欢
2. 鍦ㄧ埗缁勪欢妯℃澘涓�紩鐢�細
```html
<my-component :my-prop="parentValue"></my-component>
```
3. SFC 缂栬瘧鍣ㄨ嚜鍔ㄥ彂鐜般€佺紪璇戙€佺敓鎴愬崰浣� VNode
4. Application 鍦ㄨ繍琛屾椂灞曞紑

### 9.9 閲嶆柊缂栬瘧 SFC锛堜慨鏀� .vue 鍚庯級

淇�敼 `.vue` 鏂囦欢鍚庯紝蹇呴』閲嶆柊缂栬瘧鎵嶈兘鐢熸晥銆傚叧閿��鍒欙細

- **缂栬瘧鏍圭粍浠� App.vue**锛堣€岄潪瀛愮粍浠讹級锛岀紪璇戝櫒浼� BFS 鍙戠幇鎵€鏈夋湁鍙樻洿鐨勫瓙缁勪欢骞惰嚜鍔ㄩ噸鏂扮紪璇�
- 杈撳嚭鐩�綍鐢� .vue 鏂囦欢璺�緞鍐冲畾锛歚dirname($vueFile) + '/gen/'`
  - 缂栬瘧 `apps/<name>/App.vue` 鈫� 杈撳嚭鍒� `apps/<name>/gen/`锛堟�纭�綅缃�級
  - 缂栬瘧 `apps/<name>/components/MyComp.vue` 鈫� 杈撳嚭鍒� `apps/<name>/components/gen/`锛堥敊璇�綅缃�級
- 鍛戒护锛歚php sfc-compiler.php apps/<name>/App.vue`
- **绂佹�鎵嬪姩缂栬緫 `gen/*.php` 鏂囦欢**锛堜細琚�紪璇戝櫒瑕嗙洊锛�

### 9.10 璋冭瘯鎶€宸�

- **妫€鏌� VNode 鏍�**锛氬湪 `render()` 杩斿洖鍓� `var_dump` VNode 缁撴瀯锛堥渶鍦ㄥ紑鍙戠幆澧� PHP 鑰岄潪 AOT 涓�繍琛岋級
- **妫€鏌ュ竷灞€**锛氭煡鐪� `LayoutResolver::resolve()` 杩斿洖鐨� `scrollContainers` 鍒楄〃
- **妫€鏌ユ覆鏌撳厓绱�**锛氬湪 `collectElements` 涓�墦鍗� `$elementsByLayer`
- **formatted 杈撳嚭**锛氬湪 `Application::render()` 涓�皟鐢� `var_dump` 杈撳嚭 activeVNodeTree

### 9.7 AI 鑷�姩鎴�浘娴嬭瘯

鍦ㄨ繘琛� UI 娓叉煋娴嬭瘯鏃讹紝鍙�互浣跨敤 PowerShell 鑴氭湰鑷�姩鎴�浘楠岃瘉甯冨眬鏁堟灉銆�

**鎴�浘鑴氭湰妯℃澘**锛堜繚瀛樺埌 `apps/<app-name>/test_screen.ps1`锛夛細

```powershell
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$exePath = "f:/work/Px/apps/<app-name>/bin/<app-name>.exe"
$screenPath = "f:/work/Px/apps/<app-name>/screenshot.png"

$proc = Start-Process $exePath -PassThru
Start-Sleep 3

Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public class WND {
    [DllImport("user32.dll")]
    public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll")]
    public static extern int GetWindowText(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")]
    public static extern int GetWindowTextLength(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")]
    public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")]
    public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")]
    public static extern bool IsWindowVisible(IntPtr hWnd);
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT {
        public int Left, Top, Right, Bottom;
    }
}
"@

$targetHwnd = [IntPtr]::Zero
$targetPID = $proc.Id

$callback = {
    param([IntPtr]$hWnd, [IntPtr]$lParam)
    $winPid = 0
    [WND]::GetWindowThreadProcessId($hWnd, [ref]$winPid) | Out-Null
    if ($winPid -eq $targetPID) {
        if ([WND]::IsWindowVisible($hWnd)) {
            $len = [WND]::GetWindowTextLength($hWnd)
            if ($len -gt 0) {
                $sb = New-Object System.Text.StringBuilder($len + 1)
                [WND]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
                $title = $sb.ToString()
                if ($title -ne "") {
                    $script:targetHwnd = $hWnd
                    return $false
                }
            }
        }
    }
    return $true
}

[WND]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null

if ($targetHwnd -ne [IntPtr]::Zero) {
    [WND]::ShowWindow($targetHwnd, 1) | Out-Null
    Start-Sleep -Milliseconds 800
    [WND]::SetForegroundWindow($targetHwnd) | Out-Null
    Start-Sleep -Milliseconds 500

    $rect = New-Object WND+RECT
    [WND]::GetWindowRect($targetHwnd, [ref]$rect) | Out-Null

    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    if ($w -gt 10 -and $h -gt 10) {
        $bmp = New-Object System.Drawing.Bitmap($w, $h)
        $graphics = [System.Drawing.Graphics]::FromImage($bmp)
        $graphics.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
        $bmp.Save($screenPath)
        $graphics.Dispose()
        $bmp.Dispose()
        Write-Host "Screenshot saved: ${w}x${h} at ($($rect.Left), $($rect.Top))"
    }
} else {
    Write-Host "Window not found"
}

if (-not $proc.HasExited) {
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
```

**浣跨敤娴佺▼**锛�

1. 淇�敼 `.vue` 鏂囦欢娴嬭瘯甯冨眬
2. 杩愯�鏋勫缓锛�
   ```bash
   cd f:/work/Px
   Remove-Item 'apps/<app-name>/gen/*.php' -Force  # 娓呯悊鏃х敓鎴愭枃浠�
   .\build.bat <app-name>
   ```
3. 杩愯�鎴�浘鑴氭湰锛�
   ```bash
   powershell -ExecutionPolicy Bypass -File "f:/work/Px/apps/<app-name>/test_screen.ps1"
   ```
4. 鏌ョ湅 `screenshot.png` 楠岃瘉娓叉煋缁撴灉

**娉ㄦ剰浜嬮」**锛�

- 鎴�浘鍓嶉渶纭�繚 `gen/` 鐩�綍琚�竻鐞嗭紝鍚﹀垯鍙�兘浣跨敤鏃т唬鐮�
- 姣忎釜搴旂敤鐩�綍搴斿彧淇濈暀涓€涓� `.vue` 鏂囦欢锛堟寜瀛楁瘝椤哄簭缂栬瘧锛�
- 绐楀彛瀹氫綅浣跨敤 `EnumWindows` 鍖归厤杩涚▼ PID锛岄伩鍏嶆崟鑾烽敊璇�獥鍙�

---

## 鍗併€佸凡鐭ラ棶棰樹笌璁捐�鍊哄姟

### 10.1 SOLID 杩濆弽锛欰pplication 鎸佹湁 scrollDragTarget 鈥� 鉁� 宸茶В鍐�

> `ScrollManager` 鏈嶅姟宸叉娊鍙栵紙`framework/Core/ScrollManager.php`锛夈€侫pplication 浠呰礋璐ｄ簨浠惰矾鐢憋紝
> 鎵€鏈夋粴鍔ㄧ姸鎬侊紙drag target銆乨rag start 鍧愭爣銆乨rag start scroll 浣嶇疆锛夊拰閫昏緫锛堟粴杞�€佹嫋鎷姐€乧lamp锛�
> 褰掑睘 ScrollManager銆傛í鍚戞粴鍔ㄧ姸鎬佸悓鏍风敱 ScrollManager 缁熶竴绠＄悊銆�

### 10.2 澶氭粴鍔ㄥ�鍣ㄩ檺鍒�

`scrollDragTarget` 鏄�崟寮曠敤锛屽悓涓€鏃跺埢鍙�兘鎷栨嫿涓€涓�粴鍔ㄦ潯锛堥紶鏍囨搷浣滃ぉ鐒跺�姝わ紝鏆備笉褰卞搷浣跨敤锛夈€備絾濡傛灉鏈�潵澧炲姞閿�洏婊氬姩锛岄渶瑕佹敼涓哄�鍣� ID 绱㈠紩鐨� Map銆�

### 10.3 VNode 鎮�┖寮曠敤椋庨櫓

鎷栨嫿杩囩▼涓�嫢 VNode 鏍戣�閲嶅缓锛堜緥濡傚畾鏃跺櫒瑙﹀彂 markDirty锛夛紝`scrollDragTarget` 鎸囧悜鏃х殑瀵硅薄銆傚綋鍓嶉€氳繃 `directRender` 閬垮厤閲嶅缓锛屼絾闀挎湡闇€鏀逛负 stable identifier銆�

### 10.4 Bind 鍊煎悓姝ュ欢杩�

LayoutResolver clamp 鍚庯紝缁勪欢鐨� bind 鍊硷紙濡� scrollTop锛変繚鎸佹棫鍊笺€備笅涓€娆� render 鏃跺厛鎭㈠�鏃у€笺€佸啀琚� LayoutResolver 閲嶆柊 clamp鈥斺€旀瘡甯т竴娆�"閿欒�鈫掍慨姝�"寰�幆銆傞渶瑕� `setBindValueSilent` 鏂规硶銆�

### 10.5 鏈�疄鐜扮殑鍔熻兘

- 閿�洏婊氬姩锛圥gUp/PgDn/Home/End/Arrow锛�
- 缂栫▼寮忔粴鍔ㄥ埌鎸囧畾 item
- 绐楀彛 resize 鏃剁殑鍔ㄦ€侀噸甯冨眬锛堝綋鍓嶉渶瑕佹墜鍔ㄨЕ鍙戞覆鏌擄級
- 鏂囧瓧杈撳叆鏃� IME 鏀�寔

---

## 鍗佷竴銆佺紪鐮佺害瀹�

### 11.1 PHP 鐗堟湰瑕佹眰

- 婧愭枃浠讹細PHP 8.0+锛堜娇鐢� `match` 琛ㄨ揪寮忥級
- AOT 缂栬瘧锛歴woole_compiler 鍐呯疆 PHP 8.x
- **绯荤粺 PATH 涓�殑 PHP 鍙�兘鏄� 7.4锛屼粎鐢ㄤ簬寮€鍙戣皟璇曪紝涓嶈兘鐢ㄤ簬缂栬瘧**

### 11.2 浠ｇ爜椋庢牸

- 浣跨敤 4 绌烘牸缂╄繘
- 绫诲睘鎬т娇鐢� `protected` 鎴� `private`锛圓OT 鍙嬪ソ锛�
- `public` 灞炴€х敤浜庣粍浠剁姸鎬侊紙鐢� SFC 缂栬瘧鍣ㄧ敓鎴愶級
- 鏂规硶鍚� camelCase
- VNode factory 缁熶竴浣跨敤 `VNode::h()` 鍜� `VNode::hComponent()`

### 11.3 VNode 鏍戣�鑼�

- 姣忎釜缁勪欢鐨� `render()` 杩斿洖浠� `#root` 涓烘牴鐨� VNode 鏍�
- `#root` 鐨� style 璁剧疆 `width` 鍜� `height`
- `#component` 鏄�繍琛屾椂灞曞紑鐨勫崰浣嶈妭鐐癸紝涓嶄骇鐢熸覆鏌�
- `#text` 鐢ㄤ簬绾�枃鏈�妭鐐�
- children 鍙�互鏄� `null`銆乣string`銆乣VNode`銆乣VNode[]`

---

## 鍗佷簩銆佹祴璇�

Px 妗嗘灦浣跨敤**涓夊眰娴嬭瘯绛栫暐**锛�
1. **鍗曞厓娴嬭瘯**锛圥HP锛夆€� dispatchClick 妯℃嫙鐐瑰嚮 + 缁勪欢鏍戣�涔夐獙璇�
2. **鐘舵€佸揩鐓ф祴璇�**锛圥HP锛夆€� 鏂囧瓧鐗�"鎴�浘"锛屽皢缁勪欢鐘舵€佸簭鍒楀寲涓哄彲璇绘枃鏈�
3. **鎴�浘娴嬭瘯**锛圥owerShell锛夆€� 鍚�姩鐪熷疄 exe 鎶撳彇绐楀彛鎴�浘锛岀敤浜庤�瑙夊洖褰�

### 娴嬭瘯璁捐�鍘熷垯

| 鍘熷垯 | 璇存槑 |
|------|------|
| **涓嶄緷璧栧�閮ㄦ湇鍔�** | 鎵€鏈夋祴璇曞湪鍐呭瓨涓�繍琛岋紝鏃犳枃浠�/缃戠粶/鏁版嵁搴撲緷璧� |
| **dispatchClick 椹卞姩** | 妯℃嫙鐢ㄦ埛鐐瑰嚮锛岀洿鎺ヨ皟鐢ㄧ粍浠� handler 鏂规硶 |
| **鐘舵€佹柇瑷€ + 蹇�収** | 鏃㈡牎楠屽叿浣撳睘鎬у€硷紝涔� dump 瀹屾暣鐘舵€佺敤浜庤皟璇� |
| **缁勪欢鏍戣�涔夊�鏍� Vue 3** | 娴嬭瘯 parent 閾俱€佷簨浠跺啋娉°€乂Node 缂撳瓨銆乸atchComponentTree |
| **AOT polyfill** | bootstrap.php 鎻愪緵 `toObject()`銆乣any()` 绛� AOT 鍑芥暟 polyfill |

### 杩愯�娴嬭瘯

```bash
# 杩愯�鍏ㄩ儴鍗曞厓娴嬭瘯锛堟帹鑽愶級
D:\swoole_compiler\php.exe tests/run_all_tests.php

# 杩愯�鍗曚釜娴嬭瘯鏂囦欢
D:\swoole_compiler\php.exe tests/unit/CalculatorAppTest.php
D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php
D:\swoole_compiler\php.exe tests/unit/ReactiveComponentTest.php
D:\swoole_compiler\php.exe tests/unit/HitTestTest.php
D:\swoole_compiler\php.exe tests/unit/LayoutResolverTest.php
D:\swoole_compiler\php.exe tests/unit/VNodeRendererTest.php
D:\swoole_compiler\php.exe tests/unit/SfcCompilerPartsTest.php
D:\swoole_compiler\php.exe tests/unit/SfcCompilerVIfTest.php
D:\swoole_compiler\php.exe tests/unit/CssMappingsBorderTest.php
D:\swoole_compiler\php.exe tests/unit/PlatformTest.php

# 杩愯�瀹屾暣娓叉煋绠￠亾娴嬭瘯锛堝揩鐓у樊寮傚垎鏋愶級
D:\swoole_compiler\php.exe tests/unit/RenderingPipelineTest.php

# 杩愯�鍐呭瓨鍘嬪姏娴嬭瘯锛堝�甯х疮绉��娴嬶級
D:\swoole_compiler\php.exe tests/unit/MemoryStressTest.php
```

### 娴嬭瘯鏂囦欢

| 鏂囦欢 | 瑕嗙洊鑼冨洿 | 鐢ㄤ緥鏁� |
|------|---------|--------|
| `CalculatorAppTest.php` | 璁＄畻鍣ㄥ叏閮� 18 绫绘搷浣� + 鐘舵€佸揩鐓� + 杈圭晫鎯呭喌 | 107 |
| `ComponentTreeTest.php` | 缁勪欢 parent 閾俱€佷簨浠跺啋娉°€佸疄渚嬬嫭绔嬨€佺敓鍛藉懆鏈熴€乂Node 缂撳瓨銆乭Component 宸ュ巶銆乸atchComponentTree銆佺粍浠跺畾浣嶄繚鐣� | 26 |
| `ReactiveComponentTest.php` | dirty 鏍囪�銆乂Node 缂撳瓨銆佺粍浠舵洿鏂� | 9 |
| `HitTestTest.php` | 鍛戒腑娴嬭瘯銆佷簨浠惰矾鐢� | 10 |
| `LayoutResolverTest.php` | block/flex/grid/scroll 甯冨眬 | 14 |
| `VNodeRendererTest.php` | 鍏冪礌鏀堕泦銆乴ayer 鍒嗙粍銆乧lip锛坰croll + overflow:hidden锛夈€乥utton 杈规�娓叉煋銆乺ender 瀹屾暣娴佺▼ | 20 |
| `SfcCompilerPartsTest.php` | 缂栬瘧鍣� parts 鍏冩暟鎹�細collectVNodeBindKeys 鎻愬彇銆乬enerateVNodeExpr 浠ｇ爜鐢熸垚 | 8 |
| `SfcCompilerVIfTest.php` | v-if 缂栬瘧鏈熶紭鍖栵紙鍚�繛缁�浉鍚屾潯浠跺悎骞讹級 | 9 |
| `CssMappingsBorderTest.php` | border 绠€鍐�/鐙�珛灞炴€цВ鏋愩€乸arseInlineStyle/parseStyleBlock 杈规�澶勭悊銆乭exToBgr/borderColor 杈呭姪鍑芥暟 | 14 |
| `PlatformTest.php` | Platform 鎺ュ彛 SOLID/DIP 鍚堣� | 10 |
| `MemoryStressTest.php` | 鍐呭瓨澧為暱妫€娴嬶紙9 妯″潡 28+ 鍦烘櫙锛� | 28+ |
| `RenderingPipelineTest.php` | 瀹屾暣娓叉煋绠￠亾蹇�収宸�紓鍒嗘瀽锛�100 娆″惊鐜�偣鍑� + 5 绫昏�鍒欐牎楠� + 寮傚父瀛樻。锛� | 5 |
| `ListTestPipelineTest.php` | list-test 娓叉煋绠￠亾娴嬭瘯锛�30 娆＄偣鍑� + 澧為暱瑙勫垯 + clip 鏈夋晥鎬� + 婊氬姩鎷栧姩锛� | 8 |
| `GdiRenderContextTest.php` | GDI 娓叉煋涓婁笅鏂囩洿鎺ユ祴璇曪紙clip 鏍� + drawText 鎴�柇 + 鍙傛暟瀹堝崼锛� | 15 |

### CalculatorAppTest 娴嬭瘯娓呭崟

瑕嗙洊浠ヤ笅 18 绫诲満鏅�紙107 涓�祴璇曠敤渚嬶級锛�

| # | 绫诲埆 | 鐢ㄤ緥鏁� | 璇存槑 |
|---|------|--------|------|
| 1 | Digit Input | 7 | 鍒濆�鏄剧ず銆佹暟瀛楄緭鍏ャ€佸幓闄ゅ墠瀵奸浂銆佽繍绠楃�鍚庢柊杈撳叆 |
| 2 | Decimal Input | 6 | 灏忔暟鐐硅緭鍏ャ€侀槻閲嶅�銆佽繍绠楃�鍚庢柊杈撳叆銆�15 浣嶉檺鍒讹紙2 涓�級 |
| 3 | Clear/Reset | 2 | C 娓呴櫎杈撳叆銆丄C 瀹屽叏閲嶇疆 |
| 4 | Backspace | 4 | 鍒犻櫎鏈�綅銆佸綊闆躲€乶ewInput 淇濇姢銆佸垹闄ゅ皬鏁扮偣 |
| 5 | Toggle Sign | 3 | 姝ｈ礋鍒囨崲銆侀浂鍊间繚鎶� |
| 6 | Percentage | 2 | 50%鈫�0.5銆�200%鈫�2 |
| 7 | Basic Arithmetic | 6 | 卤脳梅銆侀櫎浠ラ浂 Error銆佺┖鎿嶄綔绗� |
| 8 | Operator Chaining | 3 | 閾惧紡璁＄畻銆佽繍绠楃�瑕嗙洊銆佹贩鍚堣繍绠� |
| 9 | Scientific Functions | 14 | sin/cos/tan/log/ln/x虏/x鲁/鈭�/inv/蟺/e + Error 鍒嗘敮 |
| 10 | Memory Functions | 6 | MS/MR/MC/M+/M鈭�/绌鸿�蹇� |
| 11 | Parentheses | 4 | openParen/closeParen 鏄剧ず |
| 12 | History | 5 | 鍘嗗彶璁板綍鐢熸垚銆佸垏鎹㈤潰鏉裤€佹竻闄ゃ€佸姞杞� |
| 13 | Error Recovery | 3 | Error 鍚庢暟瀛�/C/= 鎭㈠� |
| 14-16 | Routing | 26 | ScientificPad/BasicPad/HistoryPanel 鍐掓场璺�敱 |
| 17 | State Snapshot | 3 | 瑙嗚�鍖栫姸鎬佽窡韪�細瀹屾暣浼氳瘽銆丒rror鈫掓仮澶嶃€佹嫭鍙疯〃杈惧紡 |
| 18 | Edge Cases | 13 | 瓒呭ぇ鏁板瓧銆佽繍绠楃�閾俱€侀噸澶嶇瓑鍙枫€佸甫绗﹀彿杩愮畻銆佽繛缁�竻闄ゃ€佸�杞�帇鍔涙祴璇曠瓑 |

### ComponentTreeTest 娴嬭瘯娓呭崟

瑕嗙洊 8 绫� Vue 3 缁勪欢璇�箟锛�26 涓�祴璇曠敤渚嬶級锛�

| # | 绫诲埆 | 璇存槑 |
|---|------|------|
| 1 | Parent Chain | setParent/getParent銆乤ddChild 鍙屽悜缁戝畾銆佸�绔嬬粍浠� |
| 2 | Event Bubbling | dispatchClick 娌� parent 鍐掓场銆乻top 娑堣垂銆乶ull parent銆乨ispatchKey |
| 3 | Instance Identity | 鍚岀被鍨嬩笉鍚屽疄渚嬨€佸敮涓€ ID |
| 4 | Lifecycle | mount/unmount銆侀噸澶� mount |
| 5 | VNode Caching | 棣栨� render()銆佺紦瀛樺�鐢ㄣ€乨irty 閲嶅缓銆乵arkDirty 娓呯紦瀛� |
| 6 | VNode Factory | hComponent 鍗犱綅銆乧omponentProps 鏄犲皠銆乬roupId 閫掑綊 |
| 7 | Patch Component Tree | 鏅�€氳妭鐐� groupId銆�#component 灞曞紑銆佸疄渚嬪�鐢�紙鍚� class+鍚屼綅缃�級 |
| 8 | Component Positioning | matchComponentNode 瀹炰緥閲嶇敤鍚� transferComponentPositioning 淇濈暀瀹氫綅 |

### 鎴�浘娴嬭瘯

鎻愪緵 PowerShell 鑴氭湰鐢ㄤ簬瑙嗚�鍥炲綊锛�

```powershell
# 鐩存帴鎴�浘锛堜娇鐢ㄥ凡鏈� exe锛�
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1

# 鍏堟瀯寤哄啀鎴�浘
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1 -BuildFirst $true
```

鎴�浘淇濆瓨鍦� `tests/screenshot/output/<timestamp>/`锛屽苟鑷�姩鐢熸垚 HTML 鎶ュ憡銆�

### 娴嬭瘯鏈€浣冲疄璺碉紙缁忛獙鎬荤粨锛�

1. **dispatchClick 鏄��閫夋祴璇曟柟寮�** 鈥� 鐩存帴璋冪敤缁勪欢 handler锛屼笉渚濊禆甯冨眬鍧愭爣鍜屾覆鏌撶�閬擄紝閫熷害蹇�€佺粨鏋滅‘瀹�
2. **娴嬭瘯 helper 鍑芥暟鍖�** 鈥� `createApp()`銆乣runCalculation()`銆乣assertDisplay()`銆乣captureState()` 绛� helper 鎻愰珮鍙��鎬у拰鍙�淮鎶ゆ€�
3. **閬垮厤杩囧害妯℃嫙** 鈥� 娴嬭瘯鐪熷疄缁勪欢琛屼负姣� mock 鏇存湁浠峰€笺€傚彧鍦ㄩ渶瑕侀殧绂绘椂鎵嶇敤 test double
4. **鐘舵€佸揩鐓� vs 鍏蜂綋鏂�█** 鈥� 鍏抽敭璺�緞鐢ㄥ叿浣撴柇瑷€锛坄assertDisplay('42')`锛夛紝璋冭瘯鐢ㄧ姸鎬佸揩鐓э紙`captureState()`锛�
5. **Application 绉佹湁鏂规硶閫氳繃鍙嶅皠娴嬭瘯** 鈥� `newInstanceWithoutApp()` + `ReflectionMethod` 璁块棶 private 鏂规硶
6. **鍏堜慨澶嶆祴璇曞啀鎻愪氦** 鈥� 澶辫触鐨勬祴璇曟瘮娌℃湁娴嬭瘯鏇寸碂銆傛瘡娆′慨鏀瑰悗杩愯�鍏ㄩ儴娴嬭瘯纭�繚鍥炲綊
7. **缁勪欢鏍戞祴璇曢獙璇佹�鏋惰�涔�** 鈥� ComponentTreeTest 楠岃瘉妗嗘灦灞傞潰鐨� Vue 3 璇�箟瀵归綈锛屼笉渚濊禆鍏蜂綋搴旂敤
8. **Mock 娓叉煋涓婁笅鏂囨毚闇� GDI 涓嶅彲娴嬫紡娲�** 鈥� `_MockRenderContext` 鍙��褰� `drawElement()` 璋冪敤锛屼笉鎵ц�鐪熷疄 GDI銆侭ug 鍙戠敓鍦� GDI 瀹炵幇灞傦紙clip 杈圭晫缁樺埗绱�Н鎹熷潖 HDC 鐘舵€侊級锛岀函鍏冪礌灞� Mock 鏃犳硶鎹曡幏銆傝ˉ鍋跨瓥鐣ワ細
   - Mock 闇€妯℃嫙 clip 鏍堣拷韪� + 鏂囨湰鎴�柇锛坄applyClipTruncation()` 涓� `GdiRenderContext::drawText()` 閫昏緫涓€鑷达級
   - 娴佹按绾挎祴璇曞繀椤诲寘鍚� clip 婧㈠嚭瑙勫垯锛圧ule E锛氫换浣曟孩鍑� 鈮�1px 鍗冲憡璀︼級
   - GDI 灞傝�涓哄繀椤婚€氳繃 `GdiRenderContextTest.php` 鐩存帴楠岃瘉锛坰tub GDI C++ 鍑芥暟璁板綍璋冪敤鍙傛暟锛�
9. **clip-aware drawText 鏄�墍鏈� text 杈撳嚭璺�緞鐨勫繀閫夊畧鍗�** 鈥� 浠讳綍鏂板�鐨� GDI text 璋冪敤鐐归兘蹇呴』缁忚繃 `drawText()`锛堝惈 clip 鎴�柇锛夛紝绂佹�鐩存帴璋� `vue_draw_text()`
10. **鏂板簲鐢ㄦ帴鍏ユ椂蹇呴』娣诲姞瀵瑰簲鐨勬祦姘寸嚎娴嬭瘯** 鈥� 鑷冲皯鍖呭惈锛歂 娆″惊鐜�偣鍑荤ǔ瀹氭€ф祴璇� + A/B/C 瑙勫垯锛堜笉鍙�/鏉′欢/绾︽潫锛� + clip 鏈夋晥鎬ц�鍒�
11. **绮椾綋鏂囨湰瀛楃�瀹藉害鏄�父瑙勪綋鐨� 1.35 鍊�** 鈥� `drawText()` 鎴�柇閫昏緫蹇呴』鍖哄垎 `$bold` 鍙傛暟銆傜矖浣� 36px 瀹為檯瀹藉害 ~28px/char锛岃€� `fontSize * 0.6` 鍙�粰鍑� 21px/char銆傛湭鍖哄垎绮椾綋浼氬�鑷存埅鏂�悗浠嶇劧婧㈠嚭
12. **娴嬭瘯蹇呴』瑕嗙洊瀹屾暣鐨勭敤鎴锋搷浣滈摼** 鈥� 浠呮祴璇�"涓€鐩存寜 1"涓嶅�锛屽繀椤诲寘鍚�"澶ч噺鎿嶄綔 鈫� 娓呴櫎/閲嶇疆 鈫� 楠岃瘉 UI 瀹屾暣鎬�"鐨勭�鍒扮�鍦烘櫙銆傛瘡涓�柊绠￠亾娴嬭瘯閮藉簲鍖呭惈 clear-after-corruption 楠岃瘉
13. **鎸夐挳鏍囩�鎻愬彇娴嬭瘯** 鈥� 浣跨敤 `<button><span :bind="label">{{ label }}</span></button>` 妯℃澘鏃讹紝`makeButtonElement()` 蹇呴』鎻愬彇鍒版爣绛俱€傜�閬撴祴璇曚腑 `ltCheckButtonLabel()` 搴旀柇瑷€ label 闈炵┖锛屼笉鍐嶆爣璁颁负"known bug"
14. **婊氬姩鎷栧姩娴嬭瘯蹇呴』楠岃瘉 auto-stacked 浣嶇疆** 鈥� 浠呮祴璇�"娣诲姞 item 鍚庡竷灞€姝ｇ‘"涓嶅�銆傚繀椤绘ā鎷熸粴鍔ㄦ嫋鍔�紙鐩存帴璁剧疆 scrollTop + directRender锛夛紝楠岃瘉 auto-stacked items 鐨� y 鍧愭爣淇濇寔涓ユ牸閫掑�涓嶆姌鍙犮€傛磥鍑€璺�緞涓� `style` 鏃犳樉寮� `top` 鐨勮妭鐐逛笉搴旇�閲嶇畻 y

---

## 鍗佷笁銆佷慨鏀规�鏋朵唬鐮佹椂鐨勬�鏌ユ竻鍗�

1. **PHP 璇�硶**锛歚D:\swoole_compiler\php.exe -l <file>`
2. **AOT 鍏煎�**锛氭棤 `->$var`銆佹棤鍔ㄦ€佽皟鐢�
3. **甯冨眬鑱岃矗**锛歀ayoutResolver 绠′綅缃�紝VNodeRenderer 绠¤�鍒囷紝浜掍笉瓒婄晫
4. **璐熷�楂橀槻寰�**锛歀ayoutResolver 涓�墍鏈� `$node->w`/`$node->h` 璧嬪€肩敤 `max(0, (int)$val)`
5. **GDI 璋冪敤淇濇姢**锛欸diRenderContext 涓�墍鏈� GDI 璋冪敤鍓嶆�鏌� `$w > 0 && $h > 0`
6. **drawText clip 鎴�柇**锛氭墍鏈� text 缁樺埗蹇呴』缁忚繃 `drawText()`锛堝惈 `clipStack` 杩借釜 + 绮椾綋鎰熺煡婧㈠嚭鎴�柇锛夛紝绂佹�鐩存帴璋� `vue_draw_text()`銆傛柊澧� text 杈撳嚭璺�緞鏃跺繀椤诲悓姝ユ坊鍔犳埅鏂�€昏緫銆傛埅鏂�叕寮忥細`charWidth = (int)(fontSize * 0.6 * ($bold ? 1.35 : 1.0))`锛屽苟淇濈暀 4px 瀹夊叏浣欓噺
7. **clip 鏍堝钩琛�**锛歝lip-push/clip-pop 蹇呴』鎴愬�鍑虹幇锛屾瘡甯х粨鏉熸椂 clip 鏍堝簲涓虹┖銆俙GdiRenderContextTest` 涓�凡鏈� `clip stack push and pop balanced` 娴嬭瘯
8. **Mock clip 杩借釜**锛氫慨鏀� `_MockRenderContext`/`_LTMockRenderContext` 鏃跺繀椤诲悓姝ユ坊鍔� clip 鏍堣拷韪� + `applyClipTruncation()`锛堝惈绮椾綋鍥犲瓙鍜� 4px 瀹夊叏浣欓噺锛夛紝纭�繚 mock 鐨勫彲瑙佽�涓烘帴杩戠湡瀹� GDI
9. **overflow:hidden 瑁佸垏**锛氶渶瑕佽�鍒囧瓙鍐呭�鐨勫�鍣ㄥ繀椤昏�缃� `overflow:hidden`锛孷NodeRenderer 浼氫负鍏剁敓鎴� clip-push/clip-pop
10. **鏁板€艰緭鍏ラ檺鍒�**锛氭墍鏈夋暟鍊艰緭鍏ユ柟娉曪紙inputDigit銆乮nputDecimal 绛夛級蹇呴』鏈� 15 瀛楃�闀垮害闄愬埗
11. **Bind 鍚屾�**锛氭柊澧� bind 灞炴€у悗鍦ㄧ粍浠朵腑澹版槑 `public string`锛岀紪璇戝櫒鑷�姩鐢熸垚 get/set
12. **浜嬩欢鍐掓场**锛氬瓙缁勪欢 dispatchClick 鐨� default 鍒嗘敮璋冪敤 `parent::dispatchClick`
13. **SFC 缂栬瘧**锛氫粎缂栬瘧鏍圭粍浠� App.vue锛屼笉鐩存帴缂栬瘧瀛愮粍浠� .vue锛涗笉鎵嬪姩缂栬緫 gen/*.php
14. **鏋勫缓楠岃瘉**锛歚build.bat <app-name>` 鍏ㄦ祦绋嬮€氳繃
15. **娴嬭瘯瀹屾暣闂�幆**锛氭柊澧炵�閬撴祴璇曞繀椤昏�鐩栧畬鏁寸殑鐢ㄦ埛鎿嶄綔閾撅紙涓嶉檺浜庝竴鐩存寜鍚屼竴鎸夐挳锛夛紝鍖呮嫭锛氬ぇ閲忔搷浣滃悗 鈫� 娓呴櫎/閲嶇疆 鈫� 楠岃瘉鎵€鏈� UI 鍏冪礌瀹屾暣鐨勭�鍒扮�鍦烘櫙
16. **鎸夐挳鏍囩�鎻愬彇**锛歚makeButtonElement()` 蹇呴』閬嶅巻瀛� RenderNode 鎻愬彇鏍囩�锛坄<button><span :bind="x">{{ x }}</span></button>`锛夛紝浠呮�鏌� `node->content`(string) 鍜� `props[':bind']` 涓嶅�锛岃繕瑕佹�鏌ュ瓙鑺傜偣鐨� content 鍜� bind 寮曠敤
17. **LayoutResolver 娲佸噣璺�緞淇濈暀 auto-stack 浣嶇疆**锛氭磥鍑€璺�緞锛坄layoutDirty=false`锛変腑锛屽彧鏈夋樉寮� `top`/`left` 瀹氫綅鐨勮妭鐐规墠閲嶇畻 x/y銆俛uto-stacked 瀛愯妭鐐瑰簲淇濈暀鑴忚矾寰勮�瀹氱殑浣嶇疆锛屼粎鐢卞揩閫熸粴鍔ㄨ矾寰勶紙`shiftChildrenY`锛夊钩绉汇€備慨鏀� `resolveNode()` 涓� `$node->x = ($style['left'] ?? 0) + $parentX` 杩欑被鏃犳潯浠惰祴鍊兼椂蹇呴』鏀圭敤 `array_key_exists` 淇濇姢

---

## 鍗佸洓銆佹柊澧� CSS 甯冨眬灞炴€э紙LayoutResolver v2锛�

浠ヤ笅 CSS 甯冨眬灞炴€у凡鍦� LayoutResolver 涓�疄鐜版敮鎸侊細

### 灏哄�绾︽潫
| 灞炴€� | 璇存槑 | 榛樿�鍊� |
|------|------|--------|
| `min-width` | 鏈€灏忓�搴� (px) | 0 |
| `max-width` | 鏈€澶у�搴� (px) | 0 |
| `min-height` | 鏈€灏忛珮搴� (px) | 0 |
| `max-height` | 鏈€澶ч珮搴� (px) | 0 |

CSS 瑙勮寖锛氬綋 `min > max` 鏃讹紝`max` 琚�拷鐣ャ€�

### 鐧惧垎姣斿昂瀵�
| 灞炴€� | 璇存槑 |
|------|------|
| `width: 50%` | 鐩稿�浜庣埗瀹瑰櫒 content width |
| `height: 50%` | 鐩稿�浜庣埗瀹瑰櫒 content height |

鐧惧垎姣斿湪灏哄�瑙ｆ瀽**涔嬪悗**銆乵in/max 绾︽潫**涔嬪墠**搴旂敤銆傜櫨鍒嗘瘮涔熷湪 flex 鍜� grid 瀹瑰櫒涓婄敓鏁堛€�

### position:relative
- 鍦� auto-stack 涓�紝`position:relative` 鐨勫瓙鑺傜偣**涓嶇�姝�** auto-stack
- `top` 鍦� auto-stacked 浣嶇疆鍩虹�涓婂仛棰濆�鍋忕Щ锛屼笉褰卞搷鍏勫紵鑺傜偣瀹氫綅
- `left` 閫氳繃 resolveBlockLayout 鐨� relative 璺�緞姝ｇ‘澶勭悊

### Flex 鎵╁睍
| 灞炴€� | 璇存槑 | 榛樿�鍊� |
|------|------|--------|
| `order` | 鎺掑垪椤哄簭锛堝啋娉℃帓搴忥紝绋冲畾锛� | 0 |
| `flex-basis` | 鍒濆�涓昏酱灏哄� (`auto` 鍥為€€鍒� `width`/`height`) | `auto` |
| `flex-shrink` | 鏀剁缉鍥犲瓙 | 1 |
| `align-self` | 鍗曢」浜ゅ弶杞村�榻� (`auto`/`flex-start`/`flex-end`/`center`/`stretch`) | `auto` |

### Flex-shrink 绠楁硶
```
overflow = totalMain - containerMain  (褰� overflow > 0)
totalShrinkWeight = 危(item.mainSize 脳 item.shrink)
item.mainSize -= overflow 脳 (item.mainSize 脳 item.shrink) / totalShrinkWeight
min-width/min-height 绾︽潫鍦ㄦ敹缂╁悗搴旂敤
```

### Grid 鎵╁睍
| 灞炴€� | 璇存槑 | 榛樿�鍊� |
|------|------|--------|
| `align-self` | 鍨傜洿鏂瑰悜瀵归綈 (`stretch`/`center`/`start`/`end`) | `stretch`(auto) |
| `justify-self` | 姘村钩鏂瑰悜瀵归綈 (`stretch`/`center`/`start`/`end`) | `stretch`(auto) |

### 鍐呰仈鏍峰紡鐧惧垎鏁伴�妫€娴�
`CssMappings::parseInlineStyle()` 鍦ㄨВ鏋愭椂鑷�姩妫€娴� `width`銆乣height`銆乣min-width`銆乣max-width`銆乣min-height`銆乣max-height` 鐨勭櫨鍒嗘瘮鍊硷紝瀛樺叆 `*Percent` 閿�紙濡� `widthPercent`锛夛紝LayoutResolver 鍦ㄧ埗瀹瑰櫒灏哄�宸茬煡鏃舵嵁姝よВ鏋愬疄闄呭儚绱犲€笺€�

---

## 十五、渲染后端切换（GDI / Skia）

Px 框架支持两套渲染后端，**默认 GDI 零回归**，通过 `const APP_RENDERER` 切换 Skia 路径。

### 15.1 默认行为

未声明 `APP_RENDERER` 常量时，`framework/Platform/Win32Platform.php` 走 `GdiRenderContext`（368 行，9 个 `vue_*` 原语）。现有 7 个应用（calculator-ng / design-guide / list-test / multi-scroll / aot-property-test / aot-syntax-test / video-platform）**全部不需改动**。

### 15.2 启用 Skia 模式

在 `apps/<app-name>/main.php` 顶部追加一行：

```php
<?php
const APP_PLATFORM  = 'win32';
const APP_RENDERER  = 'skia';   // <-- 新增：启用 Skia 路径
const WINDOW_WIDTH  = 400;
const WINDOW_HEIGHT = 300;
```

无需修改 `App.vue` / `components/*.vue` / `project.yml`。`Win32Platform::init()` 自动根据 `APP_RENDERER` 选择 `SkiaRenderContext` 或 `GdiRenderContext`。

### 15.3 验证 Skia 路径已激活

启动应用时观察 stderr / 错误日志，应出现：

```
PHP Notice:  SKIA PATH ACTIVE in framework/Rendering/SkiaRenderContext.php
```

这是 R6 风险对策（"看起来工作但实际走 GDI" 的误判防护）。如未出现此 notice，说明 `APP_RENDERER` 常量未传递到 `Win32Platform::init()`，可能原因：

- `APP_RENDERER` 拼写错误（区分大小写）
- `main.php` 未被 SFC 编译器处理（检查 `gen/` 目录）
- 旧版 AOT EXE 缓存（`build.bat <app>` 强制重编）

### 15.4 切换回 GDI

删除 `const APP_RENDERER = 'skia';` 行或改为 `'gdi'`，重新构建即可。无需清理任何 C++ 编译产物。

### 15.5 阶段对照

| 阶段 | 状态 | `sk_*` 底层 | 适用场景 |
|------|------|-------------|----------|
| 阶段一（POC） | [OK] 已完成 | Win32 GDI（与 vue_* 隔离） | 验证 AOT 链接链路 |
| 阶段二（GDI 兼容层） | [OK] 已完成 | Win32 GDI（完整 12 路） | calculator-ng 全 UI 复现 |
| 阶段三（真 Skia） | [OK] spike 通过 ⚠️ with limitations | Skia `SkBitmap + SkCanvas` + `SkCanvas::drawRect` / `drawRRect` | 抗锯齿 + 圆角 + 跨平台（文本静默跳过，待 DirectWrite） |

> **阶段三限制**：① aseprite m148 FCI 已移除 → 文本绘制静默跳过，**阶段四集成 DirectWrite**；② MSVC 17.10+ STL helpers 8 个 `__std_*` 是占位 stub，spike 未触发；③ 仅 skia-poc 用 `/MT` 静态 CRT。详见 `docs/skia-render-context-guide.md` §9。

### 15.6 关键文件

- `cpp/skia_render.cc`（~250 行，C++ 原生层）
- `stub/skia.stub.php`（21 行，stub 声明）
- `framework/Rendering/SkiaRenderContext.php`（~250 行，PHP 类）
- `apps/skia-poc/`（POC 应用，仅含蓝色矩形）
- `framework/Platform/Win32Platform.php:30-37`（构造注入分支）
- `framework/aot-checker.php:138-144`（`excludedFiles` 加 `SkiaRenderContext.php`）
- `framework/Rendering/RenderContext.php`（`use native_types;`）
- `docs/skia-render-context-guide.md`（实施指南，事实源文档）

### 15.7 已知限制

1. **单窗口**：`g_skHwnd/g_skHdc/g_skSurface` 是模块静态变量，多窗口下冲突。Phase 6 通过 `php::Box` 重构
2. **文本静默跳过（Skia 阶段三限制）**：aseprite m148 fork 已移除 `SkFontMgr_New_FCI`，`skEnsureFont()` 返回 `false` 走空路径。按键数字/标签为空白。**阶段四集成 `SkFontMgr_New_DirectWrite` 加载 Segoe UI**。其他元素（矩形/圆角/线条/位图）正常渲染
3. **MSVC 17.10+ 内部 STL 符号 stub（8 个）**：aseprite m148 预编译引用 `__std_min_element_f` / `__std_max_element_f` / `__std_minmax_element_f` / `__std_max_element_2` / `__std_max_element_1` / `__std_find_trivial_1` / `__std_find_trivial_8` / `__std_search_1`，本地 MSVC 14.x 不提供。`cpp/skia_render.cc` 顶部 `extern "C" { void __std_xxx() {} }` 占位。spike 启动 3s+ 未触发，根本修复需重编 Skia（VS 17.10+）或升 MSVC
4. **静态 CRT 强制 `/MT`**：Skia 预编译用 `/MT`，本框架原 `/MD`。skia-poc cxx-flags 加 `/MT` 覆盖（`cl warning D9025`），仅本项目生效
5. **GPU backend 未启用**：当前仅用 CPU `SkBitmap + SkCanvas::MakeRasterDirectN32` + `SetDIBitsToDevice`，未启用 Direct3D 12 / Vulkan。性能优化留作 Phase 4

### 15.8 修改框架代码时的检查清单补充

在第十二章"修改框架代码时的检查清单"基础上，新增：

18. **渲染后端切换**：新增 `sk_*` 原生函数时必须同时更新 stub（`stub/skia.stub.php`）+ PHP 端（`framework/Rendering/SkiaRenderContext.php`）+ C++ 端（`cpp/skia_render.cc`），三处形参严格一致。新增 SkiaRenderContext 抽象方法时同步在 `framework/Rendering/RenderContext.php` 加 abstract 声明。修改 `Win32Platform.php` 的 `init()` 分支时保持 `GdiRenderContext` 为默认（零回归约束）