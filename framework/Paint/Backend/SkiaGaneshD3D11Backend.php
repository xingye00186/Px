<?php

namespace Px\Paint\Backend;

use native_types;
use Px\Paint\RenderContext;

/**
 * SkiaGaneshD3D11Backend — 阶段四：Skia Ganesh + Direct3D 11
 *
 * 探测条件：
 *  - sk_d3d11_probe() 存在并返回 ok=true
 *  - C++ 端 D3D11CreateDevice 成功（feature level >= 11_0）
 *
 * 性能：硬件加速渲染 + 零拷贝 SwapChain 提交。
 * 优先级：90
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
        // 阶段四未实现：探测函数未链接
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
        if (function_exists('sk_d3d11_shutdown')) {
            try { sk_d3d11_shutdown(); } catch (\Throwable $e) { /* ignore */ }
        }
        $this->context = null;
    }
}
