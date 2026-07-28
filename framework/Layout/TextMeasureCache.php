<?php

namespace Px\Layout;

use native_types;

/**
 * TextMeasureCache — 文本测量缓存（Pretext.js 风格 LRU）
 *
 * 缓存 sk_measure_text_width 结果，避免每帧重复 C++ FFI 调用。
 * 使用双向链表实现 LRU 淘汰（AOT 兼容，?self 类型已验证通过）。
 *
 * AOT 安全：
 *   - 全部静态数组 + 对象属性操作，无闭包
 *   - 双向链表节点使用 ?LruNode 类型（aot-syntax-test G16 已验证）
 *   - char-by-char fallback 不适用（sk_measure_text_width 已是轻量 C++ FFI）
 *
 * 环境变量：PX_TEXT_MEASURE_CACHE=0 可运行时关闭缓存（AB 测试用）
 */
class TextMeasureCache
{
    private const MAX_SIZE = 1024;

    /** @var array<string, int> key → width */
    private static array $cache = [];

    /** @var array<string, LruNode> key → LRU 节点 */
    private static array $nodeMap = [];

    private static ?LruNode $head = null;
    private static ?LruNode $tail = null;
    private static int $size = 0;

    /** @var bool|null native 函数是否可用（静态缓存避免重复 function_exists） */
    private static ?bool $hasNative = null;

    /**
     * 测量文本宽度（含 LRU 缓存）
     *
     * @param string $text     测量文本
     * @param int    $fontSize 字号（px）
     * @param bool   $bold     是否加粗
     * @return int 宽度（px）
     */
    public static function measure(string $text, int $fontSize, bool $bold): int
    {
        // native 不可用时走 fallback（测试环境无 C++ 绑定），不缓存
        if (self::$hasNative === null) {
            self::$hasNative = function_exists('\\sk_measure_text_width');
        }
        if (!self::$hasNative) {
            $boldFactor = $bold ? 1.35 : 1.0;
            // 按字符类度量（非按字节）：ASCII ≈ 0.6em/字符，多字节字符（CJK 等）
            // ≈ 1.0em/字（旧 strlen 字节计把 CJK 算 3×0.6=1.8em/字，系统性高估
            // ~80%：case-050 伪元素 CJK 宽 E144 vs B≈84，x=60 族实锤）。
            $asciiOnly = preg_replace('/[\x80-\xFF]+/', '', $text);
            $asciiLen = strlen($asciiOnly !== null ? $asciiOnly : $text);
            $multiLen = max(0, mb_strlen($text, 'UTF-8') - $asciiLen);
            return (int)(($asciiLen * $fontSize * 0.6 + $multiLen * $fontSize) * $boldFactor);
        }

        // 环境变量开关：PX_TEXT_MEASURE_CACHE=0 跳过缓存（AB 测试）
        if (getenv('PX_TEXT_MEASURE_CACHE') === '0') {
            \Px\Core\PerfCounter::inc('text_measure_bypass');
            return (int)\sk_measure_text_width($text, $fontSize, $bold ? 1 : 0);
        }

        $key = $fontSize . '|' . ($bold ? '1' : '0') . '|' . $text;

        // 缓存命中 → 更新 LRU 顺序
        if (isset(self::$cache[$key])) {
            \Px\Core\PerfCounter::inc('text_measure_hit');
            self::moveToHead($key);
            return self::$cache[$key];
        }

        \Px\Core\PerfCounter::inc('text_measure_miss');
        $width = (int)\sk_measure_text_width($text, $fontSize, $bold ? 1 : 0);

        // 容量满 → 淘汰最久未用
        if (self::$size >= self::MAX_SIZE) {
            self::evict();
        }

        self::$cache[$key] = $width;
        $node = new LruNode($key);
        self::$nodeMap[$key] = $node;
        self::prependNode($node);
        self::$size++;

        return $width;
    }

    /**
     * 清空缓存（主题切换/字号全局变化时调用）
     */
    public static function reset(): void
    {
        self::$cache = [];
        self::$nodeMap = [];
        self::$head = null;
        self::$tail = null;
        self::$size = 0;
    }

    // ── LRU 链表操作（AOT 安全：纯对象属性操作） ──

    /** 将节点移动到链表头部（最近使用） */
    private static function moveToHead(string $key): void
    {
        $node = self::$nodeMap[$key] ?? null;
        if ($node === null || $node === self::$head) return;

        // 从当前位置摘出
        if ($node->prev !== null) {
            $node->prev->next = $node->next;
        }
        if ($node->next !== null) {
            $node->next->prev = $node->prev;
        }
        if ($node === self::$tail) {
            self::$tail = $node->prev;
        }

        // 插入头部
        $node->prev = null;
        $node->next = self::$head;
        if (self::$head !== null) {
            self::$head->prev = $node;
        }
        self::$head = $node;
    }

    /** 在链表头部插入新节点 */
    private static function prependNode(LruNode $node): void
    {
        $node->prev = null;
        $node->next = self::$head;
        if (self::$head !== null) {
            self::$head->prev = $node;
        }
        self::$head = $node;
        if (self::$tail === null) {
            self::$tail = $node;
        }
    }

    /** 淘汰尾部节点（最久未用） */
    private static function evict(): void
    {
        if (self::$tail === null) return;

        $tailKey = self::$tail->key;
        self::$tail = self::$tail->prev;
        if (self::$tail !== null) {
            self::$tail->next = null;
        } else {
            self::$head = null;
        }

        unset(self::$cache[$tailKey]);
        unset(self::$nodeMap[$tailKey]);
        self::$size--;
    }
}
