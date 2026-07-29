<?php

namespace PxTest\Core;

/**
 * 统一测试发现器 — 自动扫描发现所有测试文件。
 *
 * 支持三种命名模式：
 *   - *Test.php          (如 RenderNodeTest.php)
 *   - *-test.php         (如 sfc-compiler-test.php)
 *   - *_test.php         (如 legacy_test.php)
 *
 * 消除 run.php 的 glob 扫描 + run_all.php 的硬编码 $scripts 数组。
 */
class TestDiscovery
{
    /** @var string[] 扫描目录列表 */
    private array $scanDirs = [];

    /** @var string[] 排除的文件名模式 */
    private array $excludePatterns = [
        'bootstrap.php',
        'test-framework.php',
        'PipelineTestBase.php',
        'LayoutBase.php',
    ];

    /** @var string[] 自定义 group 标签 */
    private array $groups = [];

    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /**
     * 添加扫描目录。
     * @param string $dir     相对或绝对路径
     * @param string $group   分组标签 (如 'unit', 'integration', 'stress')
     * @param bool   $recurse 是否递归扫描子目录
     */
    public function addScanDir(string $dir, string $group = 'default', bool $recurse = true): self
    {
        $fullPath = str_starts_with($dir, $this->projectRoot)
            ? $dir
            : $this->projectRoot . '/' . ltrim($dir, '/\\');

        $this->scanDirs[] = [
            'path'    => rtrim($fullPath, '/\\'),
            'group'   => $group,
            'recurse' => $recurse,
        ];

        if (!isset($this->groups[$group])) {
            $this->groups[$group] = [];
        }

        return $this;
    }

    /**
     * 执行扫描，返回所有发现的测试文件信息。
     *
     * @return array<int, array{file: string, name: string, group: string}>
     */
    public function discover(): array
    {
        $found = [];

        foreach ($this->scanDirs as $scanDir) {
            $base = $scanDir['path'];
            if (!is_dir($base)) {
                continue;
            }

            if ($scanDir['recurse']) {
                $this->scanRecursive($base, $scanDir['group'], $found);
            } else {
                $this->scanFlat($base, $scanDir['group'], $found);
            }
        }

        // 去重（同一文件可能被多个目录规则匹配）
        $unique = [];
        foreach ($found as $item) {
            $key = $item['file'];
            if (!isset($unique[$key])) {
                $unique[$key] = $item;
            }
        }

        sort($unique);
        return array_values($unique);
    }

    /**
     * 按 group 标签过滤。
     * @param string[]|null $groups  null=全部
     */
    public function discoverByGroups(?array $groups = null): array
    {
        $all = $this->discover();
        if ($groups === null) {
            return $all;
        }
        return array_values(array_filter($all, fn($item) => in_array($item['group'], $groups, true)));
    }

    /**
     * 排除特定 group 后返回。
     * @param string[] $excludeGroups
     */
    public function discoverExcluding(array $excludeGroups): array
    {
        $all = $this->discover();
        return array_values(array_filter($all, fn($item) => !in_array($item['group'], $excludeGroups, true)));
    }

    /** 获取所有 group 标签 */
    public function getGroups(): array
    {
        $groups = [];
        foreach ($this->scanDirs as $sd) {
            $groups[$sd['group']] = true;
        }
        return array_keys($groups);
    }

    // ── private ──

    private function scanRecursive(string $dir, string $group, array &$found): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            if ($file->getExtension() !== 'php') continue;
            // C0.2: _retired/ 目录存放已退役僵尸测试（旧 API 灭失、覆盖已由
            // css-standards 接管），不参与发现；保留文件供考古。
            if (str_contains(str_replace('\\', '/', $file->getPath()), '/_retired')) continue;
            if ($this->isExcluded($file->getFilename())) continue;
            if (!$this->isTestFile($file->getFilename())) continue;

            $found[] = [
                'file'  => str_replace('\\', '/', $file->getPathname()),
                'name'  => $file->getFilename(),
                'group' => $group,
            ];
        }
    }

    private function scanFlat(string $dir, string $group, array &$found): void
    {
        $files = glob($dir . '/*.php');
        foreach ($files as $path) {
            $name = basename($path);
            if ($this->isExcluded($name)) continue;
            if (!$this->isTestFile($name)) continue;

            $found[] = [
                'file'  => str_replace('\\', '/', $path),
                'name'  => $name,
                'group' => $group,
            ];
        }
    }

    private function isTestFile(string $filename): bool
    {
        // 匹配 *Test.php, *-test.php, *_test.php
        return (bool)preg_match('/(?:Test|-test|_test)\.php$/', $filename);
    }

    private function isExcluded(string $filename): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (str_contains($filename, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
