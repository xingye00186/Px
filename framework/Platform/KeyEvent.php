<?php

namespace Px\Platform;

use native_types;

/**
 * KeyEvent — 统一键盘事件（终极融合 P0.2，原 KeyboardEvent 正名）
 *
 * 相对 KeyboardEvent 新增 $modifiers 位掩码，供 Phase 2 快捷键系统消费。
 * 移动端软键盘由 OS IME 服务产出，Embedder 同样翻译为 KeyEvent。
 */
class KeyEvent extends PlatformEvent
{
    public const MOD_SHIFT = 1;
    public const MOD_CTRL  = 2;
    public const MOD_ALT   = 4;
    public const MOD_META  = 8;

    /** 'down' | 'up' | 'char' */
    public string $action;
    public int $keyCode;
    public string $char;

    /** MOD_* 位掩码组合 */
    public int $modifiers;

    public function __construct(string $action, int $keyCode, string $char = '', int $modifiers = 0)
    {
        parent::__construct('key');
        $this->action    = $action;
        $this->keyCode   = $keyCode;
        $this->char      = $char;
        $this->modifiers = $modifiers;
    }

    // AOT getter
    public function getAction(): string { return $this->action; }
    public function getKeyCode(): int { return $this->keyCode; }
    public function getChar(): string { return $this->char; }
    public function getModifiers(): int { return $this->modifiers; }

    public function hasShift(): bool { return ($this->modifiers & self::MOD_SHIFT) !== 0; }
    public function hasCtrl(): bool { return ($this->modifiers & self::MOD_CTRL) !== 0; }
    public function hasAlt(): bool { return ($this->modifiers & self::MOD_ALT) !== 0; }
    public function hasMeta(): bool { return ($this->modifiers & self::MOD_META) !== 0; }
}
