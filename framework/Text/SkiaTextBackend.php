<?php

namespace Px\Text;

use native_types;
use Px\Paint\Backend\BackendCapability;

/**
 * SkiaTextBackend — Skia 文本后端
 *
 * 优先级 20，在 DWrite 不可用时的回退选项。
 * 使用 Skia 的 drawString() 绘制文本。
 */
class SkiaTextBackend implements ITextBackend
{
    private string $engineName = 'skia';

    public function getName(): string
    {
        return $this->engineName;
    }

    public static function getPriority(): int
    {
        return 20;
    }

    public function probe(): BackendCapability
    {
        // USE_SKIA 为 C++ 编译宏，通过 sk_create_window_context 间接检测
        // 该函数受 #ifdef USE_SKIA 守卫，不存在即表示未启用 Skia
        if (!function_exists('sk_create_window_context')) {
            return BackendCapability::unavailable('USE_SKIA not enabled (sk_create_window_context missing)');
        }

        return BackendCapability::ok(['skia_linked' => true]);
    }

    public function activate(): void
    {
        if (function_exists('sk_set_text_engine')) {
            sk_set_text_engine($this->engineName);
        }
    }
}
