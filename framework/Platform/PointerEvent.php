<?php

namespace Px\Platform;

use native_types;

/**
 * PointerEvent — 统一指针事件（终极融合 P0.2）
 *
 * **取代 MouseEvent**。这不是 MouseEvent + TouchEvent 的拼接，而是一个全新的
 * 统一抽象：平台差异只体现在 $kind 字段上，Framework 层对所有指针来源走同一
 * 条代码路径（铁律 1：框架层零平台分支）。
 *
 *   Win32   WM_LBUTTONDOWN → PointerEvent(kind:'mouse')
 *   Android ACTION_DOWN    → PointerEvent(kind:'touch', pointerId:n)
 *   iOS     touchesBegan   → PointerEvent(kind:'touch', pointerId:n)
 *
 * AOT 约束：全字段为 int/string/bool，**无 nullable、无 float**。
 *  - nullable int 需 sentinel(-1) 模式，且哨兵值禁入声明层
 *  - float 破坏 CLI≡AOT 整数确定性算术契约（压感故用 0..1000 千分比）
 */
class PointerEvent extends PlatformEvent
{
    /** 'down' | 'up' | 'move' | 'wheel' | 'cancel'（cancel 为移动端预留） */
    public string $action;

    public int $x;
    public int $y;

    /** 0 = 主键, 1 = 次键, 2 = 中键 */
    public int $button;

    /** 滚轮增量（正=上滚）。原 MouseEvent::$delta 正名 */
    public int $scrollDelta;

    public bool $shiftDown;

    /** 'mouse' | 'touch' | 'stylus' —— 唯一承载平台输入差异的字段 */
    public string $kind;

    /** 多指触摸标识；鼠标恒 0 */
    public int $pointerId;

    /** 压感 ×1000（0..1000）；鼠标/无压感设备恒 1000 */
    public int $pressure;

    public function __construct(
        string $action,
        int $x,
        int $y,
        int $button = 0,
        int $scrollDelta = 0,
        bool $shiftDown = false,
        string $kind = 'mouse',
        int $pointerId = 0,
        int $pressure = 1000
    ) {
        parent::__construct('pointer');
        $this->action      = $action;
        $this->x           = $x;
        $this->y           = $y;
        $this->button      = $button;
        $this->scrollDelta = $scrollDelta;
        $this->shiftDown   = $shiftDown;
        $this->kind        = $kind;
        $this->pointerId   = $pointerId;
        $this->pressure    = $pressure;
    }

    // AOT getter：方法内 $this 类型确定，生成直接 C++ struct 成员访问
    public function getAction(): string { return $this->action; }
    public function getX(): int { return $this->x; }
    public function getY(): int { return $this->y; }
    public function getButton(): int { return $this->button; }
    public function getScrollDelta(): int { return $this->scrollDelta; }
    public function isShiftDown(): bool { return $this->shiftDown; }
    public function getKind(): string { return $this->kind; }
    public function getPointerId(): int { return $this->pointerId; }
    public function getPressure(): int { return $this->pressure; }
}
