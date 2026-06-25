<?php

namespace Px\Rendering\TextBackend;

use Px\Rendering\Backend\BackendCapability;

/**
 * ITextBackend — 文本渲染后端统一接口
 *
 * 所有文本后端（DirectWrite / Skia / GDI）实现此接口。
 *
 * 选择流程：
 *  1. probe() — 运行时检测本机是否支持（无副作用）
 *  2. activate() — 设置 C++ 层 g_textEngine，切换渲染引擎
 */
interface ITextBackend
{
    /**
     * 后端唯一名称（用于日志/诊断/配置）
     * 例：'dwrite'、'skia'、'gdi'
     */
    public function getName(): string;

    /**
     * 优先级（数值越大越优先）
     *  30 = DirectWrite（Windows 默认）
     *  20 = Skia
     *  10 = GDI（永远可用）
     */
    public static function getPriority(): int;

    /**
     * 运行时探测：检查当前环境是否支持此文本引擎
     * 失败原因写入 BackendCapability::reason
     */
    public function probe(): BackendCapability;

    /**
     * 激活：调用 sk_set_text_engine() 设置 C++ 层引擎
     */
    public function activate(): void;
}
