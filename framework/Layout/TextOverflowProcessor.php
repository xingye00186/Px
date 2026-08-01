<?php

namespace Px\Layout;

/**
 * TextOverflowProcessor — 文本溢出处理器
 *
 * 处理 text-overflow: ellipsis, -webkit-line-clamp, word-break 等。
 */
class TextOverflowProcessor
{
    /**
     * 处理文本溢出。
     *
     * @param string $text       原始文本
     * @param int    $containerW 容器宽度 (px)
     * @param int    $fontSize   字体大小
     * @param bool   $bold       是否粗体
     * @param array  $style      溢出样式配置
     * @return array ['text' => string, 'lines' => ?array, 'lineHeight' => int]
     */
    public static function process(string $text, int $containerW, int $fontSize, bool $bold, array $style): array
    {
        $textOverflow = $style['textOverflow'] ?? 'clip';
        $overflowWrap = $style['overflowWrap'] ?? 'normal';
        $lineHeight = $style['lineHeight'] > 0 ? $style['lineHeight'] : (int)($fontSize * 1.2);
        $lineClamp = (int)($style['WebkitLineClamp'] ?? 0);

        // 测量文本宽度（依赖 skia C++ 函数）
        $textW = TextMeasureCache::measure($text, $fontSize, (bool)$bold);

        // 如果文本不溢出，直接返回
        if ($textW <= $containerW && $lineClamp <= 0) {
            return ['text' => $text, 'lines' => null, 'lineHeight' => $lineHeight];
        }

        // 简单切割：按字符数估算，每行最大字符数 = containerW / (fontSize * 0.6)
        $charWidth = max(1, (int)($fontSize * 0.6));
        $maxCharsPerLine = max(1, (int)($containerW / $charWidth));
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            $testW = TextMeasureCache::measure($testLine, $fontSize, (bool)$bold);
            if ($testW <= $containerW) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
                // 如果单个词超宽，强制截断
                $wordW = TextMeasureCache::measure($word, $fontSize, (bool)$bold);
                while ($wordW > $containerW && strlen($currentLine) > 0) {
                    $currentLine = substr($currentLine, 0, -1);
                    $wordW = TextMeasureCache::measure($currentLine, $fontSize, (bool)$bold);
                }
            }
        }
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        // Line-clamp
        if ($lineClamp > 0 && count($lines) > $lineClamp) {
            $lines = array_slice($lines, 0, $lineClamp);
            $lastLine = $lines[$lineClamp - 1];
            // Ellipsis
            if ($textOverflow === 'ellipsis') {
                // 去末字符（UTF-8 安全，替代 mb_substr——AOT 运行时无 mbstring）；
                // 至少保留 1 个字符（原 max(1, len-1) 保护语义）
                $chopped = \Px\Text\Utf8::chopLast($lastLine);
                $lastLine = ($chopped === '' && $lastLine !== '') ? $lastLine : $chopped;
                $lastLine .= "\xE2\x80\xA6";
                $lines[$lineClamp - 1] = $lastLine;
            }
        }

        // Ellipsis for single-line
        if ($lineClamp <= 0 && $textOverflow === 'ellipsis' && count($lines) > 1) {
            $lines = array_slice($lines, 0, 1);
            $chopped = \Px\Text\Utf8::chopLast($lines[0]);
            $lines[0] = ($chopped === '' && $lines[0] !== '') ? $lines[0] : $chopped;
            $lines[0] .= "\xE2\x80\xA6";
        }

        $resultText = implode("\n", $lines);
        return ['text' => $resultText, 'lines' => $lines, 'lineHeight' => $lineHeight];
    }
}
