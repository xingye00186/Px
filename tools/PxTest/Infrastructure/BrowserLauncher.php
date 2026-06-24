<?php

namespace PxTest\Infrastructure;

/**
 * Edge 浏览器启动器 — 抽象 headless 调用。
 *
 * 安全措施：
 * 1. 调用前清理残留 Edge 进程，避免 exec() 无限阻塞
 * 2. 使用 --virtual-time-budget=30000 内置超时
 */
class BrowserLauncher
{
    private ?string $edgePath = null;

    public function __construct()
    {
        // 构造时清理上一次残留的 Edge 进程
        if (PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /IM msedge.exe /T 2>NUL');
        }
        $this->findEdge();
    }

    /** 查找 Edge 路径 */
    public function findEdge(): ?string
    {
        $paths = [
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            getenv('LOCALAPPDATA') . '\Microsoft\Edge\Application\msedge.exe',
        ];
        foreach ($paths as $p) {
            if (file_exists($p)) { $this->edgePath = $p; return $p; }
        }
        return null;
    }

    /** Edge 是否可用 */
    public function isAvailable(): bool
    {
        return $this->findEdge() !== null;
    }

    /** 截图（headless screenshot），内置 30s 超时 */
    public function screenshot(string $htmlPath, string $outputPath, int $width = 1600, int $height = 800): bool
    {
        if ($this->edgePath === null) return false;
        $realPath = realpath($htmlPath);
        if ($realPath === false) return false;
        $fileUrl = 'file:///' . str_replace('\\', '/', $realPath);

        $cmd = sprintf('"%s" --headless --disable-gpu --virtual-time-budget=30000 --window-size=%d,%d --screenshot="%s" "%s" 2>&1',
            $this->edgePath, $width, $height, $outputPath, $fileUrl);
        exec($cmd);
        return file_exists($outputPath) && filesize($outputPath) > 100;
    }

    /** dump DOM（内置 30s 超时） */
    public function dumpDom(string $htmlPath, int $width = 1600, int $height = 800): ?string
    {
        if ($this->edgePath === null) return null;
        $realPath = realpath($htmlPath);
        if ($realPath === false) return null;
        $fileUrl = 'file:///' . str_replace('\\', '/', $realPath);

        $cmd = sprintf('"%s" --headless --disable-gpu --virtual-time-budget=30000 --window-size=%d,%d --dump-dom "%s" 2>&1',
            $this->edgePath, $width, $height, $fileUrl);
        $output = [];
        exec($cmd, $output);
        return empty($output) ? null : implode("\n", $output);
    }
}
