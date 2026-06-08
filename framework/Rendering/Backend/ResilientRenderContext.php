<?php

namespace Px\Rendering\Backend;

use native_types;
use Px\Rendering\RenderContext;

/**
 * ResilientRenderContext — 故障降级代理
 *
 * 包裹一个 RenderContext，对 VNodeRenderer 透明。
 *
 * 工作原理：
 *  - 默认所有方法委派给 delegate
 *  - delegate 抛 RenderBackendFailedException 时计数
 *  - 同一后端连续失败 N 次 → 触发降级（selectNext）
 *  - 降级后用新 delegate 重试当前调用
 *
 * 这样上层（VNodeRenderer）完全无感知：
 *  - 启动时由 RuntimeBackendSelector 选第一个可用的
 *  - 运行时挂掉时自动切到下一个
 *  - 用户无感，UI 不崩
 */
class ResilientRenderContext extends RenderContext
{
    private RenderContext $delegate;
    private RuntimeBackendSelector $selector;
    private int $hwnd;
    private int $w;
    private int $h;

    /** @var array<string,int> 后端名 → 连续失败次数 */
    private array $failures = [];

    private int $failureThreshold = 3;

    public function __construct(
        RuntimeBackendSelector $selector,
        RenderContext $delegate,
        int $hwnd,
        int $w,
        int $h
    ) {
        $this->selector = $selector;
        $this->delegate = $delegate;
        $this->hwnd     = $hwnd;
        $this->w        = $w;
        $this->h        = $h;
    }

    public function getDelegateName(): string
    {
        return $this->delegate::class;
    }

    public function beginFrame(): void
    {
        $this->safeCall('beginFrame', []);
    }

    public function endFrame(): void
    {
        $this->safeCall('endFrame', []);
    }

    public function drawElement(array $el): void
    {
        $this->safeCall('drawElement', [$el]);
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        $this->safeCall('fillRect', [$x, $y, $w, $h, $color]);
    }

    public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold, string $fontFamily = ''): void
    {
        $this->safeCall('drawText', [$x, $y, $text, $fontSize, $color, $bold, $fontFamily]);
    }

    public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void
    {
        $this->safeCall('drawButton', [$x, $y, $w, $h, $bg, $border]);
    }

    /**
     * 安全调用 delegate 方法，失败计数 + 触发降级
     *
     * @param string $method
     * @param array<int,mixed> $args
     */
    private function safeCall(string $method, array $args): void
    {
        $name = $this->delegate::class;
        try {
            // AOT 兼容：用 match/switch 而非 $this->delegate->$method(...)
            switch ($method) {
                case 'beginFrame':
                    $this->delegate->beginFrame();
                    break;
                case 'endFrame':
                    $this->delegate->endFrame();
                    break;
                case 'drawElement':
                    $this->delegate->drawElement($args[0]);
                    break;
                case 'fillRect':
                    $this->delegate->fillRect($args[0], $args[1], $args[2], $args[3], $args[4]);
                    break;
                case 'drawText':
                    $this->delegate->drawText($args[0], $args[1], $args[2], $args[3], $args[4], $args[5], $args[6] ?? '');
                    break;
                case 'drawButton':
                    $this->delegate->drawButton($args[0], $args[1], $args[2], $args[3], $args[4], $args[5]);
                    break;
            }
            $this->failures[$name] = 0;  // 成功 → 重置
        } catch (RenderBackendFailedException $e) {
            $this->failures[$name] = ($this->failures[$name] ?? 0) + 1;
            trigger_error(
                "[Px] Backend {$name} {$method} failed ({$this->failures[$name]}/{$this->failureThreshold}): {$e->getMessage()}",
                E_USER_WARNING
            );

            if ($this->failures[$name] >= $this->failureThreshold) {
                $this->downgrade($name, $e);
                // 用新后端重试当前调用
                $this->safeCall($method, $args);
            }
        }
    }

    /**
     * 触发降级：关闭当前后端，选下一个
     */
    private function downgrade(string $failedName, \Throwable $cause): void
    {
        trigger_error(
            "[Px] Downgrading from {$failedName} (cause: {$cause->getMessage()})",
            E_USER_WARNING
        );

        // 通知 selector 失败
        $current = $this->selector->getCurrent();
        if ($current !== null) {
            $this->selector->markFailed($current);
        }

        // 选下一个
        $next = $this->selector->selectNext($this->hwnd, $this->w, $this->h);
        $this->delegate = $next->getContext();
        $this->failures[$failedName] = 0;
    }
}
