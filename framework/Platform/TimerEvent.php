<?php

namespace Px\Platform;

use native_types;

class TimerEvent extends PlatformEvent
{
    public int $timerId;

    public function __construct(int $timerId)
    {
        parent::__construct('timer');
        $this->timerId = $timerId;
    }
}
