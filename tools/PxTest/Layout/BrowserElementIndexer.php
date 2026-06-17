<?php

namespace PxTest\Layout;

/**
 * 浏览器元素索引器 — 将浏览器参考 JSON 的元素按文本索引。
 */
class BrowserElementIndexer
{
    /**
     * @param array<int, array> $elements 浏览器参考的 elements 数组
     * @return array<string, array> 文本→元素映射
     */
    public function indexByText(array $elements): array
    {
        $index = [];
        foreach ($elements as $el) {
            $text = trim(str_replace("\r\n", "\n", $el['text'] ?? ''));
            if ($text === '') continue;
            if (mb_strlen($text) < 2) continue;

            $index[$text] = [
                'x' => $el['x'] ?? 0,
                'y' => $el['y'] ?? 0,
                'relX' => $el['x'] ?? 0,
                'relY' => $el['y'] ?? 0,
                'w' => $el['w'] ?? 0,
                'h' => $el['h'] ?? 0,
                'tag' => $el['tag'] ?? 'div',
                'styles' => $el['styles'] ?? [],
                'cid' => $el['cid'] ?? 0,
            ];
        }
        return $index;
    }

    /** 查找最匹配的索引条目 */
    public function findBestMatch(array $index, string $text): ?array
    {
        if (isset($index[$text])) return $index[$text];

        // 前缀匹配
        $short = mb_substr($text, 0, 20);
        foreach ($index as $key => $val) {
            if (str_starts_with($key, $short)) return $val;
        }
        return null;
    }
}
