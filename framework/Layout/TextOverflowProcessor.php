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
        $textW = function_exists('sk_measure_text_width')
            ? (int)\sk_measure_text_width($text, $fontSize, $bold)
            : (int)(strlen($text) * $fontSize * 0.6);

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
            $testW = function_exists('sk_measure_text_width')
                ? (int)\sk_measure_text_width($testLine, $fontSize, $bold)
                : (int)(strlen($testLine) * $fontSize * 0.6);
            if ($testW <= $containerW) {
                $currentLine = $testLine;
            } else {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $word;
                // 如果单个词超宽，强制截断
                $wordW = function_exists('sk_measure_text_width')
                    ? (int)\sk_measure_text_width($word, $fontSize, $bold)
                    : (int)(strlen($word) * $fontSize * 0.6);
                while ($wordW > $containerW && strlen($currentLine) > 0) {
                    $currentLine = substr($currentLine, 0, -1);
                    $wordW = function_exists('sk_measure_text_width')
                        ? (int)\sk_measure_text_width($currentLine, $fontSize, $bold)
                        : (int)(strlen($currentLine) * $fontSize * 0.6);
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
                $lastLine = mb_substr($lastLine, 0, max(1, mb_strlen($lastLine) - 1)) . "\xE2\x80\xA6";
                $lines[$lineClamp - 1] = $lastLine;
            }
        }

        // Ellipsis for single-line
        if ($lineClamp <= 0 && $textOverflow === 'ellipsis' && count($lines) > 1) {
            $lines = array_slice($lines, 0, 1);
            $lines[0] = mb_substr($lines[0], 0, max(1, mb_strlen($lines[0]) - 1)) . "\xE2\x80\xA6";
        }

        $resultText = implode("\n", $lines);
        return ['text' => $resultText, 'lines' => $lines, 'lineHeight' => $lineHeight];
    }
}
