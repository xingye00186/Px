<?php

namespace Px\Rendering\Backend;

use native_types;

/**
 * BackendCapability 鈥?鍚庣鎺㈡祴缁撴灉鍊煎璞? *
 * 鐢?IRenderBackend::probe() 杩斿洖锛屾弿杩帮細
 *  - 鏄惁鍙敤
 *  - 涓嶅彲鐢ㄦ椂鐨勫師鍥? *  - 鎺㈡祴璇︽儏锛堢敤浜?verbose 鏃ュ織锛? *
 * 閰嶅悎 RuntimeBackendSelector 鍦ㄩ€夋嫨闃舵蹇€熷垽鏂€? */
class BackendCapability
{
    public bool $available;
    public string $reason;
    /** @var array<string,mixed> 鎺㈡祴璇︽儏锛堝 feature level / device name锛?*/
    public array $details;

    public function __construct(bool $available, string $reason = '', array $details = [])
    {
        $this->available = $available;
        $this->reason    = $reason;
        $this->details   = $details;
    }

    public static function ok(array $details = []): self
    {
        return new self(true, '', $details);
    }

    public static function unavailable(string $reason, array $details = []): self
    {
        return new self(false, $reason, $details);
    }
}
