<?php

namespace Px\Text;

use native_types;
use Px\Paint\Backend\BackendCapability;

/**
 * TextBackendSelector — 运行时文本后端选择器
 *
 * 流程：
 *  1. 收集候选（按优先级或用户强制）
 *  2. 依次 probe()，跳过不可用的
 *  3. 第一个 probe() 通过 → activate() → 选定
 *  4. 全部失败 → 返回 null（由 GDI 永远可用兜底）
 *
 * 同时维护失败列表，支持 ResilientTextBackendProxy 的运行时降级。
 */
class TextBackendSelector
{
    /** @var string[] 已失败并跳过的后端类名（按尝试顺序） */
    private array $failed = [];

    /** @var string[] 探测不可用的后端名 => 原因 */
    private array $unhealthy = [];

    private ?ITextBackend $current = null;

    /**
     * 第一次选择（应用启动时调用）
     *
     * @return ITextBackend|null 选中的后端，全部不可用时返回 null
     */
    public function select(): ?ITextBackend
    {
        $candidates = $this->collectCandidates();
        $verbose    = TextBackendRegistry::isVerbose();

        if ($verbose) {
            trigger_error('[Px] TextBackend probe: ' . count($candidates) . ' candidates', E_USER_NOTICE);
        }

        foreach ($candidates as $cls) {
            /** @var ITextBackend $backend */
            $backend = $this->instantiateTextBackend($cls);
            $name    = $backend->getName();
            $pri     = $cls::getPriority();

            $cap = $backend->probe();
            if (!$cap->available) {
                if ($verbose) {
                    trigger_error("[Px] [SKIP] text-{$name} pri={$pri} : {$cap->reason}", E_USER_NOTICE);
                }
                $this->unhealthy[$name] = $cap->reason;
                continue;
            }

            if ($verbose) {
                trigger_error("[Px] [OK ] text-{$name} pri={$pri}", E_USER_NOTICE);
            }

            // 探测通过 → 激活
            $backend->activate();
            $this->current = $backend;
            error_log("[Px] TextBackend selected: {$name}");
            return $backend;
        }

        error_log('[Px] No text backend available, falling back to C++ internal default');
        return null;
    }

    /**
     * 实例化文本后端（避免 new $cls() 动态类名 ZendVM dispatch）。
     */
    private function instantiateTextBackend(string $cls): ITextBackend
    {
        if ($cls === \Px\Rendering\TextBackend\DWriteTextBackend::class) return new \Px\Rendering\TextBackend\DWriteTextBackend();
        if ($cls === \Px\Rendering\TextBackend\SkiaTextBackend::class) return new \Px\Rendering\TextBackend\SkiaTextBackend();
        if ($cls === \Px\Rendering\TextBackend\GdiTextBackend::class) return new \Px\Rendering\TextBackend\GdiTextBackend();
        throw new \InvalidArgumentException("Unknown text backend class: {$cls}");
    }

    /**
     * 运行时降级：当前后端连续失败时调用
     * 选下一个可用的后端，激活并切换
     *
     * @return ITextBackend|null 新的后端
     */
    public function selectNext(): ?ITextBackend
    {
        $candidates  = $this->collectCandidates();
        $currentName = $this->current?->getName() ?? '';

        foreach ($candidates as $cls) {
            $instance     = $this->instantiateTextBackend($cls);
            $instanceName = $instance->getName();

            // 跳过当前 + 已失败 + 不健康
            if ($instanceName === $currentName) continue;
            if (in_array($instanceName, $this->failed, true)) continue;
            if (isset($this->unhealthy[$instanceName])) continue;

            // 重新探测
            $cap = $instance->probe();
            if (!$cap->available) {
                $this->unhealthy[$instanceName] = $cap->reason;
                continue;
            }

            $instance->activate();
            $this->current = $instance;
            trigger_error("[Px] TextBackend downgraded to {$instanceName}", E_USER_NOTICE);
            return $instance;
        }

        error_log('[Px] No more text backends for downgrade');
        return null;
    }

    /**
     * 标记当前后端失败（供 ResilientTextBackendProxy 触发降级时调用）
     */
    public function markFailed(ITextBackend $backend): void
    {
        $name = $backend->getName();
        if (!in_array($name, $this->failed, true)) {
            $this->failed[] = $name;
        }
        if ($this->current === $backend) {
            $this->current = null;
        }
    }

    public function getCurrent(): ?ITextBackend
    {
        return $this->current;
    }

    /**
     * 收集候选后端（考虑强制覆盖）
     *
     * @return string[] 类名数组
     */
    private function collectCandidates(): array
    {
        $forced = TextBackendRegistry::getForcedBackend();
        $all    = TextBackendRegistry::getCandidatesSorted();

        if ($forced === '') {
            return $all;
        }

        // 强制模式：只保留匹配的后端
        $filtered = [];
        foreach ($all as $cls) {
            $instance = $this->instantiateTextBackend($cls);
            if ($instance->getName() === $forced) {
                $filtered[] = $cls;
            }
        }

        if (empty($filtered)) {
            trigger_error("[Px] Forced text backend '{$forced}' not found in registry, using auto-select", E_USER_WARNING);
            return $all;
        }

        trigger_error("[Px] Forced text backend: {$forced}", E_USER_NOTICE);
        return $filtered;
    }
}
