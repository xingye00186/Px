<?php

namespace Px$1;

use native_types;
use Px\Paint\GdiRenderContext;
use Px\Paint\RenderContext;

/**
 * GdiLegacyBackend 鈥?闃舵涓€/浜岋細鍘熺敓 GDI 娓叉煋
 *
 * 姘歌繙鍙敤锛堝彧瑕?Windows + user32/gdi32 瀛樺湪锛夈€?
 * 鏃?GPU 鍔犻€燂紝鏃犳姉閿娇锛屼絾鍏煎鎬ф渶寮恒€?
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
        return 10;  // 鍏滃簳
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

