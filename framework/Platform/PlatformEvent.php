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