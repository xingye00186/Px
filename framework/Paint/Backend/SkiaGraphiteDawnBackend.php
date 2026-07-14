<?php

namespace Px$1;

use native_types;
use Px\Paint\RenderContext;

/**
 * SkiaGraphiteDawnBackend 鈥?闃舵浜旓細Skia Graphite + Dawn (D3D12/WebGPU)
 *
 * 鎺㈡祴鏉′欢锛?
 *  - sk_dawn_probe() 瀛樺湪骞惰繑鍥?ok=true
 *  - C++ 绔?Dawn Device 鍒涘缓鎴愬姛锛圖3D12 backend锛?
 *
 * 鎬ц兘锛歋kia 鏈€鏂?GPU 鏋舵瀯锛屾€ц兘鏈€楂樸€?
 * 浼樺厛绾э細100锛堟渶楂橈級
 */
class SkiaGraphiteDawnBackend implements IRenderBackend
{
    private ?RenderContext $context = null;

    public function getName(): string
    {
        return 'skia-dawn';
    }

    public static function getPriority(): int
    {
        return 100;
    }

    public function probe(): BackendCapability
    {
        if (!function_exists('sk_dawn_probe')) {
            return BackendCapability::unavailable('sk_dawn_probe not linked (Dawn backend not compiled)');
        }
        try {
            $result = sk_dawn_probe();
        } catch (\Throwable $e) {
            return BackendCapability::unavailable('sk_dawn_probe threw: ' . $e->getMessage());
        }
        if (!is_array($result) || !($result['ok'] ?? false)) {
            return BackendCapability::unavailable(
                $result['reason'] ?? 'Dawn probe returned not-ok',
                is_array($result) ? $result : []
            );
        }
        return BackendCapability::ok($result);
    }

    public function initialize(int $hwnd, int $w, int $h): void
    {
        if (!function_exists('sk_dawn_init')) {
            throw new BackendInitException($this->getName(), 'sk_dawn_init not linked');
        }
        try {
            sk_dawn_init($hwnd, $w, $h);
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
        if (function_exists('sk_dawn_shutdown')) {
            try { sk_dawn_shutdown(); } catch (\Throwable $e) { /* ignore */ }
        }
        $this->context = null;
    }
}

