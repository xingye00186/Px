<?php

namespace PxTest\Mock;

use Px\Rendering\RenderContext;

/**
 * 可捕获绘制调用的 RenderContext 实现。
 *
 * 所有 drawElement/fillRect/drawText/drawButton 调用被记录到 $drawnElements 中，
 * 用于测试验证渲染管线输出的正确性。
 */
class MockRenderContext extends RenderContext
{
    /** @var array<int, array<string, mixed>> */
    public array $drawnElements = [];

    /** @var array<int, array<string, mixed>> */
    public array $fillRects = [];

    /** @var array<int, array<string, mixed>> */
    public array $texts = [];

    /** @var array<int, array<string, mixed>> */
    public array $buttons = [];

    public function beginFrame(): void
    {
        $this->drawnElements = [];
        $this->fillRects = [];
        $this->texts = [];
        $this->buttons = [];
    }

    public function endFrame(): void {}

    public function drawElement(array $el): void
    {
        $this->drawnElements[] = $el;
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        $this->fillRects[] = compact('x', 'y', 'w', 'h', 'color');
    }

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void
    {
        $this->texts[] = compact('x', 'y', 'text', 'fontSize', 'color', 'bold', 'fontFamily');
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        $this->buttons[] = compact('x', 'y', 'w', 'h', 'bg', 'border');
    }

    /** 检查是否绘制了特定文本 */
    public function hasDrawnText(string $text): bool
    {
        foreach ($this->texts as $t) {
            if (($t['text'] ?? '') === $text) {
                return true;
            }
        }
        return false;
    }

    /** 检查是否绘制了特定类型的元素 */
    public function hasDrawnElement(string $type): bool
    {
        foreach ($this->drawnElements as $el) {
            if (($el['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    /** 获取所有绘制文本的内容列表 */
    public function getTextContents(): array
    {
        return array_map(fn(array $t): string => $t['text'] ?? '', $this->texts);
    }
}
