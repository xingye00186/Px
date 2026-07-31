<?php

namespace Px\Text;

use native_types;
use Px\Paint\RenderContext;

/**
 * ResilientTextBackendProxy — 文本引擎故障降级代理
 *
 * 包裹一个 RenderContext，对 PaintPipeline 透明。
 *
 * 工作原理：
 *  - 非 drawText 方法直接委派给 delegate
 *  - drawText 调用捕获错误，连续失败 N 次 → 触发降级
 *  - 降级后用新引擎重试当前调用
 *
 * 这样上层（PaintPipeline）完全无感知：
 *  - 启动时由 TextBackendSelector 选第一个可用的
 *  - 运行时引擎故障时自动切换到下一个
 */
class ResilientTextBackendProxy extends RenderContext
{
    private RenderContext $delegate;
    private TextBackendSelector $selector;

    /** @var array<string,int> 后端名 → 连续失败次数 */
    private array $failures = [];

    private int $failureThreshold = 3;

    public function __construct(
        RenderContext $delegate
    ) {
        // 确保文本后端已初始化（由渲染层管理）
        TextBackendRegistry::initialize();
        $this->selector = TextBackendRegistry::getSelector() ?? new TextBackendSelector();
        $this->delegate = $delegate;
    }

    // ── 委派方法（直接透传）──

    public function beginFrame(): void
    {
        $this->delegate->beginFrame();
    }

    public function endFrame(): void
    {
        $this->delegate->endFrame();
    }

    public function drawElement(array $el): void
    {
        $this->delegate->drawElement($el);
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        $this->delegate->fillRect($x, $y, $w, $h, $color);
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        $this->delegate->drawButton($x, $y, $w, $h, $bg, $border);
    }

    // ── drawText 带故障检测 ──

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void
    {
        $current = $this->selector->getCurrent();
        $name    = $current !== null ? $current->getName() : 'unknown';

        // AOT 兼容：直接传递调用
        try {
            $this->delegate->drawText($x, $y, $text, $fontSize, $color, $bold, $fontFamily);
            // 成功 → 重置失败计数
            $this->failures[$name] = 0;
        } catch (\Throwable $e) {
            $this->failures[$name] = ($this->failures[$name] ?? 0) + 1;
            trigger_error(
                "[Px] TextBackend {$name} drawText failed ({$this->failures[$name]}/{$this->failureThreshold}): {$e->getMessage()}",
                E_USER_WARNING
            );

            if ($this->failures[$name] >= $this->failureThreshold) {
                $this->downgrade($name, $e);
                // 用新后端重试当前调用
                $this->drawText($x, $y, $text, $fontSize, $color, $bold, $fontFamily);
            }
        }
    }

    /**
     * 触发降级：关闭当前后端，选下一个
     */
    private function downgrade(string $failedName, \Throwable $cause): void
    {
        trigger_error(
            "[Px] TextBackend downgrading from {$failedName} (cause: {$cause->getMessage()})",
            E_USER_WARNING
        );

        // 通知 selector 失败
        $current = $this->selector->getCurrent();
        if ($current !== null) {
            $this->selector->markFailed($current);
        }

        // 选下一个
        $next = $this->selector->selectNext();
        if ($next !== null) {
            $this->delegate = new ResilientTextBackendProxy($this->selector, $this->delegate);
            // 注：实际降级只需切换 C++ 引擎（已由 selectNext() 内的 activate() 完成）
            // 不需要替换 RenderContext，因为 drawText 方法在 C++ 层统一由 sk_draw_text 处理
        }
        $this->failures[$failedName] = 0;
    }
}
