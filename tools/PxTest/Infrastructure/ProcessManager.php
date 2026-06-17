<?php

namespace PxTest\Infrastructure;

/**
 * 进程管理器 — 从 run.php 提取的进程锁/Ctrl+C/注册表。
 */
class ProcessManager
{
    private string $lockFile;
    private string $registryFile;
    /** @var resource|null */
    private $buildProc = null;

    public function __construct(string $projectRoot)
    {
        $this->lockFile = $projectRoot . '/.build.lock';
        $this->registryFile = $projectRoot . '/.process_registry.json';
    }

    /** 获取编译锁 */
    public function acquireBuildLock(): bool
    {
        if (file_exists($this->lockFile)) {
            $pid = (int)@file_get_contents($this->lockFile);
            if ($pid > 0) {
                $alive = trim(@shell_exec('tasklist /fi "PID eq ' . $pid . '" /nh 2>nul') ?? '');
                if (str_contains($alive, (string)$pid)) {
                    return false;
                }
            }
            @unlink($this->lockFile);
        }
        file_put_contents($this->lockFile, getmypid());
        return true;
    }

    /** 释放编译锁 */
    public function releaseBuildLock(): void
    {
        if (file_exists($this->lockFile) && (int)@file_get_contents($this->lockFile) === getmypid()) {
            @unlink($this->lockFile);
        }
    }

    /** 注册子进程 PID */
    public function registerProcess(int $pid, string $name): void
    {
        $entries = [];
        if (file_exists($this->registryFile)) {
            $entries = json_decode(@file_get_contents($this->registryFile), true) ?? [];
        }
        $entries[] = ['pid' => $pid, 'name' => $name, 'time' => time()];
        @file_put_contents($this->registryFile, json_encode($entries, JSON_PRETTY_PRINT));
    }

    /** 清理进程注册表 */
    public function cleanupProcessRegistry(): void
    {
        if (!file_exists($this->registryFile)) return;
        $entries = json_decode(@file_get_contents($this->registryFile), true) ?? [];
        foreach ($entries as $entry) {
            $pid = $entry['pid'] ?? 0;
            if ($pid > 0) {
                @exec("taskkill /f /t /pid {$pid} 2>nul");
            }
        }
        @unlink($this->registryFile);
    }

    /** 注册 Ctrl+C 处理器（Windows） */
    public function registerSignalHandler(): void
    {
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function () {
                $this->cleanupProcessRegistry();
                $this->releaseBuildLock();
                exit(1);
            });
        }
        register_shutdown_function(function () {
            $this->cleanupProcessRegistry();
            $this->releaseBuildLock();
        });
    }

    /** 清理孤儿进程 */
    public function killOrphanProcesses(): void
    {
        @exec('taskkill /f /im swoole_compiler*.exe 2>nul');
        @exec('taskkill /f /im cl.exe 2>nul');
        @exec('taskkill /f /im link.exe 2>nul');
    }
}
