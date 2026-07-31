<?php

namespace Px\Platform;

use native_types;

/**
 * ViewMetrics — 视图度量（终极融合 P0.3）
 *
 * Framework 层消费的统一视图信息。桌面端安全区域恒 0；移动端由 Embedder
 * 从 OS 查询（Android WindowInsets / iOS UIEdgeInsets）后填入。
 *
 * platformName 仅供诊断/日志使用 —— **Framework 层禁止据此分支**
 * （铁律 1：框架层零平台分支）。平台差异应通过能力抽象表达，而非平台名判断。
 */
class ViewMetrics
{
    public int $width;
    public int $height;
    public int $dprPermille;

    public int $safeAreaTop;
    public int $safeAreaRight;
    public int $safeAreaBottom;
    public int $safeAreaLeft;

    public string $platformName;

    public function __construct(
        int $width,
        int $height,
        int $dprPermille = 1000,
        int $safeAreaTop = 0,
        int $safeAreaRight = 0,
        int $safeAreaBottom = 0,
        int $safeAreaLeft = 0,
        string $platformName = ''
    ) {
        $this->width          = $width;
        $this->height         = $height;
        $this->dprPermille    = $dprPermille;
        $this->safeAreaTop    = $safeAreaTop;
        $this->safeAreaRight  = $safeAreaRight;
        $this->safeAreaBottom = $safeAreaBottom;
        $this->safeAreaLeft   = $safeAreaLeft;
        $this->platformName   = $platformName;
    }

    // AOT getter
    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }
    public function getDprPermille(): int { return $this->dprPermille; }
    public function getSafeAreaTop(): int { return $this->safeAreaTop; }
    public function getSafeAreaRight(): int { return $this->safeAreaRight; }
    public function getSafeAreaBottom(): int { return $this->safeAreaBottom; }
    public function getSafeAreaLeft(): int { return $this->safeAreaLeft; }
    public function getPlatformName(): string { return $this->platformName; }
}
