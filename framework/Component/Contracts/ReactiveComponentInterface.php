<?php

namespace Px$1;

use Px\Core\Scheduler;
use Px\Render\RenderNode;
use Px\Dom\VNode;

/**
 * ReactiveComponentInterface 鈥?鍝嶅簲寮忕粍浠舵帴鍙?
 *
 * 瀹氫箟鍝嶅簲寮忕粍浠剁殑鍏叡濂戠害锛屽寘鎷覆鏌撱€佽剰鏍囪鏇存柊銆?
 * 浜嬩欢鍐掓场銆佺粦瀹氬€艰鍐欍€佺敓鍛藉懆鏈熺鐞嗐€?
 *
 * @see \Px\ReactiveComponent
 */
interface ReactiveComponentInterface
{
    // 鈹€鈹€ 娓叉煋 & 鏇存柊 鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€

    /**
     * 娉ㄥ叆娓叉煋璇锋眰鍥炶皟锛堢敱 Application 娉ㄥ叆锛夈€?
     */
    public function setRenderCallback(callable $callback): void;

    /**
     * 鎵ц寮傛鏇存柊锛堝井浠诲姟涓皟鐢級銆?
     */
    public function performUpdate(): void;

    /**
     * 鑾峰彇 VNode 鏍戯紙鎯版€ч噸寤猴級銆?
     */
    public function getVNodeTree(): VNode;

    /**
     * 娓叉煋褰撳墠缁勪欢鐨?VNode 鏍戙€?
     */
    public function render(): VNode;

    // 鈹€鈹€ 鐢熷懡鍛ㄦ湡 鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€

    /**
     * 鎸傝浇缁勪欢銆?
     */
    public function mount(): void;

    /**
     * 鍗歌浇缁勪欢銆?
     */
    public function unmount(): void;

    // 鈹€鈹€ 缁戝畾鍊?鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€

    /**
     * 璁剧疆缁戝畾鍊笺€?
     */
    public function setBindValue(string $bindKey, string $value): void;

    /**
     * 鑾峰彇缁戝畾鍊笺€?
     */
    public function getBindValue(string $bindKey): string;

    // 鈹€鈹€ RenderNode 鏍戠姸鎬佺鐞?鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€

    /**
     * 鑾峰彇涓婁竴甯х殑鏍?RenderNode锛岀敤浜庤法甯у尮閰嶅鐢ㄣ€?
     */
    public function getRootRenderNode(): ?RenderNode;

    /**
     * 璁剧疆褰撳墠鏍?RenderNode锛屼緵涓嬩竴甯у尮閰嶅鐢ㄣ€?
     */
    public function setRootRenderNode(?RenderNode $node): void;

    // 鈹€鈹€ 浜嬩欢鍒嗗彂 鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€鈹€

    /**
     * 娌跨粍浠舵爲鍐掓场鐐瑰嚮浜嬩欢銆?
     */
    public function dispatchClick(string $handler, ?string $arg = null): void;

    /**
     * 娌跨粍浠舵爲鍐掓场閿洏浜嬩欢銆?
     */
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void;
}

