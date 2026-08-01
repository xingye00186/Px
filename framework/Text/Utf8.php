<?php

namespace Px\Text;

use native_types;

/**
 * Utf8 — AOT 兼容的 UTF-8 字符串工具（替代 mbstring 依赖）
 *
 * Swoole Compiler 的 AOT 运行时（php8ts.dll）不包含 mbstring 扩展，
 * mb_strtoupper/mb_strtolower/mb_substr/mb_strlen 在 exe 中全部
 * undefined → text-transform/多字节测量/ellipsis 截断崩溃。
 *
 * 对齐 CSS Text 3 §4.1.1 的务实边界（Blink 用 ICU，Px 无 ICU）：
 *   - ASCII 全集：PHP 原生 strtoupper/strtolower
 *   - Latin-1 补充区（U+00E0-U+00FF）：静态映射表（覆盖欧洲语言）
 *   - CJK 等其余码点：无大小写概念，保持原样（Blink 亦不转换）
 * 码点数统计用 preg（PCRE 在 AOT 运行时可用），逐字节 UTF-8 定界。
 *
 * AOT 约束：静态类、无闭包、整数确定性算术、常量数组。
 */
class Utf8
{
    /** Latin-1 小写 → 大写映射（U+00E0-U+00FF + ÿ→Ÿ） */
    private const LOWER_TO_UPPER = [
        'à' => 'À', 'á' => 'Á', 'â' => 'Â', 'ã' => 'Ã', 'ä' => 'Ä', 'å' => 'Å',
        'æ' => 'Æ', 'ç' => 'Ç', 'è' => 'È', 'é' => 'É', 'ê' => 'Ê', 'ë' => 'Ë',
        'ì' => 'Ì', 'í' => 'Í', 'î' => 'Î', 'ï' => 'Ï', 'ð' => 'Ð', 'ñ' => 'Ñ',
        'ò' => 'Ò', 'ó' => 'Ó', 'ô' => 'Ô', 'õ' => 'Õ', 'ö' => 'Ö', 'ø' => 'Ø',
        'ù' => 'Ù', 'ú' => 'Ú', 'û' => 'Û', 'ü' => 'Ü', 'ý' => 'Ý', 'þ' => 'Þ',
        'ÿ' => 'Ÿ',
    ];

    /** Latin-1 大写 → 小写映射（U+00C0-U+00DE + Ÿ→ÿ） */
    private const UPPER_TO_LOWER = [
        'À' => 'à', 'Á' => 'á', 'Â' => 'â', 'Ã' => 'ã', 'Ä' => 'ä', 'Å' => 'å',
        'Æ' => 'æ', 'Ç' => 'ç', 'È' => 'è', 'É' => 'é', 'Ê' => 'ê', 'Ë' => 'ë',
        'Ì' => 'ì', 'Í' => 'í', 'Î' => 'î', 'Ï' => 'ï', 'Ð' => 'ð', 'Ñ' => 'ñ',
        'Ò' => 'ò', 'Ó' => 'ó', 'Ô' => 'ô', 'Õ' => 'õ', 'Ö' => 'ö', 'Ø' => 'ø',
        'Ù' => 'ù', 'Ú' => 'ú', 'Û' => 'û', 'Ü' => 'ü', 'Ý' => 'ý', 'Þ' => 'þ',
        'Ÿ' => 'ÿ',
    ];

    /**
     * 大写转换（ASCII + Latin-1；CJK 等无大小写保持原样）
     */
    public static function upper(string $s): string
    {
        return strtr(strtoupper($s), self::LOWER_TO_UPPER);
    }

    /**
     * 小写转换（ASCII + Latin-1）
     */
    public static function lower(string $s): string
    {
        return strtr(strtolower($s), self::UPPER_TO_LOWER);
    }

    /**
     * 非 ASCII 码点数（CJK 等）——统计 UTF-8 多字节字符个数。
     * 替代 mb_strlen($s,'UTF-8') - strlen(preg_replace 去高位)。
     * preg 带 /u 修饰符按码点匹配，PCRE 在 AOT 运行时可用。
     */
    public static function multiCharCount(string $s): int
    {
        if ($s === '') {
            return 0;
        }
        $n = preg_match_all('/[^\x00-\x7F]/u', $s);
        return $n !== false ? $n : 0;
    }

    /**
     * 去掉最后一个完整 UTF-8 字符（含 ASCII）。
     * 替代 mb_substr($s, 0, max(1, mb_strlen($s)-1))：从尾部跳过
     * UTF-8 连续字节（0x80-0xBF）定位字符边界。
     */
    public static function chopLast(string $s): string
    {
        $len = strlen($s);
        if ($len <= 1) {
            return '';
        }
        $i = $len - 1;
        while ($i > 0 && (ord($s[$i]) & 0xC0) === 0x80) {
            $i--;
        }
        return substr($s, 0, $i);
    }

    /**
     * 取第一个完整 UTF-8 字符（含 ASCII）。
     * 替代 mb_substr($s, 0, 1, 'UTF-8')。
     */
    public static function firstChar(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $b = ord($s[0]);
        if ($b < 0x80) {
            return $s[0];
        }
        if ($b < 0xE0) {
            return substr($s, 0, 2);
        }
        if ($b < 0xF0) {
            return substr($s, 0, 3);
        }
        return substr($s, 0, 4);
    }

    /**
     * 去掉第一个完整 UTF-8 字符（含 ASCII）。
     * 替代 mb_substr($s, 1, null, 'UTF-8')。
     */
    public static function restAfterFirst(string $s): string
    {
        $first = self::firstChar($s);
        return substr($s, strlen($first));
    }
}
