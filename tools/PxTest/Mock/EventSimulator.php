<?php

namespace PxTest\Mock;

use Px\Platform\PointerEvent;
use Px\Platform\KeyEvent;
use Px\Platform\PlatformEvent;

/**
 * 事件模拟器 — 构造 PointerEvent/KeyEvent 的工厂方法。
 *
 * 使用方式:
 *   EventSimulator::mouseClick(100, 200)       → PointerEvent (kind='mouse')
 *   EventSimulator::mouseWheel(100, 200, -120) → PointerEvent (滚轮)
 *   EventSimulator::keyPress('a', 65)          → KeyEvent
 *   EventSimulator::touchTap(100, 200)         → PointerEvent (kind='touch')
 */
class EventSimulator
{
    /** 模拟鼠标左键点击 */
    public static function mouseClick(int $x, int $y): PointerEvent
    {
        return new PointerEvent('down', $x, $y, 0, 0);
    }

    /** 模拟鼠标右键点击 */
    public static function mouseRightClick(int $x, int $y): PointerEvent
    {
        return new PointerEvent('down', $x, $y, 1, 0);
    }

    /** 模拟鼠标移动 */
    public static function mouseMove(int $x, int $y): PointerEvent
    {
        return new PointerEvent('move', $x, $y, 0, 0);
    }

    /** 模拟鼠标滚轮 */
    public static function mouseWheel(int $x, int $y, int $delta): PointerEvent
    {
        return new PointerEvent('wheel', $x, $y, 0, $delta);
    }

    /** 模拟触屏点击（移动端路径；Framework 层应与鼠标同行为） */
    public static function touchTap(int $x, int $y, int $pointerId = 0): PointerEvent
    {
        return new PointerEvent('down', $x, $y, 0, 0, false, 'touch', $pointerId, 1000);
    }

    /** 模拟键盘按下 */
    public static function keyPress(string $char, int $keyCode): KeyEvent
    {
        return new KeyEvent('down', $keyCode, $char);
    }

    /** 模拟键盘松开 */
    public static function keyRelease(string $char, int $keyCode): KeyEvent
    {
        return new KeyEvent('up', $keyCode, $char);
    }

    /** 模拟 Enter 键 */
    public static function enterKey(): KeyEvent
    {
        return new KeyEvent('down', 13, "\r");
    }

    /**
     * 创建并注入事件到 MockPlatform。
     *
     * 便捷方法，等价于:
     *   $platform->injectEvent(EventSimulator::mouseClick(10, 20));
     */
    public static function injectClick(MockPlatform $platform, int $x, int $y): void
    {
        $platform->injectEvent(self::mouseClick($x, $y));
    }

    public static function injectWheel(MockPlatform $platform, int $x, int $y, int $delta): void
    {
        $platform->injectEvent(self::mouseWheel($x, $y, $delta));
    }

    public static function injectKey(MockPlatform $platform, string $char, int $keyCode): void
    {
        $platform->injectEvent(self::keyPress($char, $keyCode));
    }
}
