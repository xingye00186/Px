<?php

namespace Px\Platform;

use native_types;

class PlatformFactory
{
    public static function create(string $type): Platform
    {
        if ($type === 'win32') {
            return new Win32Platform();
        }
        throw new \RuntimeException("Unsupported platform: $type");
    }
}