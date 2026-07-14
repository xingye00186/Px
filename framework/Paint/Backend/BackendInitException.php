<?php

namespace Px\Paint\Backend;

use native_types;

/**
 * BackendInitException — 后端初始化失败
 *
 * RuntimeBackendSelector 在 initialize() 抛此异常时，
 * 自动降级到下一个候选后端。
 */
class BackendInitException extends \RuntimeException
{
    public string $backendName;

    public function __construct(string $backendName, string $message)
    {
        parent::__construct("[{$backendName}] {$message}");
        $this->backendName = $backendName;
    }
}
