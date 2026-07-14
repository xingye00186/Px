<?php

namespace Px$1;

use native_types;
use Px\Paint\SkiaRenderContext;
use Px\Paint\RenderContext;

/**
 * SkiaCpuBackend 鈥?闃舵涓夛細Skia CPU 绂诲睆 + GDI 妗ユ帴
 *
 * 鎺㈡祴鏉′欢锛?
 *  - sk_create_window_context() 鍑芥暟瀛樺湪锛堥摼鎺ヤ簡 skia_render.cc + USE_SKIA锛?
 *  - skia_render.cc 宸插疄鐜伴樁娈典笁
 *
 * 鎶楅敮榻?+ 鍦嗚 + 瀛椾綋鍏夋爡鍖栬蛋 Skia锛屾渶缁堝儚绱犻€氳繃 SetDIBitsToDevice 杈撳嚭鍒?GDI DC銆?
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
        // 1. 绗﹀彿妫€娴嬶細sk_* 绯诲垪鍑芥暟鏄惁閾炬帴杩涙潵
        if (!function_exists('sk_create_window_context')) {
            return BackendCapability::unavailable('sk_create_window_context not linked (USE_SKIA=0?)');
        }

        // 2. 骞冲彴妫€娴嬶細蹇呴』鏄?Windows
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
            // SkiaRenderContext 鐨?__destruct 璋?sk_destroy_context
            $this->context = null;
        }
    }
}

