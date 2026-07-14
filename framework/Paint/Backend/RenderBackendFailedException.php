<?php

namespace Px$1;

use native_types;

/**
 * RenderBackendFailedException 鈥?娓叉煋鍚庣杩愯鏃跺け璐?
 *
 * 鐢?RenderContext 瀛愮被鍦ㄨ繍琛屾椂鎶涘嚭锛圙PU 璁惧涓㈠け銆佹樉瀛樹笉瓒崇瓑锛夈€?
 * ResilientRenderContext 鎹曡幏鍚庤鏁帮紝杩炵画 N 娆¤Е鍙戦檷绾с€?
 */
class RenderBackendFailedException extends \RuntimeException
{
    public string $backendName;

    public function __construct(string $backendName, string $message)
    {
        parent::__construct("[{$backendName}] {$message}");
        $this->backendName = $backendName;
    }
}

