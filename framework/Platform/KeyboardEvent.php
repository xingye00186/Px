<?php

namespace Px\Platform;

use native_types;

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
