<?php

namespace Px\Core;

/**
 * ReactionBus — 组件事件总线
 *
 * AOT 支持闭包 $callback($event)，默认值传递。
 */
class ReactionBus
{
    private array $listeners = [];
    private int $nextId = 1;

    private static ?ReactionBus $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function on(string $eventType, callable $callback, int $priority = 0): int
    {
        $id = $this->nextId++;
        if (!isset($this->listeners[$eventType])) {
            $this->listeners[$eventType] = [];
        }
        $this->listeners[$eventType][] = [$id, $callback, false, $priority];
        $this->sortByPriority($eventType);
        return $id;
    }

    public function once(string $eventType, callable $callback, int $priority = 0): int
    {
        $id = $this->nextId++;
        if (!isset($this->listeners[$eventType])) {
            $this->listeners[$eventType] = [];
        }
        $this->listeners[$eventType][] = [$id, $callback, true, $priority];
        $this->sortByPriority($eventType);
        return $id;
    }

    private function sortByPriority(string $eventType): void
    {
        usort($this->listeners[$eventType], fn($a, $b) => $b[3] - $a[3]);
    }

    public function off(int $listenerId): void
    {
        foreach ($this->listeners as $type => $list) {
            foreach ($list as $idx => $item) {
                if ($item[0] === $listenerId) {
                    unset($this->listeners[$type][$idx]);
                    return;
                }
            }
        }
    }

    public function emit(string $eventType, $event = null): void
    {
        if (!isset($this->listeners[$eventType])) {
            return;
        }
        $list = $this->listeners[$eventType];
        foreach ($list as $item) {
            $id = $item[0];
            $callback = objval($item[1], \Closure::class);
            $once = $item[2];
            $callback($event);
            if ($once) {
                $this->off($id);
            }
        }
    }

    public function emitAsync(string $eventType, $event = null): void
    {
        $app = Application::getInstance();
        $app->getScheduler()->addMicrotask(function () use ($eventType, $event) {
            $this->emit($eventType, $event);
        });
    }

    public function clear(): void
    {
        $this->listeners = [];
    }
}