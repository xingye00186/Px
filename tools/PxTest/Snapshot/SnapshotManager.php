<?php

namespace PxTest\Snapshot;

/**
 * 统一快照管理器 — 多域快照读写、差异对比、按域/按失败更新。
 *
 * 支持三种更新模式:
 *   --update-snapshots=all            # 全量重建
 *   --update-snapshots=failed         # 仅更新失败的（默认）
 *   --update-snapshots=domain=layout  # 仅更新指定域
 */
class SnapshotManager
{
    /** 更新模式 */
    public const UPDATE_ALL = 'all';
    public const UPDATE_FAILED = 'failed';
    public const UPDATE_DOMAIN = 'domain';

    private string $baseDir;
    private string $updateMode = self::UPDATE_FAILED;
    private ?string $updateDomain = null;

    /** @var array<string, array<string, mixed>> 内存中的快照缓存 */
    private array $cache = [];

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
    }

    /** 设置更新模式 */
    public function setUpdateMode(string $mode, ?string $domain = null): self
    {
        $this->updateMode = $mode;
        $this->updateDomain = $domain;
        return $this;
    }

    /** 获取快照文件路径（含 PHP+OS 版本前缀） */
    private function filePath(SnapshotDomain $domain, string $name): string
    {
        $dir = $this->baseDir . '/' . $domain->dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $phpVer = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $osVer = PHP_OS_FAMILY;
        return "$dir/{$name}_php{$phpVer}_{$osVer}.json";
    }

    /** 写入快照 */
    public function write(SnapshotDomain $domain, string $name, array $data): void
    {
        $path = $this->filePath($domain, $name);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $json);
        $this->cache[$domain->value][$name] = $data;
    }

    /** 读取快照 */
    public function read(SnapshotDomain $domain, string $name): ?array
    {
        if (isset($this->cache[$domain->value][$name])) {
            return $this->cache[$domain->value][$name];
        }
        $path = $this->filePath($domain, $name);
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        if ($data === null) {
            return null;
        }
        $this->cache[$domain->value][$name] = $data;
        return $data;
    }

    /** 对比当前数据与基线快照 */
    public function diff(SnapshotDomain $domain, string $name, array $current): DiffResult
    {
        $baseline = $this->read($domain, $name);
        if ($baseline === null) {
            if ($this->shouldUpdate($domain)) {
                $this->write($domain, $name, $current);
            }
            return DiffResult::missing($name);
        }

        $diffs = $this->arrayDiff($baseline, $current);
        $passed = empty($diffs);

        if (!$passed && $this->shouldUpdate($domain, failed: true)) {
            $this->write($domain, $name, $current);
        }

        return new DiffResult($name, $passed, $diffs);
    }

    /** 全量更新所有快照 */
    public function updateAll(): void
    {
        $this->updateMode = self::UPDATE_ALL;
    }

    /** 按域更新 */
    public function updateDomain(SnapshotDomain $domain): void
    {
        $this->updateMode = self::UPDATE_DOMAIN;
        $this->updateDomain = $domain->value;
    }

    /** 判断是否应该更新快照 */
    private function shouldUpdate(SnapshotDomain $domain, bool $failed = false): bool
    {
        return match ($this->updateMode) {
            self::UPDATE_ALL => true,
            self::UPDATE_FAILED => $failed,
            self::UPDATE_DOMAIN => $this->updateDomain === $domain->value,
        };
    }

    /** 递归数组差异对比 */
    private function arrayDiff(array $a, array $b, string $prefix = ''): array
    {
        $diffs = [];
        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));
        sort($allKeys);

        foreach ($allKeys as $k) {
            $path = $prefix === '' ? (string)$k : "$prefix.$k";
            $va = $a[$k] ?? null;
            $vb = $b[$k] ?? null;

            if ($va === null && $vb !== null) {
                $diffs[] = "$path: added=" . json_encode($vb, JSON_UNESCAPED_UNICODE);
            } elseif ($va !== null && $vb === null) {
                $diffs[] = "$path: removed (was " . json_encode($va, JSON_UNESCAPED_UNICODE) . ")";
            } elseif (is_array($va) && is_array($vb)) {
                $diffs = array_merge($diffs, $this->arrayDiff($va, $vb, $path));
            } elseif ($va !== $vb) {
                $diffs[] = "$path: expected=" . json_encode($va, JSON_UNESCAPED_UNICODE)
                         . " actual=" . json_encode($vb, JSON_UNESCAPED_UNICODE);
            }
        }

        return $diffs;
    }
}

/**
 * 快照差异结果值对象。
 */
class DiffResult
{
    /** @param string[] $diffs */
    public function __construct(
        public readonly string $name,
        public readonly bool   $passed,
        public readonly array  $diffs = [],
    ) {}

    public static function missing(string $name): self
    {
        return new self($name, false, ['baseline snapshot missing']);
    }
}
