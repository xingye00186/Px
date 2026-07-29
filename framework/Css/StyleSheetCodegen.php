<?php

namespace Px\Css;

/**
 * StyleSheetCodegen — RuleData 列表 → PHP 数组字面量代码串（C2.3）。
 *
 * 将 StyleSheetContents::build 产出的规则表序列化为 AOT 友好的 PHP 数组
 * 字面量，烘入 gen 组件的 `styleSheetContents()` 静态方法体。运行时暂不
 * 消费（零行为变化）；C2.4/C2.5 由运行时通道/规则 ID 级烘焙消费。
 *
 * 纯常量数组（无对象、无闭包、无动态调用），符合 AOT 约束。
 */
final class StyleSheetCodegen
{
    /**
     * @param array $rules StyleSheetContents::build 结果
     * @return string PHP 数组字面量（可直接嵌入 return <expr>;）
     */
    public static function emit(array $rules): string
    {
        if (empty($rules)) return '[]';
        $parts = [];
        foreach ($rules as $r) {
            $parts[] = self::emitRule($r);
        }
        return "[\n" . implode(",\n", $parts) . "\n]";
    }

    /**
     * 生成注入 gen 组件的静态方法（AOT 友好；运行时不消费）。
     */
    public static function emitMethod(array $rules): string
    {
        $literal = self::emit($rules);
        return "    /**\n"
            . "     * C2.3: 编译期规则存储（StyleSheetContents/RuleData）。\n"
            . "     * AOT 友好常量数组；运行时暂不消费（C2.4/C2.5 接管）。\n"
            . "     */\n"
            . "    public static function styleSheetContents(): array\n"
            . "    {\n"
            . "        return {$literal};\n"
            . "    }\n";
    }

    private static function emitRule(array $r): string
    {
        $sel  = self::str($r['selector'] ?? '');
        $decl = self::str($r['declarations'] ?? '');
        $spec = self::intArray($r['specificity'] ?? [0, 0, 0, 0]);
        $order = (int)($r['order'] ?? 0);
        $scope = self::str($r['scopeId'] ?? '');
        $ast  = self::emitValue($r['ast'] ?? []);
        return "    ['selector' => {$sel}, 'declarations' => {$decl}, "
            . "'specificity' => {$spec}, 'order' => {$order}, 'scopeId' => {$scope}, "
            . "'ast' => {$ast}]";
    }

    /** 递归序列化任意（数组/标量）为 PHP 字面量。 */
    private static function emitValue($v): string
    {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            $items = [];
            foreach ($v as $k => $vv) {
                $val = self::emitValue($vv);
                if ($isList) {
                    $items[] = $val;
                } else {
                    $items[] = self::keyLiteral($k) . ' => ' . $val;
                }
            }
            return '[' . implode(', ', $items) . ']';
        }
        if (is_int($v)) return (string)$v;
        if (is_float($v)) return (string)$v;
        if (is_bool($v)) return $v ? 'true' : 'false';
        if ($v === null) return 'null';
        return self::str((string)$v);
    }

    private static function keyLiteral($k): string
    {
        return is_int($k) ? (string)$k : self::str((string)$k);
    }

    private static function str(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }

    private static function intArray(array $a): string
    {
        $ints = array_map(static fn($x) => (string)(int)$x, $a);
        return '[' . implode(', ', $ints) . ']';
    }
}
