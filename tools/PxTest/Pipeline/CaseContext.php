<?php

namespace PxTest\Pipeline;

/**
 * Case 级上下文 — 每 case 新建，持有该 case 的独立数据。
 * 通过 pipeline() 访问跨 case 持久化的 PipelineContext。
 */
class CaseContext
{
    /** @var array<string, mixed> */
    private array $data = [];

    private PipelineContext $pipelineCtx;

    public function __construct(PipelineContext $pipelineCtx)
    {
        $this->pipelineCtx = $pipelineCtx;
    }

    /** 设置 case 级数据 */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /** 读取 case 级数据 */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** 检查 case 级 key 是否存在 */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** 访问 pipeline 级上下文（跨 case 持久） */
    public function pipeline(): PipelineContext
    {
        return $this->pipelineCtx;
    }
}
