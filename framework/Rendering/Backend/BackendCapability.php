<?php

namespace Px\Rendering\Backend;

use native_types;

/**
 * BackendCapability — 后端探测结果值对象
 *
 * 由 IRenderBackend::probe() 返回，描述：
 *  - 是否可用
 *  - 不可用时的原因
 *  - 探测详情（用于 verbose 日志）
 *
 * 配合 RuntimeBackendSelector 在选择阶段快速判断。
 */
class BackendCapability
{
    public bool $available;
    public string $reason;
    /** @var array<string,mixed> 探测详情（如 feature level / device name） */
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
