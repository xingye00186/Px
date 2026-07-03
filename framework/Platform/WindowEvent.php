<?php

namespace Px\Platform;

use native_types;

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
