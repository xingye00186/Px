<?php

namespace Px\Paint\Backend;

use native_types;

use Px\Paint\RenderContext;

/**
 * CapturingRenderContext — 框架侧软件捕获后端
 *
 * P1.3 Surface 解耦（对标 Flutter）：光栅产物属于引擎，不属于 Embedder。
 * 无 C++ 绑定（PHP-only 测试 / headless）时由 Application::initRenderer
 * 构造，PaintPipeline 绘入本上下文，测试经
 * Application::getPaintPipeline()->getRenderContext() 从引擎侧读回——
 * 对应 Flutter 软件渲染路径中光栅器仍在 engine 侧、embedder 只提供
 * 像素回读机制的模型。
 *
 * 捕获语义与原 tests/css-standards 的 _CssCaptureRenderContext 逐字一致：
 * 仅捕获 drawElement（PaintPipeline 的唯一结构化出口），
 * fillRect/drawText/drawButton 为空操作——保证 336 快照零漂移。
 */
class CapturingRenderContext extends RenderContext
{
    /** @var array[] 本帧捕获的绘制元素（beginFrame 清空） */
    public array $drawnElements = [];

    public function beginFrame(): void
    {
        $this->drawnElements = [];
    }

    public function endFrame(): void {}

    public function drawElement(array $el): void
    {
        $this->drawnElements[] = $el;
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void {}

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}

    /** @return array[] 本帧捕获的绘制元素 */
    public function getDrawnElements(): array
    {
        return $this->drawnElements;
    }
}
