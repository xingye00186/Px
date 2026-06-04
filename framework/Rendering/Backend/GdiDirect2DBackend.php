<?php

namespace Px\Rendering\Backend;

use native_types;
use Px\Rendering\GdiRenderContext;
use Px\Rendering\RenderContext;

/**
 * GdiDirect2DBackend — 阶段五：原生 GDI + Direct2D 加速
 *
 * 探测条件：
 *  - Windows 7+（自带 d2d1.dll）
 *  - 阶段五实现
 *
 * 优先级：50（介于 Skia-CPU 和 GDI-Legacy 之间）
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
        // 阶段五未实现
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
        // D2D 后端不实现时直接不可用
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
