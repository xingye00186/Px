<?php

namespace Px\Rendering;

use native_types;

/**
 * ImageManager — 图片缓存管理器（静态）
 *
 * 职责：
 *   1. 缓存已加载的图片句柄（路径→句柄映射），避免重复加载
 *   2. 将相对路径解析为绝对路径（基于应用根目录）
 *   3. 应用退出时统一释放所有图片资源
 *
 * 与后端无关：底层调用 sk_load_image/sk_free_image，skia_render.cc
 * 在 USE_SKIA / 非 USE_SKIA 下都有实现。
 */
class ImageManager
{
    /** @var string 应用根目录（用于解析相对路径） */
    private static string $appRoot = '';

    /** @var array<string, int> 路径 → 图片句柄映射 */
    private static array $cache = [];

    /** @var bool 是否已初始化 */
    private static bool $initialized = false;

    /**
     * 设置应用根目录（在 Application::mount() 时调用）
     */
    public static function setAppRoot(string $appDir): void
    {
        // 统一用 / 分隔符
        self::$appRoot = rtrim(str_replace('\\', '/', $appDir), '/');
        self::$initialized = true;
    }

    /**
     * 加载图片，返回句柄（Int 伪装指针）
     *
     * @param string $path 图片路径（相对或绝对）
     * @return int 句柄，0 表示失败
     */
    public static function loadImage(string $path): int
    {
        if ($path === '') return 0;

        // 解析为绝对路径
        $absPath = self::resolvePath($path);
        if ($absPath === '') return 0;

        // 缓存命中
        if (isset(self::$cache[$absPath])) {
            return self::$cache[$absPath];
        }

        // 检查文件存在
        if (!file_exists($absPath)) {
            fprintf(STDERR, "[ImageManager] file not found: %s\n", $absPath);
            return 0;
        }

        // 调用 C++ 层加载
        $handle = sk_load_image($absPath);
        if ($handle === 0) {
            fprintf(STDERR, "[ImageManager] sk_load_image failed: %s\n", $absPath);
            return 0;
        }

        self::$cache[$absPath] = $handle;
        return $handle;
    }

    /**
     * 释放指定图片
     */
    public static function freeImage(string $path): void
    {
        $absPath = self::resolvePath($path);
        if ($absPath === '' || !isset(self::$cache[$absPath])) return;

        $handle = self::$cache[$absPath];
        sk_free_image($handle);
        unset(self::$cache[$absPath]);
    }

    /**
     * 释放所有图片资源（应用退出时调用）
     */
    public static function freeAll(): void
    {
        foreach (self::$cache as $path => $handle) {
            sk_free_image($handle);
        }
        self::$cache = [];
    }

    /**
     * 获取已缓存的图片句柄（不触发加载）
     */
    public static function getHandle(string $path): int
    {
        $absPath = self::resolvePath($path);
        if ($absPath === '') return 0;
        return self::$cache[$absPath] ?? 0;
    }

    /**
     * 将路径解析为绝对路径
     *
     * @param string $path 相对或绝对路径
     * @return string 解析后的绝对路径（/ 分隔符），空字符串表示无法解析
     */
    public static function resolvePath(string $path): string
    {
        if ($path === '') return '';

        // 统一分隔符
        $normalized = str_replace('\\', '/', $path);

        // 已经是绝对路径（Windows: C:/...）
        if (preg_match('/^[a-zA-Z]:\//', $normalized)) {
            return $normalized;
        }

        // 相对路径 → 基于 appRoot 解析
        if (self::$appRoot === '') {
            return '';
        }

        return self::$appRoot . '/' . ltrim($normalized, '/');
    }

    /**
     * 获取缓存中的图片数量（用于诊断）
     */
    public static function getCacheSize(): int
    {
        return count(self::$cache);
    }

    /**
     * 检查图片是否已缓存
     */
    public static function isCached(string $path): bool
    {
        $absPath = self::resolvePath($path);
        if ($absPath === '') return false;
        return isset(self::$cache[$absPath]);
    }
}
