<?php

namespace Px$1;

use native_types;
use Px\Paint\RenderContext;

/**
 * SkiaGaneshWGLBackend 鈥?闃舵鍥涳細Skia Ganesh + WGL (Windows OpenGL)
 *
 * 鎺㈡祴鏉′欢锛?
 *  - sk_wgl_probe() 瀛樺湪骞惰繑鍥?ok=true
 *  - C++ 绔?wglCreateContext + wglMakeCurrent 鎴愬姛
 *
 * 鎬ц兘锛氱‖浠跺姞閫?OpenGL 娓叉煋銆?
 * 浼樺厛绾э細80
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
            $this->context = new \Px\Rendering\SkiaRenderContext($hwnd, $w, $h);
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

