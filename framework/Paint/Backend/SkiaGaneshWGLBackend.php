<?php

namespace Px\Paint\Backend;

use native_types;
use Px\Paint\RenderContext;

/**
 * SkiaGaneshWGLBackend — 阶段四：Skia Ganesh + WGL (Windows OpenGL)
 *
 * 探测条件：
 *  - sk_wgl_probe() 存在并返回 ok=true
 *  - C++ 端 wglCreateContext + wglMakeCurrent 成功
 *
 * 性能：硬件加速 OpenGL 渲染。
 * 优先级：80
 */
class SkiaGaneshWGLBackend implements IRenderBackend
{
    private ?RenderContext $context = null;

    public function getName(): string
    {
        return 'skia-wgl';
    }

    public static function getPriority(): int
    {
        return 80;
    }

    public function probe(): BackendCapability
    {
        if (!function_exists('sk_wgl_probe')) {
            return BackendCapability::unavailable('sk_wgl_probe not linked (WGL backend not compiled)');
        }
        try {
            $result = sk_wgl_probe();
        } catch (\Throwable $e) {
            return BackendCapability::unavailable('sk_wgl_probe threw: ' . $e->getMessage());
        }
        if (!is_array($result) || !($result['ok'] ?? false)) {
            return BackendCapability::unavailable(
                $result['reason'] ?? 'WGL probe returned not-ok',
                is_array($result) ? $result : []
            );
        }
        return BackendCapability::ok($result);
    }

    public function initialize(int $hwnd, int $w, int $h): void
    {
        if (!function_exists('sk_wgl_init')) {
            throw new BackendInitException($this->getName(), 'sk_wgl_init not linked');
        }
        try {
            sk_wgl_init($hwnd, $w, $h);
            $this->context = new \Px\Paint\SkiaRenderContext($hwnd, $w, $h);
        } catch (\Throwable $e) {
            throw new BackendInitException($this->getName(), $e->getMessage());
        }
    }

    public function getContext(): RenderContext
    {
        if ($this->context === null) {
            throw new BackendInitException($this->getName(), 'context not initialized');
        }
        return $this->context;
    }

    public function shutdown(): void
    {
        if (function_exists('sk_wgl_shutdown')) {
            try { sk_wgl_shutdown(); } catch (\Throwable $e) { /* ignore */ }
        }
        $this->context = null;
    }
}
