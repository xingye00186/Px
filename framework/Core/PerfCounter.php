<?php

namespace Px\Core;

use native_types;

/**
 * PerfCounter — 轻量级静态性能计数器
 * 
 * 环境变量 PX_PERF=1 时启用，默认关闭时零开销。
 * 提供 start/end/inc/snapshot 方法，计时单位微秒(μs)。
 */
class PerfCounter
{
    /** @var bool 是否启用 */
    private static bool $enabled = false;

    /** @var bool 是否已执行初始化 */
    private static bool $initialized = false;

    /** @var array<string, array{start: float, count: int, total: float, min: float, max: float}> */
    private static array $counters = [];

    /** @var array<string, float> 正在运行的计时器 */
    private static array $running = [];

    /**
     * 延迟初始化（代替类外自动调用，以满足 AOT 编译约束）
     */
    private static function ensureInit(): void
    {
        if (!self::$initialized) {
            self::$enabled = (getenv('PX_PERF') === '1');
            self::$initialized = true;
        }
    }

    /**
     * 是否启用
     */
    public static function isEnabled(): bool
    {
        self::ensureInit();
        return self::$enabled;
    }

    /**
     * 开始计时
     */
    public static function start(string $name): void
    {
        self::ensureInit();
        if (!self::$enabled) return;
        self::$running[$name] = microtime(true);
    }

    /**
     * 结束计时，记录耗时
     */
    public static function end(string $name): void
    {
        self::ensureInit();
        if (!self::$enabled) return;
        if (!isset(self::$running[$name])) return;

        $elapsed = (microtime(true) - self::$running[$name]) * 1_000_000; // μs
        unset(self::$running[$name]);

        if (!isset(self::$counters[$name])) {
            self::$counters[$name] = [
                'start'  => microtime(true),
                'count'  => 0,
                'total'  => 0.0,
                'min'    => PHP_FLOAT_MAX,
                'max'    => 0.0,
            ];
        }

        $c = &self::$counters[$name];
        $c['count']++;
        $c['total'] += $elapsed;
        if ($elapsed < $c['min']) $c['min'] = $elapsed;
        if ($elapsed > $c['max']) $c['max'] = $elapsed;
    }

    /**
     * 递增计数器
     */
    public static function inc(string $name, int $delta = 1): void
    {
        self::ensureInit();
        if (!self::$enabled) return;
        if (!isset(self::$counters[$name])) {
            self::$counters[$name] = [
                'start' => microtime(true),
                'count' => 0,
                'total' => 0.0,
                'min'   => PHP_FLOAT_MAX,
                'max'   => 0.0,
            ];
        }
        self::$counters[$name]['count'] += $delta;
    }

    /**
     * 获取快照并自动重置所有计数器
     * 
     * @return array<string, array{count: int, total: float, avg: float, min: float, max: float}>
     */
    public static function snapshot(): array
    {
        self::ensureInit();
        if (!self::$enabled) return [];

        $now = microtime(true);
        $result = [];
        foreach (self::$counters as $name => $c) {
            $result[$name] = [
                'count' => $c['count'],
                'total' => round($c['total'], 2),
                'avg'   => $c['count'] > 0 ? round($c['total'] / $c['count'], 2) : 0.0,
                'min'   => $c['min'] === PHP_FLOAT_MAX ? 0.0 : round($c['min'], 2),
                'max'   => round($c['max'], 2),
                'elapsed_sec' => round($now - $c['start'], 4),
            ];
        }

        // 自动重置
        self::$counters = [];
        self::$running = [];

        return $result;
    }

    /**
     * 格式化输出快照
     */
    public static function formatSnapshot(array $snapshot): string
    {
        if (empty($snapshot)) return '';

        $lines = [];
        $lines[] = str_repeat('-', 90);
        $lines[] = sprintf('| %-28s | %-6s | %-12s | %-12s | %-12s |', 'Counter', 'Count', 'Total(μs)', 'Avg(μs)', 'Max(μs)');
        $lines[] = str_repeat('-', 90);

        foreach ($snapshot as $name => $data) {
            $lines[] = sprintf(
                '| %-28s | %-6d | %-12.2f | %-12.2f | %-12.2f |',
                $name,
                $data['count'],
                $data['total'],
                $data['avg'],
                $data['max']
            );
        }
        $lines[] = str_repeat('-', 90);

        return implode("\n", $lines);
    }
}

