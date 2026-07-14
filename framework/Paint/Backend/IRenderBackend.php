<?php

namespace Px$1;

use Px\Paint\RenderContext;

/**
 * IRenderBackend 鈥?娓叉煋鍚庣缁熶竴鎺ュ彛
 *
 * 鎵€鏈夋覆鏌撳悗绔紙Skia-CPU / Skia-D3D11 / Skia-WGL / Skia-Graphite-Dawn /
 * GDI-Direct2D / GDI-Legacy锛夊疄鐜版鎺ュ彛銆?
 *
 * 鍚庣閫夋嫨娴佺▼锛?
 *  1. probe() 鈥?杩愯鏃舵娴嬫湰鏈烘槸鍚︽敮鎸侊紙鏃犲壇浣滅敤锛?
 *  2. initialize() 鈥?鐪熸鍒涘缓璧勬簮锛堝彲鑳藉垱寤虹獥鍙?璁惧/涓婁笅鏂囷級
 *  3. getContext() 鈥?鎷垮埌 RenderContext 缁?VNodeRenderer 鐢?
 *  4. shutdown() 鈥?閲婃斁璧勬簮
 *
 * 闃舵鍥涜捣鏂板 D3D11 / WGL / Dawn 鍚庣锛岄樁娈典竴浜屼笁 GDI/SkiaCPU 宸插彲鐢ㄣ€?
 */
interface IRenderBackend
{
    /**
     * 鍚庣鍞竴鍚嶇О锛堢敤浜庢棩蹇?璇婃柇锛?
     * 渚嬶細'skia-cpu'銆?skia-d3d11'銆?skia-wgl'銆?skia-dawn'銆?gdi-d2d'銆?gdi-legacy'
     */
    public function getName(): string;

    /**
     * 浼樺厛绾э紙鏁板€艰秺澶ц秺浼樺厛锛?
     * 100 = Skia-Graphite-Dawn
     *  90 = Skia-Ganesh-D3D11
     *  80 = Skia-Ganesh-WGL
     *  60 = Skia-CPU锛堥樁娈典笁褰撳墠锛?
     *  50 = GDI-Direct2D
     *  10 = GDI-Legacy锛堟案杩滃彲鐢級
     */
    public static function getPriority(): int;

    /**
     * 杩愯鏃舵帰娴嬶細妫€鏌ュ綋鍓嶈繘绋?+ 绯荤粺鐜鏄惁鏀寔
     * 澶辫触鍘熷洜鍐欏叆 BackendCapability::reason
     * 鎺㈡祴璇︽儏锛堝 D3D feature level锛夊啓鍏?details
     */
    public function probe(): BackendCapability;

    /**
     * 鍒濆鍖栵細鍒涘缓 GPU 璁惧 / Skia context / 瀛椾綋绛?
     * 澶辫触鎶?BackendInitException
     *
     * @param int $hwnd  绐楀彛鍙ユ焺
     * @param int $w     绐楀彛瀹藉害
     * @param int $h     绐楀彛楂樺害
     */
    public function initialize(int $hwnd, int $w, int $h): void;

    /**
     * 鎷垮埌 RenderContext锛圴NodeRenderer 鐢ㄥ畠缁樺埗锛?
     */
    public function getContext(): RenderContext;

    /**
     * 鍏抽棴/閲婃斁璧勬簮
     */
    public function shutdown(): void;
}

