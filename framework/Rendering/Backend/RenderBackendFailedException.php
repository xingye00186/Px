<?php

namespace Px\Rendering\Backend;

/**
 * RenderBackendFailedException — 渲染后端运行时失败
 *
 * 由 RenderContext 子类在运行时抛出（GPU 设备丢失、显存不足等）。
 * ResilientRenderContext 捕获后计数，连续 N 次触发降级。
 */
class RenderBackendFailedException extends \RuntimeException
{
    public string $backendName;

    public function __construct(string $backendName, string $message)
    {
        parent::__construct("[{$backendName}] {$message}");
        $this->backendName = $backendName;
    }
}
