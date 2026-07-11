<?php

namespace Px\Rendering;

use Px\Core\Config;

/**
 * Diag — 布局诊断日志系统（永久基础设施）
 *
 * 三优先级：CLI 参数 --diag-layout=N > project.yml Px_diag_layout_level > 默认 0
 *
 * 使用:
 *   Diag::log(1, 'layout:enter', ['rootW' => $w, 'rootH' => $h]);
 *   Diag::log(2, 'block:enter', ['type' => $node->type, 'cw' => $cw]);
 *   Diag::log(3, 'pfrag:new',   ['x' => $x, 'y' => $y, 'w' => $w]);
 *
 * 启用:
 *   css_test.exe --case=xxx --diag-layout=2
 *   project.yml 加 Px_diag_layout_level: 2
 */
class Diag
{
    private static int $level = -1;
    private static ?string $logPath = null;

    /**
     * CLI 参数覆盖（由 Application::handleDumpArgs 调用）。
     */
    public static function initFromCli(int $level): void
    {
        self::$level = max(0, $level);
        if (self::$logPath === null) {
            $env = getenv('PX_DIAG_LAYOUT_LOG');
            self::$logPath = ($env !== false && $env !== '') ? (string)$env : null;
        }
    }

    /**
     * 设置日志路径（由 --diag-log-path= 参数调用）。
     */
    public static function setLogPath(string $path): void
    {
        self::$logPath = $path !== '' ? $path : null;
    }

    /**
     * 输出诊断日志。
     *
     * @param int    $level  日志层级（1=入口/出口, 2=节点决策, 3=Fragment 参数）
     * @param string $msg    日志消息
     * @param array  $ctx    上下文键值对（如 ['w' => 100, 'h' => 200]）
     */
    public static function log(int $level, string $msg, array $ctx = []): void
    {
        if (self::$level === -1) {
            // 首次调用：检测 project.yml 配置
            self::$level = (int)Config::get('diag_layout_level', 0);
            $env = getenv('PX_DIAG_LAYOUT_LOG');
            self::$logPath = ($env !== false && $env !== '') ? (string)$env : null;
        }
        if ($level > self::$level) return;

        $extra = '';
        foreach ($ctx as $k => $v) {
            $extra .= " $k=$v";
        }

        if (self::$logPath !== null) {
            file_put_contents(self::$logPath, "[DIAG_LAYOUT] $msg$extra\n", FILE_APPEND);
        } else {
            error_log("[DIAG_LAYOUT] $msg$extra");
        }
    }
}
