<?php

namespace Px$1;

use native_types;

/**
 * BackendRegistry 鈥?鍚庣娉ㄥ唽琛紙闈欐€侊級
 *
 * 缁存姢 6 涓悗绔被鍚嶏紙鎸変紭鍏堢骇纭紪鐮侊級锛屽苟鏀寔锛?
 *  - 鐢ㄦ埛寮哄埗瑕嗙洊锛堢幆澧冨彉閲?/ CLI 鍙傛暟锛?
 *  - 鎺㈡祴澶辫触鍚庣殑鍥為€€鍒楄〃绠＄悊
 *
 * 娉ㄦ剰锛欰OT 缂栬瘧绾︽潫涓嬶紝绫诲悕蹇呴』鐢ㄥ瓧绗︿覆甯搁噺銆?
 */
class BackendRegistry
{
    /**
     * 鍏ㄩ儴鍊欓€夊悗绔紙鎸変紭鍏堢骇浠庨珮鍒颁綆纭紪鐮侊級
     * 闃舵鍥涗簲闄嗙画瀹炵幇 D3D11 / WGL / Dawn / D2D
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
     * 鐢ㄦ埛寮哄埗瑕嗙洊锛氱幆澧冨彉閲?PX_RENDERER
     * 鍚堟硶鍊硷細'skia-cpu' | 'skia-d3d11' | 'skia-wgl' | 'skia-dawn' | 'gdi-d2d' | 'gdi-legacy'
     * 绌哄€?= 鑷姩閫夋嫨
     */
    public static function getForcedBackend(): string
    {
        // AOT 鍏煎锛歡etenv 杩斿洖 string|false
        $env = getenv('PX_RENDERER');
        if ($env === false) {
            return '';
        }
        return $env;
    }

    /**
     * 鏄惁澶勪簬 verbose 妯″紡锛堟墦鍗版帰娴嬭鎯咃級
     */
    public static function isVerbose(): bool
    {
        $env = getenv('PX_RENDERER_VERBOSE');
        return ($env === '1' || strtolower((string)$env) === 'true');
    }

    /**
     * 鎸変紭鍏堢骇闄嶅簭杩斿洖鍏ㄩ儴鍊欓€夛紙楂樹紭鍏堢骇鍦ㄥ墠锛?
     * CANDIDATES 鏁扮粍鏈韩宸叉寜浼樺厛绾ч檷搴忕‖缂栫爜锛岀洿鎺ヨ繑鍥炲嵆鍙€?
     *
     * @return string[] 绫诲悕鏁扮粍
     */
    public static function getCandidatesSorted(): array
    {
        return self::CANDIDATES;
    }
}

