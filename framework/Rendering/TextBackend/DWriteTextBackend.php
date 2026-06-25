<?php

namespace Px\Rendering\TextBackend;

use native_types;
use Px\Rendering\Backend\BackendCapability;

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
        // 1. C++ 绑定存在性检测
        if (!function_exists('sk_draw_text')) {
            return BackendCapability::unavailable('sk_draw_text not linked (C++ bindings missing)');
        }

        // 2. DirectWrite 可用性检测：调用 sk_measure_text_height 间接验证
        // measureHeightDWrite 内部调用 ensureDWriteFactory()，失败返回 0
        $height = sk_measure_text_height(16, 0);
        if ($height <= 0) {
            return BackendCapability::unavailable('DirectWrite not available on this system');
        }

        return BackendCapability::ok(['dwrite_available' => true]);
    }

    public function activate(): void
    {
        if (function_exists('sk_set_text_engine')) {
            sk_set_text_engine($this->engineName);
        }
    }
}
