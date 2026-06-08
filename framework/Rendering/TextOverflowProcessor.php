<?php

namespace Px\Rendering;

/**
 * TextOverflowProcessor — 文本溢出/省略处理
 *
 * 从 VNodeRenderer::makeSpanElement() 提取，职责单一：处理 text-overflow:ellipsis
 * 和 -webkit-line-clamp 的文本截断逻辑。
 */
class TextOverflowProcessor
{
    /**
     * 处理文本溢出截断。
     *
     * @param string $text        原始文本
     * @param int    $containerW  容器宽度 (px)，用于截断判断
     * @param int    $fontSize    字号
     * @param bool   $bold        是否粗体
     * @param array  $style       完整 style 数组（含 WebkitLineClamp/lineHeight 等）
     * @return array{text:string, lines:?array, lineHeight:int}
     *         返回处理后结果：
     *          text:  单行最终文本（不含截断时为原文本）
     *          lines: 多行模式（line-clamp>1）返回行数组，单行返回 null
     *          lineHeight: 行高（多行模式有效，单行返回 0）
     */
    public static function process(string $text, int $containerW, int $fontSize, bool $bold, array $style): array
    {
        $result = ['text' => $text, 'lines' => null, 'lineHeight' => 0];

        $textOverflow = $style['textOverflow'] ?? 'clip';
        if ($textOverflow !== 'ellipsis' || $containerW <= 0) {
            return $result;
        }

        $lineClamp = (int)($style['WebkitLineClamp'] ?? $style['webkitLineClamp'] ?? 0);
        $availWidth = $containerW - 4; // 4px 内边距

        // ── 文本测量闭包 ──
        $measureTextWidth = function (string $str) use ($fontSize, $bold): int {
            static $hasNative = null;
            if ($hasNative === null) $hasNative = function_exists('\\sk_measure_text_width');
            if ($hasNative) {
                return (int)\sk_measure_text_width($str, $fontSize, $bold);
            }
            $boldFactor = $bold ? 1.35 : 1.0;
            $charW = (int)($fontSize * 0.6 * $boldFactor);
            $cjkW  = (int)($fontSize * $boldFactor);
            $len   = strlen($str);
            $total = 0;
            for ($i = 0; $i < $len;) {
                $b = ord($str[$i]);
                if ($b < 0x80) {
                    $total += $charW; $i++;
                } elseif ($b < 0xC0) {
                    $i++;
                } elseif ($b < 0xE0) {
                    $total += $cjkW; $i += 2;
                } elseif ($b < 0xF0) {
                    $total += $cjkW; $i += 3;
                } else {
                    $total += $cjkW; $i += 4;
                }
            }
            return $total;
        };

        if ($lineClamp > 0) {
            // ── 多行模式：逐字符拆分行 ──
            $lineHeight = (int)($style['lineHeight'] ?? 0);
            if ($lineHeight <= 0) {
                $lineHeight = (int)($fontSize * 1.4);
            }

            $lines = [];
            $currentLine = '';
            $len = strlen($text);
            for ($i = 0; $i < $len;) {
                $charLen = 1;
                $b = ord($text[$i]);
                if ($b >= 0xF0) $charLen = 4;
                elseif ($b >= 0xE0) $charLen = 3;
                elseif ($b >= 0xC0) $charLen = 2;
                $chunk = substr($text, $i, $charLen);
                $candidate = $currentLine . $chunk;
                if ($measureTextWidth($candidate) > $availWidth && $currentLine !== '') {
                    $lines[] = $currentLine;
                    if (count($lines) >= $lineClamp) break;
                    $currentLine = $chunk;
                } else {
                    $currentLine = $candidate;
                }
                $i += $charLen;
            }
            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }

            if (count($lines) > $lineClamp) {
                $lines = array_slice($lines, 0, $lineClamp);
                $lastIdx = count($lines) - 1;
                $lastLine = $lines[$lastIdx];
                $len2 = strlen($lastLine);
                for ($j = $len2; $j > 0;) {
                    $b = ord($lastLine[$j - 1]);
                    $charLen = 1;
                    if ($b >= 0xF0) { $j -= 4; $charLen = 4; }
                    elseif ($b >= 0xE0) { $j -= 3; $charLen = 3; }
                    elseif ($b >= 0xC0) { $j -= 2; $charLen = 2; }
                    else { $j--; $charLen = 1; }
                    $trimmed = substr($lastLine, 0, $j) . '…';
                    if ($measureTextWidth($trimmed) <= $availWidth) {
                        $lastLine = $trimmed;
                        break;
                    }
                }
                if ($j <= 0) $lastLine = '…';
                $lines[$lastIdx] = $lastLine;
            }

            if (count($lines) > 0) {
                $result['text'] = $lines[0];
                $result['lines'] = $lines;
                $result['lineHeight'] = $lineHeight;
                return $result;
            }
        } else {
            // 单行模式
            if ($measureTextWidth($text) > $availWidth) {
                $len = strlen($text);
                for ($j = $len; $j > 0;) {
                    $b = ord($text[$j - 1]);
                    $charLen = 1;
                    if ($b >= 0xF0) { $j -= 4; $charLen = 4; }
                    elseif ($b >= 0xE0) { $j -= 3; $charLen = 3; }
                    elseif ($b >= 0xC0) { $j -= 2; $charLen = 2; }
                    else { $j--; $charLen = 1; }
                    $trimmed = substr($text, 0, $j) . '…';
                    if ($measureTextWidth($trimmed) <= $availWidth) {
                        $text = $trimmed;
                        break;
                    }
                }
                if ($j <= 0) $text = '…';
            }
            $result['text'] = $text;
        }

        return $result;
    }
}
