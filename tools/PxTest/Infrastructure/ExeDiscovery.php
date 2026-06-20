<?php

namespace PxTest\Infrastructure;

/**
 * exe 路径发现器 — 统一 3 份 findExe 副本。
 */
class ExeDiscovery
{
    private string $appBinDir;
    private string $projectRoot;

    public function __construct(string $appDir)
    {
        $this->appBinDir = rtrim($appDir, '/\\') . '/bin';
        $this->projectRoot = dirname($appDir, 2);
    }

    /**
     * 查找 exe 文件。
     * @param string $exeName 如 'css_test.exe'
     */
    public function findExe(string $exeName): ?string
    {
        $candidates = [
            // 项目根目录：--force-build 编译后新 exe 在此（优先级最高，最新编译）
            $this->projectRoot . '/' . $exeName,
            $this->appBinDir . '/' . $exeName,
            dirname($this->appBinDir) . '/' . $exeName,
        ];

        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        return null;
    }

    /** 检查 exe 是否就绪 */
    public function isReady(string $exeName): bool
    {
        return $this->findExe($exeName) !== null;
    }
}
