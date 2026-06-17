<?php

namespace PxTest;

/**
 * 内存泄漏感知测试基类。
 *
 * 在 tearDown 中自动断言内存增量 < 1MB。
 * 所有集成测试继承此基类，自动捕获资源泄漏。
 *
 * Usage:
 *   class MyIntegrationTest extends MemoryLeakAwareTestCase
 *   {
 *       public function testRenderLoop(): void
 *       {
 *           for ($i = 0; $i < 100; $i++) {
 *               $this->app->render();
 *           }
 *       }
 *   }
 */
abstract class MemoryLeakAwareTestCase
{
    private int $baseMemory;
    private bool $memoryCheckEnabled = true;

    /** 内存增长阈值 (bytes) */
    protected int $memoryThreshold = 1_048_576; // 1MB

    protected function setUp(): void
    {
        $this->baseMemory = memory_get_peak_usage(true);
    }

    protected function tearDown(): void
    {
        if (!$this->memoryCheckEnabled) {
            return;
        }

        $current = memory_get_peak_usage(true);
        $delta = $current - $this->baseMemory;

        if ($delta > $this->memoryThreshold) {
            $kb = round($delta / 1024, 1);
            $msg = "Memory leak detected: {$kb} KB over baseline (threshold: "
                 . round($this->memoryThreshold / 1024, 1) . " KB)";
            $this->fail($msg);
        }
    }

    /** 临时禁用内存检查（用于预期内存增长的测试） */
    protected function disableMemoryCheck(): void
    {
        $this->memoryCheckEnabled = false;
    }

    /** 设置自定义内存阈值 */
    protected function setMemoryThreshold(int $bytes): void
    {
        $this->memoryThreshold = $bytes;
    }

    /** PHPUnit 兼容的 fail 方法 */
    protected function fail(string $message = ''): void
    {
        if (class_exists('PHPUnit\Framework\Assert')) {
            \PHPUnit\Framework\Assert::fail($message);
        } else {
            throw new \RuntimeException($message);
        }
    }
}
