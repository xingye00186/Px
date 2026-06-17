<?php

namespace PxTest\Core;

/**
 * 测试套件聚合 — 包含一组 TestCaseInterface 实例。
 */
class TestSuite
{
    /** @var TestCaseInterface[] */
    private array $cases = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    public function __construct(
        public readonly string $name = '',
    ) {}

    public function addCase(TestCaseInterface $case): self
    {
        $this->cases[] = $case;
        return $this;
    }

    /** @return TestCaseInterface[] */
    public function getCases(): array
    {
        return $this->cases;
    }

    public function caseCount(): int
    {
        return count($this->cases);
    }

    public function setMeta(string $key, mixed $value): self
    {
        $this->meta[$key] = $value;
        return $this;
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}
