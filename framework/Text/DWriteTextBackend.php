<?php

namespace Px\Text;

use native_types;
use Px\Paint\Backend\BackendCapability;

/**
 * DWriteTextBackend — DirectWrite 文本后端
 *
 * 优先级 30，Windows 默认文本引擎。
 * 使用 IDWriteBitmapRenderTarget 真正用 DWrite 绘制文本。
 */
class DWriteTextBackend implements ITextBackend
{
    private string $engineName = 'dwrite';

    public function getName(): string
    {
        return $this->engineName;
    }

    public static function getPriority(): int
    {
        return 30;
    }

    public function probe(): BackendCapability
    {
        // SDK 10.0.26100.0 中 IDWriteBitmapRenderTarget::DrawGlyphRun 的
        // COLORREF textColor 参数被忽略，文字始终渲染为黑色。
        // 临时禁用 DWrite 后端，回退到 GDI 文本渲染。
        return BackendCapability::unavailable('DrawGlyphRun textColor broken in SDK 26100');
    }

    public function activate(): void
    {
        if (function_exists('sk_set_text_engine')) {
            sk_set_text_engine($this->engineName);
        }
    }
}
