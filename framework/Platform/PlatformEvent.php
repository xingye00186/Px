<?php

namespace Px\Platform;

use native_types;

abstract class PlatformEvent
{
    public string $type;
    public int $timestamp;

    public function __construct(string $type)
    {
        $this->type      = $type;
        $this->timestamp = time();
    }
}

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

class KeyboardEvent extends PlatformEvent
{
    public string $action;
    public int $keyCode;
    public string $char;

    public function __construct(string $action, int $keyCode, string $char = '')
    {
        parent::__construct('keyboard');
        $this->action  = $action;
        $this->keyCode = $keyCode;
        $this->char    = $char;
    }

    // AOT getter
    public function getAction(): string { return $this->action; }
    public function getKeyCode(): int { return $this->keyCode; }
    public function getChar(): string { return $this->char; }
}

class WindowEvent extends PlatformEvent
{
    public string $action;
    public int $width  = 0;
    public int $height = 0;

    public function __construct(string $action, int $width = 0, int $height = 0)
    {
        parent::__construct('window');
        $this->action = $action;
        $this->width  = $width;
        $this->height = $height;
    }
}

class TimerEvent extends PlatformEvent
{
    public int $timerId;

    public function __construct(int $timerId)
    {
        parent::__construct('timer');
        $this->timerId = $timerId;
    }
}

class IoEvent extends PlatformEvent
{
    public string $ioType;
    public string $path;
    public mixed $data;

    public function __construct(string $ioType, string $path, mixed $data = null)
    {
        parent::__construct('io');
        $this->ioType = $ioType;
        $this->path   = $path;
        $this->data   = $data;
    }
}