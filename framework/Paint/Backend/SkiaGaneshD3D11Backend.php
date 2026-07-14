<?php

namespace Px$1;

use native_types;
use Px\Paint\RenderContext;

/**
 * SkiaGaneshD3D11Backend 鈥?闃舵鍥涳細Skia Ganesh + Direct3D 11
 *
 * 鎺㈡祴鏉′欢锛?
 *  - sk_d3d11_probe() 瀛樺湪骞惰繑鍥?ok=true
 *  - C++ 绔?D3D11CreateDevice 鎴愬姛锛坒eature level >= 11_0锛?
 *
 * 鎬ц兘锛氱‖浠跺姞閫熸覆鏌?+ 闆舵嫹璐?SwapChain 鎻愪氦銆?
 * 浼樺厛绾э細90
 */
class SkiaGaneshD3D11Backend implements IRenderBackend
{
    private ?RenderContext $context = null;

    public function getName(): string
    {
        return 'skia-d3d11';
    }

    public static function getPriority(): int
    {
        return 90;
    }

    public function probe(): BackendCapability
    {
        // 闃舵鍥涙湭瀹炵幇锛氭帰娴嬪嚱鏁版湭閾炬帴
        if (!function_exists('sk_d3d11_probe')) {
            return BackendCapability::unavailable('sk_d3d11_probe not linked (D3D11 backend not compiled)');
        }

        try {
            $result = sk_d3d11_probe();
        } catch (\Throwable $e) {
            return BackendCapability::unavailable('sk_d3d11_probe threw: ' . $e->getMessage());
        }

        if (!is_array($result) || !($result['ok'] ?? false)) {
            return BackendCapability::unavailable(
                $result['reason'] ?? 'D3D11 probe returned not-ok',
                is_array($result) ? $result : []
            );
        }

        return BackendCapability::ok($result);
    }

    public function initialize(int $hwnd, int $w, int $h): void
    {
        if (!function_exists('sk_d3d11_init')) {
            throw new BackendInitException($this->getName(), 'sk_d3d11_init not linked');
        }
        try {
            sk_d3d11_init($hwnd, $w, $h);
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
        if (function_exists('sk_d3d11_shutdown')) {
            try { sk_d3d11_shutdown(); } catch (\Throwable $e) { /* ignore */ }
        }
        $this->context = null;
    }
}

