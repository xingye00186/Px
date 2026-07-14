<?php

namespace Px$1;

use native_types;
use Px\Paint\GdiRenderContext;
use Px\Paint\RenderContext;

/**
 * GdiDirect2DBackend 鈥?闃舵浜旓細鍘熺敓 GDI + Direct2D 鍔犻€?
 *
 * 鎺㈡祴鏉′欢锛?
 *  - Windows 7+锛堣嚜甯?d2d1.dll锛?
 *  - 闃舵浜斿疄鐜?
 *
 * 浼樺厛绾э細50锛堜粙浜?Skia-CPU 鍜?GDI-Legacy 涔嬮棿锛?
 */
class GdiDirect2DBackend implements IRenderBackend
{
    private ?RenderContext $context = null;

    public function getName(): string
    {
        return 'gdi-d2d';
    }

    public static function getPriority(): int
    {
        return 50;
    }

    public function probe(): BackendCapability
    {
        // 闃舵浜旀湭瀹炵幇
        if (!function_exists('d2d_probe')) {
            return BackendCapability::unavailable('d2d_probe not linked (D2D backend not compiled)');
        }
        try {
            $result = d2d_probe();
        } catch (\Throwable $e) {
            return BackendCapability::unavailable('d2d_probe threw: ' . $e->getMessage());
        }
        if (!is_array($result) || !($result['ok'] ?? false)) {
            return BackendCapability::unavailable(
                $result['reason'] ?? 'D2D probe returned not-ok',
                is_array($result) ? $result : []
            );
        }
        return BackendCapability::ok($result);
    }

    public function initialize(int $hwnd, int $w, int $h): void
    {
        // D2D 鍚庣涓嶅疄鐜版椂鐩存帴涓嶅彲鐢?
        throw new BackendInitException($this->getName(), 'D2D backend not implemented yet');
    }

    public function getContext(): RenderContext
    {
        throw new BackendInitException($this->getName(), 'D2D backend not implemented yet');
    }

    public function shutdown(): void
    {
        $this->context = null;
    }
}

