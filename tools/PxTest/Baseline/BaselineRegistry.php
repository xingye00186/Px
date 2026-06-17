<?php

namespace PxTest\Baseline;

/**
 * 基线注册表 — 管理已归档 case 的语义版本基线。
 *
 * 增强 baseline_registry.json 的功能：
 * - 记录框架版本哈希
 * - 支持语义版本失效策略
 * - 增量基线更新
 */
class BaselineRegistry
{
    private string $registryPath;
    private array $data;

    public function __construct(string $registryPath)
    {
        $this->registryPath = $registryPath;
        $this->data = $this->load();
    }

    /** 加载注册表 */
    private function load(): array
    {
        if (!file_exists($this->registryPath)) {
            return [
                '_format_version' => 2,
                '_framework_hash' => '',
                'cases' => [],
            ];
        }
        $loaded = json_decode(file_get_contents($this->registryPath), true);
        return $loaded ?: ['_format_version' => 2, '_framework_hash' => '', 'cases' => []];
    }

    /** 保存注册表 */
    public function save(): void
    {
        ksort($this->data['cases']);
        file_put_contents($this->registryPath,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** 设置框架版本哈希 */
    public function setFrameworkHash(string $hash): void
    {
        $this->data['_framework_hash'] = $hash;
    }

    /** 检查框架是否变更（哈希不匹配） */
    public function isFrameworkChanged(string $currentHash): bool
    {
        $stored = $this->data['_framework_hash'] ?? '';
        return $stored !== '' && $stored !== $currentHash;
    }

    /** 归档 case */
    public function archive(string $caseName, array $meta): void
    {
        $this->data['cases'][$caseName] = array_merge([
            'archived_at' => date('Y-m-d H:i:s'),
            'framework_hash_at_archive' => $this->data['_framework_hash'] ?? '',
        ], $meta);
        $this->save();
    }

    /** 获取已归档 case 列表 */
    public function getArchivedCases(): array
    {
        return array_keys($this->data['cases'] ?? []);
    }

    /** 检查 case 是否已归档 */
    public function isArchived(string $caseName): bool
    {
        return isset($this->data['cases'][$caseName]);
    }

    /** 获取 case 元数据 */
    public function getMeta(string $caseName): ?array
    {
        return $this->data['cases'][$caseName] ?? null;
    }

    /** 归档数量 */
    public function count(): int
    {
        return count($this->data['cases'] ?? []);
    }

    /** 清空所有基线 */
    public function clear(): void
    {
        $this->data['cases'] = [];
        $this->save();
    }
}
