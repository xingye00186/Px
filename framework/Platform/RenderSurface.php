<?php

namespace Px\Platform;

use native_types;

/**
 * RenderSurface — 可渲染表面抽象（终极融合 P0.3）
 *
 * Framework 层与平台世界的边界对象之一。取代 Platform::getHwnd()：
 * 原生句柄的**平台含义**被封在 Embedder 内部，Framework 只看到"一个表面"。
 *
 *   Win32   → handle = HWND
 *   Android → handle = ANativeWindow*
 *   iOS     → handle = CAMetalLayer*
 *   离屏/headless → handle = 0
 *
 * DPR 用整数千分比而非 float：引擎数值代码禁用浮点中间值（整数确定性算术
 * 契约），DPR 最终参与布局缩放，float 会在 PHP CLI 与 AOT 之间引入分叉。
 * 千分比无损覆盖全部现实档位（1.0/1.25/1.5/1.75/2.0/3.0）。
 */
class RenderSurface
{
    public int $handle;
    public int $width;
    public int $height;
    public int $dprPermille;

    public function __construct(int $handle, int $width, int $height, int $dprPermille = 1000)
    {
        $this->handle      = $handle;
        $this->width       = $width;
        $this->height      = $height;
        $this->dprPermille = $dprPermille;
    }

    // AOT getter：跨类访问必须走 getter（native_types readonly 约束）
    public function getHandle(): int { return $this->handle; }
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }
    public function getDprPermille(): int { return $this->dprPermille; }

    /** 无原生窗口（headless / 离屏渲染） */
    public function isOffscreen(): bool { return $this->handle === 0; }
}
