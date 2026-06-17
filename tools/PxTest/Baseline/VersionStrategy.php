<?php

namespace PxTest\Baseline;

/**
 * 语义版本策略 — 框架哈希变更时自动失效策略。
 */
class VersionStrategy
{
    private string $currentHash;

    public function __construct(string $frameworkDir)
    {
        $this->currentHash = $this->computeHash($frameworkDir);
    }

    public function getCurrentHash(): string { return $this->currentHash; }

    /** 检查已归档基线是否需要因框架变更而失效 */
    public function shouldInvalidate(BaselineRegistry $registry): bool
    {
        return $registry->isFrameworkChanged($this->currentHash);
    }

    /** 更新注册表中的框架哈希 */
    public function updateRegistry(BaselineRegistry $registry): void
    {
        $registry->setFrameworkHash($this->currentHash);
    }

    private function computeHash(string $dir): string
    {
        $files = glob($dir . '/Core/*.php');
        $files = array_merge($files, glob($dir . '/Rendering/*.php'));
        $files = array_merge($files, glob($dir . '/Rendering/Layout/*.php'));
        sort($files);
        $hashes = '';
        foreach (array_slice($files, 0, 20) as $f) {
            $hashes .= md5_file($f);
        }
        return substr(md5($hashes), 0, 12);
    }
}
