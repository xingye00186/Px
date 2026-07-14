<?php

namespace Px$1;

use native_types;

/**
 * RuntimeBackendSelector 鈥?杩愯鏃跺悗绔€夋嫨鍣?
 *
 * 娴佺▼锛?
 *  1. 鏀堕泦鍊欓€夛紙鎸変紭鍏堢骇鎴栫敤鎴峰己鍒讹級
 *  2. 渚濇 probe()锛岃烦杩囦笉鍙敤鐨?
 *  3. 绗竴娆?initialize() 鎴愬姛 鈫?閫夊畾
 *  4. 鍏ㄩ儴澶辫触 鈫?鎶涘紓甯?
 *
 * 鍚屾椂缁存姢澶辫触鍒楄〃锛屾敮鎸?ResilientRenderContext 鐨勮繍琛屾椂闄嶇骇銆?
 */
class RuntimeBackendSelector
{
    /** @var string[] 宸插け璐ュ苟璺宠繃鐨勫悗绔被鍚嶏紙鎸夊皾璇曢『搴忥級 */
    private array $failed = [];

    /** @var string[] 鎺㈡祴澶辫触鐨勫悗绔紙涓嶅仴搴凤紝璺宠繃锛?*/
    private array $unhealthy = [];

    private ?IRenderBackend $current = null;

    public function __construct()
    {
    }

    /**
     * 绗竴娆￠€夋嫨锛堝簲鐢ㄥ惎鍔ㄦ椂璋冪敤锛?
     *
     * @return IRenderBackend 閫変腑鐨勫悗绔?
     * @throws \RuntimeException 娌℃湁鍙敤鍚庣
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
            $backend = $this->instantiateBackend($cls);
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

            // 鎺㈡祴閫氳繃 鈫?灏濊瘯鍒濆鍖?
            try {
                $backend->initialize($hwnd, $w, $h);
                $this->current = $backend;
                error_log("[Px] Selected: {$name}");
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
     * 瀹炰緥鍖栧悗绔紙閬垮厤 new $cls() 鍔ㄦ€佺被鍚?ZendVM dispatch锛夈€?
     */
    private function instantiateBackend(string $cls): IRenderBackend
    {
        if ($cls === \Px\Rendering\Backend\SkiaGraphiteDawnBackend::class) return new \Px\Rendering\Backend\SkiaGraphiteDawnBackend();
        if ($cls === \Px\Rendering\Backend\SkiaGaneshD3D11Backend::class) return new \Px\Rendering\Backend\SkiaGaneshD3D11Backend();
        if ($cls === \Px\Rendering\Backend\SkiaGaneshWGLBackend::class) return new \Px\Rendering\Backend\SkiaGaneshWGLBackend();
        if ($cls === \Px\Rendering\Backend\SkiaCpuBackend::class) return new \Px\Rendering\Backend\SkiaCpuBackend();
        if ($cls === \Px\Rendering\Backend\GdiDirect2DBackend::class) return new \Px\Rendering\Backend\GdiDirect2DBackend();
        if ($cls === \Px\Rendering\Backend\GdiLegacyBackend::class) return new \Px\Rendering\Backend\GdiLegacyBackend();
        throw new \InvalidArgumentException("Unknown backend class: {$cls}");
    }

    /**
     * 杩愯鏃堕檷绾э細褰撳墠鍚庣杩炵画澶辫触鏃惰皟鐢?
     * 閫変笅涓€涓湭澶辫触鐨勫悗绔紝鍒濆鍖栧苟鍒囨崲
     *
     * @return IRenderBackend 鏂扮殑鍚庣
     * @throws \RuntimeException 娌℃湁鍙檷绾х殑鍚庣
     */
    public function selectNext(int $hwnd, int $w, int $h): IRenderBackend
    {
        $candidates = $this->collectCandidates();
        $currentName = $this->current?->getName() ?? '';

        foreach ($candidates as $cls) {
            $name = $cls::getPriority() . '';
            // 瀹炰緥鍖栦互鑾峰彇鍚嶅瓧
            $instance = $this->instantiateBackend($cls);
            $instanceName = $instance->getName();

            // 璺宠繃褰撳墠 + 宸插け璐?+ 涓嶅仴搴?
            if ($instanceName === $currentName) continue;
            if (in_array($instanceName, $this->failed, true)) continue;
            if (isset($this->unhealthy[$instanceName])) continue;

            // 閲嶆柊鎺㈡祴
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
     * 鏍囪褰撳墠鍚庣澶辫触锛堜緵 ResilientRenderContext 瑙﹀彂闄嶇骇鏃惰皟鐢級
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
     * 鏀堕泦鍊欓€夊悗绔紙鑰冭檻寮哄埗瑕嗙洊锛?
     *
     * @return string[] 绫诲悕鏁扮粍
     */
    private function collectCandidates(): array
    {
        $forced = BackendRegistry::getForcedBackend();
        $all    = BackendRegistry::getCandidatesSorted();

        if ($forced === '') {
            return $all;
        }

        // 寮哄埗锛氬彧淇濈暀鎸囧畾鍚庣
        $filtered = [];
        foreach ($all as $cls) {
            $instance = $this->instantiateBackend($cls);
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
     * 鏍煎紡鍖栨帰娴嬭鎯呬负鍗曡瀛楃涓?
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

