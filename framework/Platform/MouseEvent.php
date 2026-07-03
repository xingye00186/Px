<?php

namespace Px\Platform;

use native_types;

class MouseEvent extends PlatformEvent
{
    public string $action;
    public int $x;
    public int $y;
    public int $button;
    public int $delta;

    public bool $shiftDown;

    public function __construct(string $action, int $x, int $y, int $button = 0, int $delta = 0, bool $shiftDown = false)
    {
        parent::__construct('mouse');
        $this->action = $action;
        $this->x      = $x;
        $this->y      = $y;
        $this->button = $button;
        $this->delta  = $delta;
        $this->shiftDown = $shiftDown;
    }

    // AOT getter：方法内 $this 编译器知道确切类型，生成直接 C++ struct 成员访问
    public function getAction(): string { return $this->action; }
    public function getX(): int { return $this->x; }
    public function getY(): int { return $this->y; }
    public function getButton(): int { return $this->button; }
    public function getDelta(): int { return $this->delta; }
    public function isShiftDown(): bool { return $this->shiftDown; }
}
