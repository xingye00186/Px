<?php

namespace Px\Rendering\TextBackend;

use native_types;
use Px\Rendering\Backend\BackendCapability;

/**
 * GdiTextBackend — GDI 文本后端
 *
 * 优先级 10，永远可用的最终回退。
 * 使用 Windows GDI TextOutW/DrawTextW 绘制文本。
 */
class GdiTextBackend implements ITextBackend
{
    private string $engineName = 'gdi';

    public function getName(): string
    {
        return $this->engineName;
    }

    public static function getPriority(): int
    {
        return 10;
    }

    public function probe(): BackendCapability
    {
        // GDI 在 Windows 上永远可用
        return BackendCapability::ok(['always_available' => true]);
    }

    public function activate(): void
    {
        if (function_exists('sk_set_text_engine')) {
            sk_set_text_engine($this->engineName);
        }
    }
}
