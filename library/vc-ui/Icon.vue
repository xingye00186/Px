<template>
  <div style="font-size:16px;text-align:center">{{ iconChar }}</div>
</template>

<script lang="php">

    /** 图标名称或 Unicode 字符 */
    public string $name = '';

    /**
     * 内置图标名称 → Unicode 映射
     */
    private array $iconMap = [
        'search' => '🔍',
        'arrow-left' => '◀',
        'arrow-right' => '▶',
        'arrow-up' => '▲',
        'arrow-down' => '▼',
        'notification' => '🔔',
        'user' => '👤',
        'heart' => '♥',
        'eye' => '👁',
        'refresh' => '🔄',
        'close' => '✕',
        'check' => '✓',
        'plus' => '+',
        'minus' => '−',
        'settings' => '⚙',
        'home' => '⌂',
        'star' => '★',
        'play' => '▶',
        'pause' => '⏸',
        'upload' => '↑',
        'download' => '↓',
        'camera' => '📷',
        'clock' => '🕐',
        'fire' => '🔥',
        'hot' => '🔥',
        'thumbs-up' => '👍',
        'comment' => '💬',
        'share' => '📤',
        'more' => '…',
        'menu' => '☰',
    ];

    /**
     * 获取图标的 Unicode 字符
     */
    public function getIconChar(): string
    {
        $n = trim($this->name);
        if ($n === '') return '';
        // Check built-in map first
        if (isset($this->iconMap[$n])) {
            return $this->iconMap[$n];
        }
        // Fallback: return the name itself (as a Unicode character)
        return $n;
    }
</script>

<style>
/* Icon uses inline styles */
</style>
