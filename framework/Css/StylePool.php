<?php

namespace Px\Css;

use native_types;

use Px\Layout\LruNode;

/**
 * StylePool — ComputedStyle 全局 Flyweight 池
 *
 * 对齐 Blink MatchedPropertiesCache：
 *   - Key = 输入路径指纹 (className|elementType|inlineStyleFp|parentObjId)
 *   - Value = 复用 ComputedStyle 实例（identity 稳定，同输入必返同一指针）
 *   - LRU 淘汰，容量上限 512（对齐 TextMeasureCache 风格）
 *
 * 关键设计：
 *   - Key 用父 ComputedStyle 的 spl_object_id() O(1)，避免序列化父数组
 *     （前提：父 ComputedStyle 已经在池里，身份稳定 → 语义等价）
 *   - 双向链表实现 O(1) LRU 淘汰（对齐 TextMeasureCache 已验证的 AOT 兼容模式）
 *   - Empty ComputedStyle 单例（Layout 算法 fallback 用），跳过池 lookup
 *
 * 命中率量化：PerfCounter::inc('style_pool_hit') / 'style_pool_miss' / 'style_pool_evict'
 *
 * AOT 安全：
 *   - 全部静态数组 + 对象属性操作，无闭包
 *   - 复用 Px\Layout\LruNode（?self 类型已验证兼容 aot-syntax-test G16）
 */
class StylePool
{
    private const MAX_SIZE = 512;

    /** @var array<string, ComputedStyle> key → 复用实例 */
    private static array $pool = [];

    /** @var array<string, LruNode> key → LRU 节点 */
    private static array $nodeMap = [];

    private static ?LruNode $head = null;
    private static ?LruNode $tail = null;
    private static int $size = 0;

    /** 空 ComputedStyle 单例（fallback 用） */
    private static ?ComputedStyle $emptySingleton = null;

    /**
     * 命中池即返回复用实例；未命中则构造并入池。
     *
     * @param array               $declarations   已解析的样式声明数组
     * @param ?ComputedStyle      $parentCS       父 ComputedStyle（用于继承 + key 组成）
     * @param string              $elementType    元素类型
     * @param string              $inlineStyleFp  inline style 指纹（O(k) 拼接）
     * @param string              $className      类名（进入 key）
     */
    public static function intern(
        array $declarations,
        ?ComputedStyle $parentCS,
        string $elementType,
        string $inlineStyleFp,
        string $className
    ): ComputedStyle {
        // O(1) key 构建（父身份 O(1)、inline 已在上游指纹化）
        $parentId = $parentCS !== null ? (string)\spl_object_id($parentCS) : '0';
        $key = $className . '|' . $elementType . '|' . $inlineStyleFp . '|' . $parentId;

        // 命中 → 移到 LRU 头部
        if (isset(self::$pool[$key])) {
            self::moveToHead($key);
            \Px\Core\PerfCounter::inc('style_pool_hit');
            return self::$pool[$key];
        }

        // Miss: 构造 + 入池
        $parentDecls = $parentCS !== null ? $parentCS->toExportArray() : [];
        $cs = new ComputedStyle($declarations, $parentDecls, $elementType);

        if (self::$size >= self::MAX_SIZE) {
            self::evict();
        }

        self::$pool[$key] = $cs;
        $node = new LruNode($key);
        self::$nodeMap[$key] = $node;
        self::prependNode($node);
        self::$size++;

        \Px\Core\PerfCounter::inc('style_pool_miss');
        return $cs;
    }

    /**
     * 派生变换：基于已有 ComputedStyle 应用 overrides 后返回池化实例。
     * 用于 :style 动态合并、HTML align 属性合并等场景。
     *
     * @param ComputedStyle $base         基础样式（对象 id 进入 key）
     * @param array         $overrides    要覆盖的键值对
     * @param string        $elementType  元素类型
     */
    public static function withOverride(
        ComputedStyle $base,
        array $overrides,
        string $elementType = 'div'
    ): ComputedStyle {
        if (empty($overrides)) return $base;

        $overrideFp = self::fingerprintOverrides($overrides);
        $key = 'ovr|' . \spl_object_id($base) . '|' . $elementType . '|' . $overrideFp;

        if (isset(self::$pool[$key])) {
            self::moveToHead($key);
            \Px\Core\PerfCounter::inc('style_pool_hit');
            return self::$pool[$key];
        }

        $merged = $base->toExportArray();
        foreach ($overrides as $k => $v) {
            $merged[$k] = $v;
        }
        $cs = new ComputedStyle($merged, [], $elementType);

        if (self::$size >= self::MAX_SIZE) {
            self::evict();
        }

        self::$pool[$key] = $cs;
        $node = new LruNode($key);
        self::$nodeMap[$key] = $node;
        self::prependNode($node);
        self::$size++;

        \Px\Core\PerfCounter::inc('style_pool_miss');
        return $cs;
    }

    /**
     * 空 ComputedStyle 单例（Layout 算法 fallback 用，跳过 pool lookup）
     */
    public static function empty(): ComputedStyle
    {
        if (self::$emptySingleton === null) {
            self::$emptySingleton = new ComputedStyle([]);
        }
        return self::$emptySingleton;
    }

    /**
     * inline style 数组指纹（O(k), k≈10）
     * 上游调用点少（仅 StyleResolver 主入口 + RTM 降级路径），可接受 O(k) 成本。
     */
    public static function fingerprintInline(array $inlineStyle): string
    {
        if (empty($inlineStyle)) return '';
        $parts = [];
        foreach ($inlineStyle as $k => $v) {
            if (\is_scalar($v)) {
                $parts[] = $k . ':' . $v;
            } elseif (\is_object($v)) {
                // CssValue 对象 → 用对象 id（如果对象重用则指纹稳定）
                $parts[] = $k . ':#' . \spl_object_id($v);
            } else {
                $parts[] = $k . ':?';
            }
        }
        return \implode(';', $parts);
    }

    /**
     * overrides 指纹（O(k)）
     */
    private static function fingerprintOverrides(array $overrides): string
    {
        $parts = [];
        foreach ($overrides as $k => $v) {
            if (\is_scalar($v)) {
                $parts[] = $k . '=' . $v;
            } elseif (\is_object($v)) {
                $parts[] = $k . '=#' . \spl_object_id($v);
            } else {
                $parts[] = $k . '=?';
            }
        }
        return \implode(',', $parts);
    }

    /**
     * 全量清空（测试用 + 主题切换用）
     */
    public static function clear(): void
    {
        self::$pool = [];
        self::$nodeMap = [];
        self::$head = null;
        self::$tail = null;
        self::$size = 0;
        self::$emptySingleton = null;
    }

    /**
     * 池状态快照（诊断用）
     */
    public static function stats(): array
    {
        return [
            'size' => self::$size,
            'capacity' => self::MAX_SIZE,
        ];
    }

    // ── LRU 链表操作（AOT 安全：纯对象属性操作，复制自 TextMeasureCache 已验证模式） ──

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

        unset(self::$pool[$tailKey]);
        unset(self::$nodeMap[$tailKey]);
        self::$size--;
        \Px\Core\PerfCounter::inc('style_pool_evict');
    }
}
