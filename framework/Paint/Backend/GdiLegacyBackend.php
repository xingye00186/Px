<?php

namespace Px\Paint\Backend;

use native_types;
use Px\Paint\GdiRenderContext;
use Px\Paint\RenderContext;

/**
 * GdiLegacyBackend — 阶段一/二：原生 GDI 渲染
 *
 * 永远可用（只要 Windows + user32/gdi32 存在）。
 * 无 GPU 加速，无抗锯齿，但兼容性最强。
 */
class GdiLegacyBackend implements IRenderBackend
{
    private ?RenderContext $context = null;

    public function getName(): string
    {
        return 'gdi-legacy';
    }

    public static function getPriority(): int
    {
        return 10;  // 兜底
    }

    public function probe(): BackendCapability
    {
        return BackendCapability::ok(['always_available' => true]);
    }

    public function initialize(int $hwnd, int $w, int $h): void
    {
        $this->context = new GdiRenderContext($hwnd);
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
        $this->context = null;
    }
}
