<?php

namespace PxTest\Pipeline;

/**
 * 步骤执行结果值对象。
 */
class StepResult
{
    /** @param string[] $errors */
    public function __construct(
        public readonly string $stepName,
        public readonly bool   $passed,
        public readonly array  $errors = [],
        public readonly float  $durationMs = 0.0,
    ) {}

    public static function ok(string $name, float $durationMs = 0.0): self
    {
        return new self($name, true, [], $durationMs);
    }

    public static function err(string $name, string $error, float $durationMs = 0.0): self
    {
        return new self($name, false, [$error], $durationMs);
    }
}
