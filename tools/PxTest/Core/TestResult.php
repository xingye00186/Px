<?php

namespace PxTest\Core;

/**
 * 结构化测试结果值对象。
 */
class TestResult
{
    public const STATUS_PASS = 'PASS';
    public const STATUS_FAIL = 'FAIL';
    public const STATUS_SKIP = 'SKIP';
    public const STATUS_ERROR = 'ERROR';

    /** @param array<string, mixed> $details 额外诊断信息 */
    public function __construct(
        public readonly string $status,
        public readonly string $name = '',
        public readonly float  $durationMs = 0.0,
        public readonly array  $details = [],
    ) {}

    public function isPassed(): bool
    {
        return $this->status === self::STATUS_PASS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAIL || $this->status === self::STATUS_ERROR;
    }

    public static function pass(string $name = '', float $durationMs = 0.0, array $details = []): self
    {
        return new self(self::STATUS_PASS, $name, $durationMs, $details);
    }

    public static function fail(string $name = '', string $reason = '', float $durationMs = 0.0): self
    {
        return new self(self::STATUS_FAIL, $name, $durationMs, ['reason' => $reason]);
    }

    public static function skip(string $name = '', string $reason = ''): self
    {
        return new self(self::STATUS_SKIP, $name, 0.0, ['reason' => $reason]);
    }

    public static function error(string $name = '', string $message = '', float $durationMs = 0.0): self
    {
        return new self(self::STATUS_ERROR, $name, $durationMs, ['error' => $message]);
    }
}
