<?php

namespace Px\Core;

use native_types;

/**
 * Config — AOT 兼容的运行时配置读取（来自 project.yml 中 Px_* 前缀的项）
 *
 * 静态类，由 Application::mount() 初始化。
 * 从 {APP_DIR}/project.yml 中读取以 Px_ 开头的键值对，
 * 去除 Px_ 前缀后供 Config::get() 查询。
 * 文件不存在时所有 get() 返回默认值，不抛异常。
 */
class Config
{
    private static ?array $cache = null;
    private static string $appDir = '';

    /**
     * 初始化配置路径，由 Application::mount() 调用。
     * @param string $appDir 应用目录（如 apps/bilibili）
     */
    public static function init(string $appDir): void
    {
        self::$appDir = $appDir;
        self::$cache = null; // 强制重新解析
        $ymlFile = $appDir . '/project.yml';
        if (!file_exists($ymlFile)) {
            return; // 无配置文件，全部走默认值
        }
        $lines = file($ymlFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        $prefix = 'Px_';
        $parsed = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue; // 空行或注释
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // 提取 Px_ 前缀的项，去除前缀后存入缓存
            if (!str_starts_with($key, 'Px_')) {
                continue;
            }
            $shortKey = substr($key, 3);

            // 解析布尔值
            if ($val === 'true') {
                $parsed[$shortKey] = true;
            } elseif ($val === 'false') {
                $parsed[$shortKey] = false;
            } elseif (is_numeric($val)) {
                // 整数/浮点数
                $parsed[$shortKey] = strpos($val, '.') !== false ? (float)$val + 0 : (int)$val;
            } else {
                // 字符串，去掉引号
                if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
                    || (str_starts_with($val, "'") && str_ends_with($val, "'"))
                ) {
                    $val = substr($val, 1, -1);
                }
                $parsed[$shortKey] = $val;
            }
        }
        self::$cache = $parsed;
    }

    /**
     * 读取配置项。
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            return $default;
        }
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    /**
     * 获取应用目录路径（含尾部 /）。
     * @return string
     */
    public static function getAppDir(): string
    {
        return self::$appDir;
    }

    /**
     * 获取调试输出目录路径。
     * @return string （如 apps/bilibili/debug）
     */
    public static function getOutputDir(): string
    {
        return self::$appDir . '/debug';
    }
}
