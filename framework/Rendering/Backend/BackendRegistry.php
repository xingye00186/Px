<?php

namespace Px\Rendering\Backend;

use native_types;

/**
 * BackendRegistry — 后端注册表（静态）
 *
 * 维护 6 个后端类名（按优先级硬编码），并支持：
 *  - 用户强制覆盖（环境变量 / CLI 参数）
 *  - 探测失败后的回退列表管理
 *
 * 注意：AOT 编译约束下，类名必须用字符串常量。
 */
class BackendRegistry
{
    /**
     * 全部候选后端（按优先级从高到低硬编码）
     * 阶段四五陆续实现 D3D11 / WGL / Dawn / D2D
     */
    public const CANDIDATES = [
        SkiaGraphiteDawnBackend::class,   // pri=100
        SkiaGaneshD3D11Backend::class,    // pri=90
        SkiaGaneshWGLBackend::class,      // pri=80
        SkiaCpuBackend::class,            // pri=60
        GdiDirect2DBackend::class,        // pri=50
        GdiLegacyBackend::class,          // pri=10
    ];

    /**
     * 用户强制覆盖：环境变量 PX_RENDERER
     * 合法值：'skia-cpu' | 'skia-d3d11' | 'skia-wgl' | 'skia-dawn' | 'gdi-d2d' | 'gdi-legacy'
     * 空值 = 自动选择
     */
    public static function getForcedBackend(): string
    {
        // AOT 兼容：getenv 返回 string|false
        $env = getenv('PX_RENDERER');
        if ($env === false) {
            return '';
        }
        return $env;
    }

    /**
     * 是否处于 verbose 模式（打印探测详情）
     */
    public static function isVerbose(): bool
    {
        $env = getenv('PX_RENDERER_VERBOSE');
        return ($env === '1' || strtolower((string)$env) === 'true');
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
