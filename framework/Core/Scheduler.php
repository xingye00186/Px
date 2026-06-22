<?php

namespace Px\Core;

use native_types;

/**
 * Scheduler — 微任务/宏任务调度器
 *
 * AOT 支持闭包 $task()，默认值传递参数。
 * 需要引用传递时使用 refval()。
 */
class Scheduler
{
    private array $microtasks = [];
    private array $macrotasks = [];

    private static ?Scheduler $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        $this->microtasks = [];
        $this->macrotasks = [];
    }

    public function addMicrotask(callable $task): void
    {
        $this->microtasks[] = $task;
    }

    public function addMacrotask(callable $task): void
    {
        $this->macrotasks[] = $task;
    }

    public function nextTick(callable $task): void
    {
        $this->addMicrotask($task);
    }

    public function flushMicrotasks(): void
    {
        while (!empty($this->microtasks)) {
            $task = objval(array_shift($this->microtasks), \Closure::class);
            ($task)();
        }
    }

    public function runOneMacrotask(): bool
    {
        if (empty($this->macrotasks)) {
            return false;
        }
        $task = objval(array_shift($this->macrotasks), \Closure::class);
        ($task)();
        return true;
    }

    public function tick(): void
    {
        $this->flushMicrotasks();
        $this->runOneMacrotask();
    }

    public function getMicrotaskCount(): int
    {
        return count($this->microtasks);
    }

    public function getMacrotaskCount(): int
    {
        return count($this->macrotasks);
    }

    public function clear(): void
    {
        $this->microtasks = [];
        $this->macrotasks = [];
    }
}