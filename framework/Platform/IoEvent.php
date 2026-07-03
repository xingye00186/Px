<?php

namespace Px\Platform;

use native_types;

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
