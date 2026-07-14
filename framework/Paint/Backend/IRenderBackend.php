<?php

namespace Px\Paint\Backend;

use Px\Paint\RenderContext;

/**
 * IRenderBackend — 渲染后端统一接口
 *
 * 所有渲染后端（Skia-CPU / Skia-D3D11 / Skia-WGL / Skia-Graphite-Dawn /
 * GDI-Direct2D / GDI-Legacy）实现此接口。
 *
 * 后端选择流程：
 *  1. probe() — 运行时检测本机是否支持（无副作用）
 *  2. initialize() — 真正创建资源（可能创建窗口/设备/上下文）
 *  3. getContext() — 拿到 RenderContext 给 VNodeRenderer 用
 *  4. shutdown() — 释放资源
 *
 * 阶段四起新增 D3D11 / WGL / Dawn 后端，阶段一二三 GDI/SkiaCPU 已可用。
 */
interface IRenderBackend
{
    /**
     * 后端唯一名称（用于日志/诊断）
     * 例：'skia-cpu'、'skia-d3d11'、'skia-wgl'、'skia-dawn'、'gdi-d2d'、'gdi-legacy'
     */
    public function getName(): string;

    /**
     * 优先级（数值越大越优先）
     * 100 = Skia-Graphite-Dawn
     *  90 = Skia-Ganesh-D3D11
     *  80 = Skia-Ganesh-WGL
     *  60 = Skia-CPU（阶段三当前）
     *  50 = GDI-Direct2D
     *  10 = GDI-Legacy（永远可用）
     */
    public static function getPriority(): int;

    /**
     * 运行时探测：检查当前进程 + 系统环境是否支持
     * 失败原因写入 BackendCapability::reason
     * 探测详情（如 D3D feature level）写入 details
     */
    public function probe(): BackendCapability;

    /**
     * 初始化：创建 GPU 设备 / Skia context / 字体等
     * 失败抛 BackendInitException
     *
     * @param int $hwnd  窗口句柄
     * @param int $w     窗口宽度
     * @param int $h     窗口高度
     */
    public function initialize(int $hwnd, int $w, int $h): void;

    /**
     * 拿到 RenderContext（VNodeRenderer 用它绘制）
     */
    public function getContext(): RenderContext;

    /**
     * 关闭/释放资源
     */
    public function shutdown(): void;
}
