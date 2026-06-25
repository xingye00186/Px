<?php

namespace Px\Rendering\TextBackend;

use native_types;
use Px\Core\Config;

/**
 * TextBackendRegistry — 文本后端注册表（静态）
 *
 * 维护 3 个候选后端（按优先级硬编码），并支持：
 *  - 用户强制覆盖（Px_debug_text_engine 配置）
 *  - 探测失败后的回退列表管理
 *
 * 注意：AOT 编译约束下，类名必须用字符串常量。
 */
class TextBackendRegistry
{
    /**
     * 全部候选文本后端（按优先级从高到低硬编码）
     */
    public const CANDIDATES = [
        DWriteTextBackend::class,   // pri=30
        SkiaTextBackend::class,     // pri=20
        GdiTextBackend::class,      // pri=10
    ];

    /**
     * 用户强制覆盖：从 project.yml 的 Px_debug_text_engine 读取
     * 合法值：'dwrite' | 'skia' | 'gdi' | ''（空字符串 = 自动选择）
     */
    public static function getForcedBackend(): string
    {
        // Config 已由 Application::mount() 初始化
        $engine = Config::get('text_engine', '');
        if ($engine === false || $engine === null) {
            return '';
        }
        return (string)$engine;
    }

    /**
     * 是否处于 verbose 模式（打印探测详情）
     * 通过 Px_debug_text_engine_verbose 配置控制
     */
    public static function isVerbose(): bool
    {
        return (bool)Config::get('text_engine_verbose', false);
    }

    /**
     * 按优先级降序返回全部候选（高优先级在前）
     * CANDIDATES 数组本身已按优先级降序硬编码，直接返回即可。
     *
     * @return string[] 类名数组
     */
    public static function getCandidatesSorted(): array
    {
        return self::CANDIDATES;
    }
}
