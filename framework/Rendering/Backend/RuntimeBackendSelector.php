<?php

namespace Px\Rendering\Backend;

use native_types;

/**
 * RuntimeBackendSelector — 运行时后端选择器
 *
 * 流程：
 *  1. 收集候选（按优先级或用户强制）
 *  2. 依次 probe()，跳过不可用的
 *  3. 第一次 initialize() 成功 → 选定
 *  4. 全部失败 → 抛异常
 *
 * 同时维护失败列表，支持 ResilientRenderContext 的运行时降级。
 */
class RuntimeBackendSelector
{
    /** @var string[] 已失败并跳过的后端类名（按尝试顺序） */
    private array $failed = [];

    /** @var string[] 探测失败的后端（不健康，跳过） */
    private array $unhealthy = [];

    private ?IRenderBackend $current = null;

    public function __construct()
    {
    }

    /**
     * 第一次选择（应用启动时调用）
     *
     * @return IRenderBackend 选中的后端
     * @throws \RuntimeException 没有可用后端
     */
    public function select(int $hwnd, int $w, int $h): IRenderBackend
    {
        $candidates = $this->collectCandidates();
        $verbose    = BackendRegistry::isVerbose();

        if ($verbose) {
            trigger_error('[Px] Renderer probe: ' . count($candidates) . ' candidates', E_USER_NOTICE);
        }

        foreach ($candidates as $cls) {
            /** @var IRenderBackend $backend */
            $backend = new $cls();
            $name    = $backend->getName();
            $pri     = $cls::getPriority();

            $cap = $backend->probe();
            if (!$cap->available) {
                if ($verbose) {
                    trigger_error("[Px] [SKIP] {$name} pri={$pri} : {$cap->reason}", E_USER_NOTICE);
                }
                $this->unhealthy[$name] = $cap->reason;
                continue;
            }

            if ($verbose) {
                $detailStr = $this->formatDetails($cap->details);
                trigger_error("[Px] [OK ] {$name} pri={$pri} {$detailStr}", E_USER_NOTICE);
            }

            // 探测通过 → 尝试初始化
            try {
                $backend->initialize($hwnd, $w, $h);
                $this->current = $backend;
                trigger_error("[Px] Selected: {$name}", E_USER_NOTICE);
                return $backend;
            } catch (BackendInitException $e) {
                if ($verbose) {
                    trigger_error("[Px] [FAIL] {$name} init: {$e->getMessage()}", E_USER_WARNING);
                }
                $this->failed[] = $name;
                continue;
            }
        }

        throw new \RuntimeException(
            'No render backend available. Tried: ' . implode(', ', $this->failed + array_keys($this->unhealthy))
        );
    }

    /**
     * 运行时降级：当前后端连续失败时调用
     * 选下一个未失败的后端，初始化并切换
     *
     * @return IRenderBackend 新的后端
     * @throws \RuntimeException 没有可降级的后端
     */
    public function selectNext(int $hwnd, int $w, int $h): IRenderBackend
    {
        $candidates = $this->collectCandidates();
        $currentName = $this->current?->getName() ?? '';

        foreach ($candidates as $cls) {
            $name = $cls::getPriority() . '';
            // 实例化以获取名字
            $instance = new $cls();
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

            try {
                $instance->initialize($hwnd, $w, $h);
                $this->current = $instance;
                trigger_error("[Px] Downgraded to {$instanceName}", E_USER_NOTICE);
                return $instance;
            } catch (BackendInitException $e) {
                $this->failed[] = $instanceName;
                continue;
            }
        }

        throw new \RuntimeException('No more backends for downgrade');
    }

    /**
     * 标记当前后端失败（供 ResilientRenderContext 触发降级时调用）
     */
    public function markFailed(IRenderBackend $backend): void
    {
        $name = $backend->getName();
        if (!in_array($name, $this->failed, true)) {
            $this->failed[] = $name;
        }
        if ($this->current === $backend) {
            $this->current = null;
        }
    }

    public function getCurrent(): ?IRenderBackend
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
        $forced = BackendRegistry::getForcedBackend();
        $all    = BackendRegistry::getCandidatesSorted();

        if ($forced === '') {
            return $all;
        }

        // 强制：只保留指定后端
        $filtered = [];
        foreach ($all as $cls) {
            $instance = new $cls();
            if ($instance->getName() === $forced) {
                $filtered[] = $cls;
            }
        }

        if (empty($filtered)) {
            throw new \RuntimeException("Forced backend '{$forced}' not found in registry");
        }

        trigger_error("[Px] Forced backend: {$forced}", E_USER_NOTICE);
        return $filtered;
    }

    /**
     * 格式化探测详情为单行字符串
     *
     * @param array<string,mixed> $details
     */
    private function formatDetails(array $details): string
    {
        if (empty($details)) return '';
        $parts = [];
        foreach ($details as $k => $v) {
            if (is_bool($v)) {
                $parts[] = $k . '=' . ($v ? 'true' : 'false');
            } else {
                $parts[] = $k . '=' . $v;
            }
        }
        return '(' . implode(', ', $parts) . ')';
    }
}
