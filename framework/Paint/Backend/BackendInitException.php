<?php

namespace Px$1;

use native_types;

/**
 * BackendInitException 鈥?鍚庣鍒濆鍖栧け璐?
 *
 * RuntimeBackendSelector 鍦?initialize() 鎶涙寮傚父鏃讹紝
 * 鑷姩闄嶇骇鍒颁笅涓€涓€欓€夊悗绔€?
 */
class BackendInitException extends \RuntimeException
{
    public string $backendName;

    public function __construct(string $backendName, string $message)
    {
        parent::__construct("[{$backendName}] {$message}");
        $this->backendName = $backendName;
    }
}

