# Vue 3 风格动画系统实现计划

## Context

Px 框架目前缺乏动画/过渡系统支持。用户希望实现与 Vue 3 完全相同语义的动画系统。

**用户反馈关键点**：
1. 帧驱动需要原生 C++ 扩展（vue_set_timer）
2. LayoutResolver 必须创建样式副本，不修改 $node->style
3. Interpolator 输入使用整型 BGR 值避免字符串解析
4. translate 变换使用 $effectiveX = $node->x + $translateX，不修改 $node->x
5. Transition 组件基于 v-show 实现（节点始终存在）
6. CSS transition 自动触发在 RenderTreeManager::updateFromVNode() 中

---

## 实现范围

1. **完整功能**：Transition + TransitionGroup + CSS transition/animation + JavaScript 钩子（PHP 版本）
2. **需要 @keyframes 支持**
3. **支持属性**：opacity、background-color、width、height、left/top、translateX/Y

---

## 架构设计

### 模块依赖

```
framework/
├── Core/
│   ├── Application.php          ← 修改：集成动画定时器
│   ├── Scheduler.php            → 已存在
│   └── AnimationManager.php      ← 新增：动画调度中心
├── Animation/                    ← 新增目录
│   ├── EasingFunctions.php       ← 新增：缓动函数库
│   ├── Interpolator.php          ← 新增：属性插值引擎
│   ├── TransitionController.php  ← 新增：过渡状态机
│   ├── TransitionComponent.php   ← 新增：Transition 组件
│   ├── TransitionGroupComponent.php ← 新增：TransitionGroup 组件
│   ├── CssAnimationParser.php    ← 新增：CSS 动画属性解析
│   └── KeyframeResolver.php      ← 新增：关键帧插值
├── Platform/
│   ├── Platform.php              ← 修改：添加动画定时器接口
│   └── Win32Platform.php         ← 修改：实现 vue_set_timer
├── Rendering/
│   ├── RenderNode.php             ← 修改：添加 animatedStyle/isAnimating/lastX/lastY
│   ├── RenderTreeManager.php      ← 修改：添加查找方法、坐标缓存、transition 触发
│   └── CssMappings.php            ← 修改：添加 animation/transition 属性
└── BaseComponent.php              ← 修改：添加动画钩子方法
```

---

## Phase 0: 帧驱动（新增）

### 问题
当前事件循环没有固定帧率，runOneMacrotask 只执行一个宏任务后循环继续，无法保证每帧 16ms 调用 AnimationManager::tick()。

### 解决方案
在 Win32Platform 中实现 SetTimer 定时器，每 16ms 投递一个 WM_TIMER 事件。

**需要修改的文件**：

1. **修改 `stub/vue_calc.stub.php`**
   ```php
   // 添加定时器常量
   class WinMsg {
       public const WM_TIMER = 0x0113;
   }

   // 添加定时器函数
   function vue_set_timer(int $hWnd, int $intervalMs): int {}
   ```

2. **修改 `framework/Platform/Platform.php`**
   ```php
   interface Platform {
       // 新增
       public function setAnimationTimer(callable $callback, int $intervalMs): void;
   }
   ```

3. **修改 `framework/Platform/Win32Platform.php`**
   ```php
   // 在 init() 中调用 vue_set_timer
   // 在 eventMap 中添加 WM_TIMER
   private array $eventMap = [
       WinMsg::WM_TIMER => ['timer', 'tick'],
       // ...existing
   ];

   public function setAnimationTimer(callable $callback, int $intervalMs): void {
       $this->animationCallback = $callback;
       vue_set_timer($this->hwnd, $intervalMs);
   }
   ```

4. **修改 `framework/Core/Application.php`**
   ```php
   private function handleTimerEvent(): void {
       AnimationManager::getInstance()->tick();
   }

   // 在 mount() 中设置定时器
   $this->platform->setAnimationTimer(fn () => $this->handleTimerEvent(), 16);
   ```

**注意**：需要 C++ 层实现 `vue_set_timer()`，在 Swoole Compiler 环境中添加原生函数。

### C++ 层实现：vue_set_timer

```cpp
// cpp/px.cpp 或相关源文件

#include <windows.h>
#include <map>

// 全局存储：HWND → 定时器回调函数指针
static std::map<HWND, void(*)()> g_timerCallbacks;

// Windows API Timer Callback (TIMERPROC)
void CALLBACK TimerCallback(HWND hwnd, UINT msg, UINT_PTR idEvent, DWORD time) {
    auto it = g_timerCallbacks.find(hwnd);
    if (it != g_timerCallbacks.end()) {
        it->second();  // 调用 PHP 回调
    }
}

/**
 * 设置定时器，每隔 intervalMs 毫秒触发一次回调
 *
 * @param hwnd        窗口句柄
 * @param intervalMs   间隔（毫秒），推荐 16ms（约 60fps）
 * @return 定时器 ID（用于 KillTimer）
 */
int vue_set_timer(HWND hwnd, int intervalMs) {
    // 使用 SetTimer，timer ID = 1
    // 回调函数 TimerCallback 会在定时器触发时被调用
    return SetTimer(hwnd, 1, intervalMs, TimerCallback);
}

/**
 * 停止定时器
 */
void vue_kill_timer(HWND hwnd, int timerId) {
    KillTimer(hwnd, timerId);
}
```

### 实现要点

1. **回调存储**：使用 `std::map<HWND, void(*)()>` 存储每个窗口的回调函数
2. **TIMERPROC**：Windows API 要求使用 `TIMERPROC` 回调签名（详见 Windows SDK）
3. **线程安全**：定时器回调在窗口消息线程中执行，无需额外同步

### 注册回调的 PHP 接口

```php
// Win32Platform.php
private ?callable $animationCallback = null;

public function setAnimationTimer(callable $callback, int $intervalMs): void {
    $this->animationCallback = $callback;

    // 通过 FFI 或预编译的扩展调用 C++ 函数
    vue_set_timer($this->hwnd, $intervalMs);
}

// 在 pollEvents 中处理 WM_TIMER
private array $eventMap = [
    WinMsg::WM_TIMER => ['timer', 'tick'],
    // ...existing
];

public function pollEvents(): array {
    // ... 现有代码 ...

    if ($msgType === WinMsg::WM_TIMER && $this->animationCallback !== null) {
        ($this->animationCallback)();  // 调用 AnimationManager::tick()
    }
}
```

---

## Phase 1: 基础设施

### 1.1 EasingFunctions.php

```php
class EasingFunctions {
    public static function apply(string $name, float $t): float {
        return match ($name) {
            'linear'      => $t,
            'ease'        => self::ease($t),
            'ease-in'     => $t * $t,
            'ease-out'    => $t * (2 - $t),
            'ease-in-out' => $t < 0.5 ? 2*$t*$t : -1+(4-2*$t)*$t,
            default       => self::cubicBezier($t, $name),
        };
    }

    public static function ease(float $t): float {
        return $t < 0.5 ? 2*$t*$t : -1+(4-2*$t)*$t;
    }

    // cubic-bezier 求解使用 Newton-Raphson 迭代
    public static function cubicBezier(float $t, string $spec): float { ... }
}
```

### 1.2 Interpolator.php

```php
class Interpolator {
    /**
     * 数值线性插值
     */
    public static function interpolateNumber(float $from, float $to, float $progress): float {
        return $from + ($to - $from) * $progress;
    }

    /**
     * 颜色插值（使用整型 BGR 值，避免字符串解析开销）
     * @param int $fromBgr 0xBBGGRR
     * @param int $toBgr   0xBBGGRR
     * @param float $progress 0.0-1.0
     * @return int 插值结果 0xBBGGRR
     */
    public static function interpolateColor(int $fromBgr, int $toBgr, float $progress): int {
        $fromR = ($fromBgr >> 0) & 0xFF;
        $fromG = ($fromBgr >> 8) & 0xFF;
        $fromB = ($fromBgr >> 16) & 0xFF;

        $toR = ($toBgr >> 0) & 0xFF;
        $toG = ($toBgr >> 8) & 0xFF;
        $toB = ($toBgr >> 16) & 0xFF;

        $r = (int)round($fromR + ($toR - $fromR) * $progress);
        $g = (int)round($fromG + ($toG - $fromG) * $progress);
        $b = (int)round($fromB + ($toB - $fromB) * $progress);

        return ($b << 16) | ($g << 8) | $r;
    }

    /**
     * translate 插值（仅支持 translateX/Y）
     * @return array ['translateX' => int, 'translateY' => int]
     */
    public static function interpolateTransform(int $fromX, int $fromY, int $toX, int $toY, float $progress): array {
        return [
            'translateX' => (int)round($fromX + ($toX - $fromX) * $progress),
            'translateY' => (int)round($fromY + ($toY - $fromY) * $progress),
        ];
    }
}
```

---

## Phase 2: 核心引擎

### 2.1 RenderNode.php 扩展

```php
class RenderNode {
    // 现有字段...

    /** 动画临时覆盖样式（优先级高于 style） */
    public array $animatedStyle = [];

    /** 是否有活跃动画 */
    public bool $isAnimating = false;

    /** 上次布局时的坐标（用于 FLIP 检测） */
    public int $lastX = 0;
    public int $lastY = 0;
}
```

### 2.2 AnimationManager.php

```php
class AnimationManager {
    private static ?self $instance = null;
    private array $animations = [];       // key => RunningAnimation
    private Application $app;
    private bool $isTicking = false;

    /** 同时动画节点数量上限（防止 GC 压力） */
    private const MAX_ANIMATIONS = 50;

    /** 对象池：复用样式数组以减少 GC 压力 */
    private array $stylePool = [];
    private int $poolSize = 0;
    private const POOL_CAPACITY = 100;

    public static function getInstance(): self;
    public function setApp(Application $app): void;

    /**
     * 添加过渡动画
     * @return string 动画 key（用于取消）
     */
    public function addTransition(string $key, RenderNode $node, array $properties, int $duration, string $easing, ?callable $done): string;

    /**
     * 添加 CSS 动画（@keyframes）
     */
    public function addAnimation(string $key, RenderNode $node, string $animationName, array $keyframes, int $duration, string $easing, int $iterationCount, ?callable $done): string;

    public function tick(): void;
    public function hasAnimations(): bool;
    public function cancelAnimation(string $key): void;

    /**
     * 从对象池获取样式数组
     */
    private function acquireStyleArray(): array {
        if ($this->poolSize > 0) {
            return array_pop($this->stylePool);
        }
        return [];
    }

    /**
     * 归还样式数组到对象池
     */
    private function releaseStyleArray(array $arr): void {
        if ($this->poolSize < self::POOL_CAPACITY) {
            // 清空数组但不销毁，以便复用
            foreach ($arr as $k => $v) {
                unset($arr[$k]);
            }
            $this->stylePool[] = $arr;
            $this->poolSize++;
        }
    }
}

class RunningAnimation {
    public string $key;
    public RenderNode $targetNode;
    public array $properties;      // ['opacity' => [from, to], ...]
    public string $easing;
    public int $duration;
    public float $startTime;
    public ?callable $doneCallback;
    public bool $cancelled = false;
    public ?string $animationName = null;
    public ?array $keyframeRules = null;
    public string $type = 'transition';
    public int $iterationCount = 1;
}
```

**tick() 核心逻辑**：
```php
public function tick(): void {
    if ($this->isTicking) return;
    $this->isTicking = true;

    // 限制同时动画节点数量
    if (count($this->animations) > self::MAX_ANIMATIONS) {
        // 取消最早的动画
        $keys = array_keys($this->animations);
        $cancelCount = count($this->animations) - self::MAX_ANIMATIONS;
        for ($i = 0; $i < $cancelCount; $i++) {
            $this->cancelAnimation($keys[$i]);
        }
    }

    $now = microtime(true);
    $completed = [];

    foreach ($this->animations as $key => $anim) {
        if ($anim->cancelled) { $completed[] = $key; continue; }

        $elapsed = ($now - $anim->startTime) * 1000;
        $progress = min(1.0, $elapsed / $anim->duration);
        $easedProgress = EasingFunctions::apply($anim->easing, $progress);

        $this->applyFrame($anim, $easedProgress);

        if ($progress >= 1.0) {
            $completed[] = $key;
            if ($anim->doneCallback !== null) {
                $this->app->getScheduler()->addMicrotask($anim->doneCallback);
            }
        }
    }

    foreach ($completed as $k) {
        $anim = $this->animations[$k];
        $anim->targetNode->animatedStyle = [];
        $anim->targetNode->isAnimating = false;
        $anim->targetNode->markLayoutDirty(true);
        unset($this->animations[$k]);
    }

    if (count($completed) > 0 || count($this->animations) > 0) {
        $this->app->directRender();
    }

    $this->isTicking = false;
}
```

### 2.3 TransitionComponent.php

基于 v-show 的 Transition 组件实现：

```php
class TransitionComponent extends ReactiveComponent {
    public string $name = 'v';
    public bool $appear = false;
    public ?int $duration = null;
    public string $mode = '';

    // bind 属性（存储字符串值，因为 getBindValue 返回 string）
    public string $show = 'true';

    private TransitionController $controller;

    public function render(): VNode {
        $show = ($this->show === 'true');
        $child = $this->getDefaultChild();
        if ($child === null) {
            return VNode::h('#root', ['style' => 'width:0;height:0'], []);
        }

        // 设置 visibility 样式（v-show 实现）
        $style = $child->props['style'] ?? '';
        if (!$show) {
            $style = CssMappings::addVisibilityHidden($style);
        }

        return VNode::h('div', ['style' => $style], $child->children);
    }

    /**
     * 监听 show prop 变化触发动画。
     *
     * 注意：
     * - setBindValue 是 public 方法，会被父组件调用
     * - 需要先调用父类存储值，再检测变化
     * - show prop 是 "true"/"false" 字符串（因为 getBindValue 返回 string）
     */
    public function setBindValue(string $key, string $value): void {
        // 先调用父类存储值
        parent::setBindValue($key, $value);

        if ($key === 'show') {
            // 字符串比较，因为 getBindValue 返回 string
            $nowShowing = ($value === 'true');
            $wasShowing = ($this->show === 'true');

            if ($nowShowing !== $wasShowing) {
                // 获取子节点的 RenderNode 并触发动画
                $childNode = $this->getChildRenderNode();
                if ($childNode !== null) {
                    if ($nowShowing) {
                        $this->controller->enter($childNode);
                    } else {
                        $this->controller->leave($childNode);
                    }
                }
            }
        }
    }

    /**
     * 获取默认子节点的 RenderNode。
     */
    private function getChildRenderNode(): ?RenderNode {
        $tree = $this->getVNodeTree();
        if ($tree->children instanceof VNode) {
            $child = $tree->children;
            return Application::getInstance()
                ->getRenderTreeManager()
                ->findRenderNodeBySourceVNode($child);
        }
        if (is_array($tree->children)) {
            foreach ($tree->children as $child) {
                if ($child instanceof VNode) {
                    return Application::getInstance()
                        ->getRenderTreeManager()
                        ->findRenderNodeBySourceVNode($child);
                }
            }
        }
        return null;
    }

    // JavaScript 钩子（默认空实现，可被子类 override）
    public function onBeforeEnter(VNode $el): void { }
    public function onEnter(VNode $el, callable $done): void { $done(); }
    public function onAfterEnter(VNode $el): void { }
    public function onEnterCancelled(VNode $el): void { }
    public function onBeforeLeave(VNode $el): void { }
    public function onLeave(VNode $el, callable $done): void { $done(); }
    public function onAfterLeave(VNode $el): void { }
    public function onLeaveCancelled(VNode $el): void { }
}
```

### 2.4 TransitionController.php

```php
class TransitionController {
    private TransitionComponent $transition;
    private string $state = 'idle';

    public function enter(RenderNode $node): void {
        $this->state = 'entering';
        $el = $node->sourceVNode;

        // 1. onBeforeEnter 钩子
        $this->transition->onBeforeEnter($el);

        // 2. 添加 enter-from/active 类
        $this->addClass($node, "{$this->transition->name}-enter-from");
        $this->addClass($node, "{$this->transition->name}-enter-active");

        // 3. 读取 from 样式
        $fromStyle = $this->getEnterFromStyle();
        $node->animatedStyle = $fromStyle;
        $node->isAnimating = true;

        // 4. 注册动画
        $done = function() use ($node) {
            $this->scheduler->addMicrotask(function() use ($node) {
                $this->onEnterDone($node);
            });
        };

        $this->transition->onEnter($el, $done);

        // 5. 超时保护
        $duration = $this->transition->duration ?? 300;
        $this->scheduler->addMacrotask(function() use ($done, $duration) {
            // 2x duration 后强制完成
        });
    }

    private function onEnterDone(RenderNode $node): void {
        $this->removeClass($node, "enter-from");
        $this->addClass($node, "enter-to");
        $this->transition->onAfterEnter($node->sourceVNode);
        $this->state = 'idle';
    }
}
```

---

## Phase 3: CSS 解析

### 3.1 CssMappings.php 扩展

```php
// 添加 transition/animation 属性映射
const ANIMATION_PROPERTIES = [
    'transition' => ['key' => 'transition', 'parser' => 'parseTransition'],
    'animation' => ['key' => 'animation', 'parser' => 'parseAnimation'],
    'animation-name' => ['key' => 'animationName'],
    'animation-duration' => ['key' => 'animationDuration', 'parser' => 'parseMs'],
    'animation-timing-function' => ['key' => 'animationTiming'],
];
```

### 3.2 CssAnimationParser.php

```php
class CssAnimationParser {
    /**
     * 解析 transition 简写
     * @return array [['property'=>..., 'duration'=>..., 'timing'=>..., 'delay'=>...], ...]
     */
    public static function parseTransitionRules(string $style): array;

    /**
     * 解析 animation 简写
     */
    public static function parseAnimationRules(string $style): array;

    /**
     * 检查属性是否可过渡
     */
    public static function isTransitionable(string $property): bool {
        return in_array($property, [
            'opacity', 'width', 'height', 'left', 'top',
            'background', 'color', 'border-color', 'font-size'
        ]);
    }
}
```

---

## Phase 4: TransitionGroup

### 4.1 TransitionGroupComponent.php

```php
class TransitionGroupComponent extends TransitionComponent {
    public ?string $tag = null;  // 包装元素标签
    public string $move = 'ease';
    public int $moveDuration = 300;

    public function onUpdated(): void {
        parent::onUpdated();
        $this->processMove();
    }

    /**
     * FLIP 算法：检测列表项位置变化并创建 move 动画
     *
     * 更新时机：lastX/lastY 应在每次完整布局完成后
     * （即 LayoutResolver::resolve 结束后）由 TransitionGroupComponent::onUpdated
     * 统一读取并存储，而不是在每个节点动画结束后单独更新。
     *
     * 流程：
     * 1. 遍历所有子节点，读取当前布局坐标（lastX/lastY 存储的是上一次布局的位置）
     * 2. 与当前 x/y 比较，计算位移差
     * 3. 若有位移且节点不在动画中，创建 move 动画
     * 4. 动画完成后不更新 lastX/lastY（由下一次 onUpdated 统一更新）
     */
    private function processMove(): void {
        foreach ($this->getChildNodes() as $node) {
            $key = $node->key;
            if ($key === null) continue;

            // 跳过正在动画的节点（避免覆盖 lastX/lastY）
            if ($node->isAnimating) {
                continue;
            }

            $oldX = $node->lastX;
            $oldY = $node->lastY;
            $newX = $node->x;
            $newY = $node->y;

            // 仅当节点不是由动画驱动时，才认为这是真实的位置变化
            // 且 oldX/oldY 已初始化（不等于 0 表示已有历史值）
            if (($oldX !== 0 || $oldY !== 0) && ($oldX !== $newX || $oldY !== $newY)) {
                // 计算位移差
                $deltaX = $newX - $oldX;
                $deltaY = $newY - $oldY;

                // 先移动到旧位置（无动画）
                $node->animatedStyle['translateX'] = -$deltaX;
                $node->animatedStyle['translateY'] = -$deltaY;
                $node->isAnimating = true;

                // 注册 FLIP 动画
                AnimationManager::getInstance()->addTransition(
                    "move-{$key}",
                    $node,
                    ['translateX' => [-$deltaX, 0], 'translateY' => [-$deltaY, 0]],
                    $this->moveDuration,
                    $this->move,
                    null  // 动画完成后不更新 lastX/lastY
                );

                // 添加 move 类
                $this->addClass($node, "{$this->name}-move");
            }

            // 动画结束后也不更新 lastX/lastY
            // 下一次 onUpdated 时统一读取当前布局坐标
        }
    }
}
```

### 更新时机说明

| 时机 | 操作 |
|------|------|
| **onUpdated** | 读取当前 x/y，与 lastX/lastY 比较，创建 move 动画（若需要） |
| **动画完成后** | 不更新 lastX/lastY |
| **下次 onUpdated** | 再次读取当前 x/y（此时是动画结束后的最终位置）并更新 lastX/lastY |
```

---

## Phase 5: @keyframes 支持

### 5.1 sfc-compiler.php 修改

```php
/**
 * 从 <style> 块中提取 @keyframes 规则
 * @return array [animationName => [[point, properties], ...]]
 */
function extractKeyframes(string $styleCss): array;

/**
 * 生成组件的 keyframes 数据存储
 * 注意：必须使用 protected static array 而非 const（AOT 兼容性）
 */
protected static array $animationKeyframes = [];

public function getAnimationKeyframes(string $name): array {
    return self::$animationKeyframes[$name] ?? [];
}
```

### 5.2 KeyframeResolver.php

```php
class KeyframeResolver {
    /**
     * 根据进度查找相邻关键帧并插值
     * @param array $keyframes [[point, properties], ...]
     * @param float $progress 0.0-1.0
     * @return array 插值后的属性
     */
    public static function resolve(array $keyframes, float $progress): array {
        // 找到相邻关键帧
        // 局部进度 = (progress - prevPoint) / (nextPoint - prevPoint)
        // 插值各属性
    }
}
```

---

## Phase 6: CSS transition 过渡触发

### 问题
RenderTreeManager::updateFromVNode 不接收 $oldVnode 参数，无法直接获取旧样式。

### 解决方案
在复用节点前，先保存 $renderNode->style 的副本（深拷贝）。更新为新样式后，比较新旧样式数组。

```php
/**
 * RenderTreeManager::updateFromVNode()
 *
 * 现有方法签名：
 * public function updateFromVNode(VNode $vnode, ?RenderNode $parent,
 *     ReactiveComponent $root, array $componentByGroupId): ?RenderNode
 *
 * 实现要点：
 * 1. 深拷贝：使用 foreach 逐元素复制，避免引用（样式值都是标量或简单数组）
 * 2. 过滤：排除 transition 属性本身，避免递归触发
 * 3. 状态检查：仅当节点当前没有活跃动画（$renderNode->isAnimating === false）时才触发新过渡
 */
private function updateFromVNode(VNode $vnode, ?RenderNode $parent, ReactiveComponent $root, array $componentByGroupId): ?RenderNode {
    // 尝试复用已有 RenderNode
    $renderNode = $this->findRenderNodeByVNode($vnode);

    if ($renderNode !== null) {
        // 1. 深拷贝旧样式（foreach 逐元素复制，避免引用污染）
        $oldStyle = [];
        foreach ($renderNode->style as $k => $v) {
            $oldStyle[$k] = $v;
        }
        $wasAnimating = $renderNode->isAnimating;
    }

    // 2. 解析新样式 $newStyle ...

    // 更新为新样式
    $renderNode->style = $newStyle;

    // 3. 比较新旧样式，检测可过渡属性的变化
    //    仅当节点当前没有活跃动画时才触发新过渡
    if ($renderNode !== null && !$wasAnimating) {
        foreach ($oldStyle as $prop => $oldVal) {
            // 跳过 transition 属性本身，避免递归触发
            if ($prop === 'transition') {
                continue;
            }

            if (isset($newStyle[$prop]) && $oldVal !== $newStyle[$prop]) {
                // 属性变化，检测是否需要过渡
                if (CssAnimationParser::isTransitionable($prop)) {
                    $transitionRule = $newStyle['transition'] ?? null;
                    if ($transitionRule !== null) {
                        $rules = CssAnimationParser::parseTransitionRules($transitionRule);
                        foreach ($rules as $rule) {
                            if ($rule['property'] === $prop || $rule['property'] === 'all') {
                                // 创建过渡动画
                                AnimationManager::getInstance()->addTransition(
                                    "trans-" . spl_object_id($renderNode) . "-{$prop}",
                                    $renderNode,
                                    [$prop => [$oldVal, $newStyle[$prop]]],
                                    $rule['duration'],
                                    $rule['timing'],
                                    null
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    return $renderNode;
}
```

### 实现要点总结

| 要点 | 说明 |
|------|------|
| **深拷贝** | 使用 `foreach` 逐元素复制 `$renderNode->style`，避免引用污染 |
| **过滤 transition** | 在比较循环中跳过 `transition` 属性本身 |
| **状态检查** | 仅当 `$renderNode->isAnimating === false` 时才触发新过渡 |

**注意**：
- `$oldStyle` 必须深拷贝（逐元素复制），避免引用污染
- 仅当节点不在动画状态时（`!$wasAnimating`）才触发新过渡
- 过滤掉 transition 属性本身，避免递归触发
```

---

## LayoutResolver 合并 animatedStyle 的统一做法

### 要求
LayoutResolver 中 animatedStyle 的合并必须在**所有布局分支（block、flex、grid）中统一处理**。建议在 resolveNode 入口处统一合并，避免遗漏。

### 实现方案

```php
/**
 * LayoutResolver::resolveNode() 入口
 *
 * 在入口处统一创建 $effectiveStyle 副本，合并 animatedStyle。
 * 所有后续布局分支（resolveBlockLayout、resolveFlexLayout、resolveGridLayout）
 * 都使用 $effectiveStyle 而非直接读写 $node->style。
 */
public function resolveNode(RenderNode $node, int $parentX, int $parentY, ...): void {
    // 统一创建样式副本，合并 animatedStyle
    $effectiveStyle = $node->style;
    if ($node->isAnimating && !empty($node->animatedStyle)) {
        $effectiveStyle = [];
        foreach ($node->style as $k => $v) {
            $effectiveStyle[$k] = $v;
        }
        foreach ($node->animatedStyle as $k => $v) {
            $effectiveStyle[$k] = $v;
        }
    }

    // translate 变换：$effectiveX = $node->x + $translateX
    // 不修改 $node->x/$node->y，仅计算偏移量
    $translateX = $effectiveStyle['translateX'] ?? 0;
    $translateY = $effectiveStyle['translateY'] ?? 0;
    $effectiveX = $node->x + $translateX;
    $effectiveY = $node->y + $translateY;

    // 根据 display 模式分发到不同布局分支
    $display = $effectiveStyle['display'] ?? 'block';

    if ($display === 'flex') {
        $this->resolveFlexLayout($node, $effectiveX, $effectiveY, $effectiveStyle, ...);
    } elseif ($display === 'grid') {
        $this->resolveGridLayout($node, $effectiveX, $effectiveY, $effectiveStyle, ...);
    } else {
        // block 布局
        $this->resolveBlockLayout($node, $effectiveX, $effectiveY, $effectiveStyle, ...);
    }
}
```

### 关键点

1. **入口处统一合并**：在 resolveNode 入口处创建 `$effectiveStyle`，所有分支共享
2. **深拷贝**：使用 foreach 循环创建副本，避免引用污染
   - **性能提示**：对小型样式数组（通常少于 20 个键），使用 `array_merge($node->style, $node->animatedStyle)` 替代逐键复制可减少 GC 压力，但需注意引用问题
3. **translate 应用**：使用 `$effectiveX/$effectiveY` 而非修改 `$node->x/$node->y`
4. **不修改 $node->style**：所有布局计算使用副本，不修改原始样式

### 现有代码适配

LayoutResolver 现有代码中 `resolveNode` 是私有方法，没有统一入口。需要调整：

```php
// 现有代码：在 resolve() 中直接调用 resolveNode 私有方法
// 调整方案：将 $effectiveStyle 作为参数传递

public function resolve(RenderNode $root): void {
    $this->resolveNodeRecursive($root, 0, 0);
}

private function resolveNodeRecursive(RenderNode $node, int $parentX, int $parentY): void {
    // 在入口处创建 $effectiveStyle（合并 animatedStyle）
    $effectiveStyle = $node->style;
    if ($node->isAnimating && !empty($node->animatedStyle)) {
        $effectiveStyle = array_merge($node->style, $node->animatedStyle);  // 深拷贝
    }

    // translate 计算
    $translateX = $effectiveStyle['translateX'] ?? 0;
    $translateY = $effectiveStyle['translateY'] ?? 0;
    $effectiveX = $node->x + $translateX;
    $effectiveY = $node->y + $translateY;

    // 分发到各布局分支
    $display = $effectiveStyle['display'] ?? 'block';
    if ($display === 'flex') {
        $this->resolveFlexLayout($node, $effectiveX, $effectiveY, $effectiveStyle);
    } elseif ($display === 'grid') {
        $this->resolveGridLayout($node, $effectiveX, $effectiveY, $effectiveStyle);
    } else {
        $this->resolveBlockLayout($node, $effectiveX, $effectiveY, $effectiveStyle);
    }

    // 递归处理子节点
    foreach ($node->children as $child) {
        $this->resolveNodeRecursive($child, $effectiveX, $effectiveY);
    }
}
```

### 各布局分支的职责

| 分支 | 职责 |
|------|------|
| `resolveNode()` | 入口：合并样式、计算 translate、应用 display 分发 |
| `resolveBlockLayout()` | 读取 `$effectiveStyle`，计算 block 布局 |
| `resolveFlexLayout()` | 读取 `$effectiveStyle`，计算 flex 布局 |
| `resolveGridLayout()` | 读取 `$effectiveStyle`，计算 grid 布局 |

---

## 关键文件修改清单

| 文件 | 操作 | 说明 |
|------|------|------|
| `stub/vue_calc.stub.php` | 修改 | 添加 WM_TIMER 常量和 vue_set_timer 函数 |
| `framework/Platform/Platform.php` | 修改 | 添加 setAnimationTimer 接口 |
| `framework/Platform/Win32Platform.php` | 修改 | 实现定时器驱动 |
| `framework/Core/Application.php` | 修改 | handleTimerEvent、设置动画定时器 |
| `framework/Core/AnimationManager.php` | 新增 | 动画调度中心 |
| `framework/Animation/EasingFunctions.php` | 新增 | 缓动函数库 |
| `framework/Animation/Interpolator.php` | 新增 | 属性插值引擎 |
| `framework/Animation/TransitionController.php` | 新增 | 过渡状态机 |
| `framework/Animation/TransitionComponent.php` | 新增 | Transition 组件（基于 v-show） |
| `framework/Animation/TransitionGroupComponent.php` | 新增 | TransitionGroup 组件 |
| `framework/Animation/CssAnimationParser.php` | 新增 | CSS 动画属性解析 |
| `framework/Animation/KeyframeResolver.php` | 新增 | 关键帧插值 |
| `framework/Rendering/RenderNode.php` | 修改 | 添加 animatedStyle/isAnimating/lastX/lastY |
| `framework/Rendering/RenderTreeManager.php` | 修改 | 添加查找方法、坐标缓存、transition 触发 |
| `framework/Rendering/CssMappings.php` | 修改 | 添加 animation/transition 属性 |
| `framework/compiler/sfc-compiler.php` | 修改 | 添加 @keyframes 提取 |
| `framework/Layout/LayoutResolver.php` | 修改 | 合并 animatedStyle、应用 translate 偏移 |

---

## 测试计划

1. **单元测试**
   - `EasingFunctionsTest` - 缓动函数精度
   - `InterpolatorTest` - 插值计算正确性
   - `TransitionControllerTest` - 状态机转换

2. **集成测试**
   - 创建 `apps/animation-test` 演示应用
   - 测试 Fade/Slide 过渡效果
   - 测试 TransitionGroup 列表动画

3. **截图测试**
   - 使用 PowerShell 脚本自动截图验证动画效果

---

## 阶段依赖关系

```
Phase 0（帧驱动）
    ↓
Phase 1（EasingFunctions + Interpolator）
    ↓
Phase 2（AnimationManager + TransitionComponent）
    │
Phase 3（CSS 解析 + RenderNode 扩展）────┐
    ↓                                   ↓
Phase 4（TransitionGroup）        Phase 5（@keyframes）
    ↓
Phase 6（CSS transition 过渡触发）
```

---

## 补充细节

### 动画完成后的清理

```php
// AnimationManager::tick() 中动画完成时
foreach ($completed as $k) {
    $anim = $this->animations[$k];

    // 1. 清理动画样式
    $anim->targetNode->animatedStyle = [];
    $anim->targetNode->isAnimating = false;

    // 2. 标记布局脏（向上传播，刷新子树）
    $anim->targetNode->markLayoutDirty(true);

    // 3. 移除动画实例
    unset($this->animations[$k]);
}
```

### 批量 directRender 优化

```php
public function tick(): void {
    // ... 计算所有动画的插值（不触发渲染）

    // 所有插值计算完成后，一次性 directRender
    // 而非每个动画单独触发
    if (count($completed) > 0 || count($this->animations) > 0) {
        $this->app->directRender();
    }
}
```

### 脏标记传播策略

```php
// 仅标记受影响的节点及其祖先为 layoutDirty
// 而非全量重建整棵树
$anim->targetNode->markLayoutDirty(true);  // 向上传播

// LayoutResolver 中跳过无动画节点的重算
if (!$node->isAnimating && !$node->layoutDirty && $node->parent !== null) {
    // 快速路径：仅传递坐标
    return;
}
```

### C++ 层 vue_set_timer 实现要点

```cpp
// cpp/px.cpp 中添加
typedef void (__cdecl *TimerCallback)();

static std::map<HWND, TimerCallback> g_timerCallbacks;

int vue_set_timer(HWND hwnd, int intervalMs) {
    TimerCallback callback = g_timerCallbacks[hwnd];
    return SetTimer(hwnd, 1, intervalMs, [](HWND hwnd, UINT msg, UINT_PTR id, DWORD time) {
        auto it = g_timerCallbacks.find(hwnd);
        if (it != g_timerCallbacks.end()) {
            it->second();  // 调用 PHP 回调
        }
    });
}
```

### AOT 兼容性检查清单

- [ ] interpolateColor() 使用整型 BGR 输入，不解析字符串
- [ ] @keyframes 数据使用 `protected static array` 而非 `const`
- [ ] 动画插值计算使用 `foreach` 而非闭包（避免 AOT 闭包变量作用域问题）
- [ ] 禁止动态方法调用 `$obj->$method()`，使用静态已知方法

---

## 使用示例（目标语法）

```html
<!-- Transition 组件（基于 v-show） -->
<template>
  <Transition name="fade" :appear="true" :duration="300">
    <div v-show="show" class="box">Hello</div>
  </Transition>
</template>

<style>
.fade-enter-from { opacity: 0; }
.fade-enter-active { transition: opacity 300ms ease; }
.fade-enter-to { opacity: 1; }
.fade-leave-from { opacity: 1; }
.fade-leave-active { transition: opacity 300ms ease; }
.fade-leave-to { opacity: 0; }
</style>

<!-- TransitionGroup 组件 -->
<template>
  <TransitionGroup name="list" tag="ul">
    <li v-for="item in items" :key="item.id" @click="remove(item.id)">
      {{ item.text }}
    </li>
  </TransitionGroup>
</template>

<style>
.list-enter-from { opacity: 0; transform: translateX(30px); }
.list-enter-active { transition: all 300ms ease; }
.list-leave-to { opacity: 0; transform: translateX(-30px); }
.list-leave-active { transition: all 300ms ease; }
.list-move { transition: transform 300ms ease; }
</style>

<!-- @keyframes 动画 -->
<style>
@keyframes bounce {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-20px); }
}
.bounce-enter-active { animation: bounce 600ms ease; }
</style>
```