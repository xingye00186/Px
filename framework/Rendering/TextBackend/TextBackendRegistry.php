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
    /** @var TextBackendSelector|null 全局选择器实例（渲染层管理） */
    private static ?TextBackendSelector $selector = null;

    /**
     * 初始化文本后端：创建选择器 → probe 候选 → 激活第一个可用的。
     * 由渲染层负责调用（如 ResilientTextBackendProxy 构造或 VNodeRenderer 初始化）。
     * C++ 绑定不可用时（测试环境）静默跳过。
     */
    public static function initialize(): void
    {
        if (self::$selector !== null) return;

        if (!function_exists('sk_set_text_engine')) {
            return;
        }

        self::$selector = new TextBackendSelector();
        $backend = self::$selector->select();
        if ($backend !== null) {
            error_log('[Px] TextBackend: ' . $backend->getName());
        } else {
            error_log('[Px] TextBackend: none available, using C++ default');
        }
    }

    public static function getSelector(): ?TextBackendSelector { return self::$selector; }

    /**
     * 全部候选文本后端（按优先级从高到低硬编码）
     */
    public const CANDIDATES = [
        DWriteTextBackend::class,   // pri=30
        SkiaTextBackend::class,     // pri=20
        GdiTextBackend::class,      // pri=10
    ];

    /**
     * 用户强制覆盖：从 project.yml 的 Px_text_engine 读取
     * 合法值：'dwrite' | 'skia' | 'gdi' | ''（空字符串 = 自动选择）
     */
    public static function getForcedBackend(): string
    {
        $engine = Config::get('text_engine', '');
        if ($engine === false || $engine === null) {
            return '';
        }
        return (string)$engine;
    }

    /**
     * 是否处于 verbose 模式（打印探测详情）
     * 通过 Px_text_engine_verbose 配置控制
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
