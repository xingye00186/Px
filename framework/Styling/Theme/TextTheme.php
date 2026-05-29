<?php
declare(strict_types=1);

namespace Px\Styling\Theme;

/**
 * 字体令牌，定义各级别字号。
 * 所有值以 px 为单位，int 类型。
 */
class TextTheme
{
    public int $displayLarge;
    public int $displayMedium;
    public int $displaySmall;
    public int $headlineLarge;
    public int $headlineMedium;
    public int $headlineSmall;
    public int $titleLarge;
    public int $titleMedium;
    public int $titleSmall;
    public int $bodyLarge;
    public int $bodyMedium;
    public int $bodySmall;
    public int $labelLarge;
    public int $labelMedium;
    public int $labelSmall;

    /**
     * @param array $values 可选的覆盖值，键名为属性名，值为 int px
     */
    public function __construct(array $values = [])
    {
        $this->displayLarge  = $values['displayLarge']  ?? 57;
        $this->displayMedium = $values['displayMedium'] ?? 45;
        $this->displaySmall  = $values['displaySmall']  ?? 36;
        $this->headlineLarge = $values['headlineLarge'] ?? 32;
        $this->headlineMedium = $values['headlineMedium'] ?? 28;
        $this->headlineSmall = $values['headlineSmall'] ?? 24;
        $this->titleLarge    = $values['titleLarge']    ?? 22;
        $this->titleMedium   = $values['titleMedium']   ?? 16;
        $this->titleSmall    = $values['titleSmall']    ?? 14;
        $this->bodyLarge     = $values['bodyLarge']     ?? 16;
        $this->bodyMedium    = $values['bodyMedium']    ?? 14;
        $this->bodySmall     = $values['bodySmall']     ?? 12;
        $this->labelLarge    = $values['labelLarge']    ?? 14;
        $this->labelMedium   = $values['labelMedium']   ?? 12;
        $this->labelSmall    = $values['labelSmall']    ?? 11;
    }

    public static function default(): self
    {
        return new self();
    }
}
