<?php

namespace Px\Paint\Backend;

use native_types;
use Px\Paint\SkiaRenderContext;
use Px\Paint\RenderContext;

/**
 * SkiaCpuBackend — 阶段三：Skia CPU 离屏 + GDI 桥接
 *
 * 探测条件：
 *  - sk_create_window_context() 函数存在（链接了 skia_render.cc + USE_SKIA）
 *  - skia_render.cc 已实现阶段三
 *
 * 抗锯齿 + 圆角 + 字体光栅化走 Skia，最终像素通过 SetDIBitsToDevice 输出到 GDI DC。
 */
class SkiaCpuBackend implements IRenderBackend
{
    private ?RenderContext $context = null;

    public function getName(): string
    {
        return 'skia-cpu';
    }

    public static function getPriority(): int
    {
        return 60;
    }

    public function probe(): BackendCapability
    {
        // 1. 符号检测：sk_* 系列函数是否链接进来
        if (!function_exists('sk_create_window_context')) {
            return BackendCapability::unavailable('sk_create_window_context not linked (USE_SKIA=0?)');
        }

        // 2. 平台检测：必须是 Windows
        if (PHP_OS_FAMILY !== 'Windows') {
            return BackendCapability::unavailable('Skia renderer requires Windows');
        }

        $details = [
            'skia_linked'     => true,
            'platform'        => PHP_OS,
            'php_version'     => PHP_VERSION,
        ];
        return BackendCapability::ok($details);
    }

    public function initialize(int $hwnd, int $w, int $h): void
    {
        try {
            $this->context = new SkiaRenderContext($hwnd, $w, $h);
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
        if ($this->context !== null) {
            // SkiaRenderContext 的 __destruct 调 sk_destroy_context
            $this->context = null;
        }
    }
}
