<?php

namespace Px\Css;

/**
 * CascadeResolver — CSS 层叠决策纯函数（C1.1）。
 *
 * 对标 Blink StyleCascade + CascadePriority(origin, importance, position) 的
 * 声明集简化形：输入 = 带元数据（origin / specificity / 源顺序 / important）的
 * 声明集，输出 = 合并后声明数组（property => value）。
 *
 * 槽位序（对齐 CSS Cascade 4 §6.1，低→高）：
 *   UA normal → author normal → inline normal（style 属性，胜过任何选择器）
 *   → author !important → inline !important → UA !important
 * specificity 与源顺序只在同槽位内部比较。
 *
 * 注：animation origin 在 Blink 中是独立 cascade 层（高于全部 normal、低于
 * !important）。Px 现状在 RenderNode::$animatedStyle 渲染树层叠加，不进 cascade
 * （有意偏差）。此处仅预留槽位常量 SLOT_ANIMATION 不实现，防未来接入时重排序。
 *
 * 本类为纯函数，无副作用、无状态；C1.1 不接线（零行为变化），
 * C1.3 由 mergeClassStylesIntoNode / resolveClassStyles 改调本类统一层叠序。
 */
final class CascadeResolver
{
    // origin 枚举
    public const ORIGIN_UA        = 'ua';
    public const ORIGIN_AUTHOR    = 'author';
    public const ORIGIN_INLINE    = 'inline';
    /** 预留：动画 origin（Blink 独立 cascade 层）；Px 现不进 cascade，仅占位不实现。 */
    public const ORIGIN_ANIMATION = 'animation';

    // 槽位序（低→高）；数值越大优先级越高
    private const SLOT_UA_NORMAL        = 0;
    private const SLOT_AUTHOR_NORMAL    = 1;
    /** 预留：animation 槽（介于 author normal 与 inline normal 之间，未实现）。 */
    private const SLOT_ANIMATION        = 2;
    private const SLOT_INLINE_NORMAL    = 3;
    private const SLOT_AUTHOR_IMPORTANT = 4;
    private const SLOT_INLINE_IMPORTANT = 5;
    private const SLOT_UA_IMPORTANT     = 6;

    /**
     * 层叠合并。
     *
     * @param array $declarations 声明列表，每项为关联数组：
     *   [
     *     'property'    => string     属性名（如 'color'）
     *     'value'       => mixed      属性值（原样透传，不解析）
     *     'origin'      => string     ORIGIN_* 之一（默认 author）
     *     'important'   => bool        是否 !important（默认 false）
     *     'specificity' => int[]       [a,b,c,d]（默认 [0,0,0,0]）
     *     'order'       => int         源顺序（同槽位 tie-break，默认按数组下标）
     *   ]
     * @return array property => value 的胜出声明映射
     */
    public static function resolve(array $declarations): array
    {
        // 每属性保留当前胜出者的比较元组，末尾一次性收敛为 value 映射。
        /** @var array<string, array{value:mixed, slot:int, spec:array, order:int}> $winners */
        $winners = [];

        foreach ($declarations as $i => $decl) {
            $property = $decl['property'] ?? null;
            if ($property === null || $property === '') {
                continue;
            }
            $origin    = (string)($decl['origin'] ?? self::ORIGIN_AUTHOR);
            $important = (bool)($decl['important'] ?? false);
            $spec      = $decl['specificity'] ?? [0, 0, 0, 0];
            $order     = (int)($decl['order'] ?? $i);
            $slot      = self::slotOf($origin, $important);

            $candidate = [
                'value' => $decl['value'] ?? null,
                'slot'  => $slot,
                'spec'  => is_array($spec) ? $spec : [0, 0, 0, 0],
                'order' => $order,
            ];

            $current = $winners[$property] ?? null;
            if ($current === null || self::beats($candidate, $current)) {
                $winners[$property] = $candidate;
            }
        }

        $result = [];
        foreach ($winners as $property => $w) {
            $result[$property] = $w['value'];
        }
        return $result;
    }

    /**
     * 层叠排序（块级，C1.3 接线入口）：对"声明块"列表按层叠序稳定排序
     *（低→高），供串接/顺序覆盖消费。值级烘焙时代两通道（编译期
     * mergeClassStylesIntoNode 串拼 / 运行时 resolveClassStyles 键覆盖）均以
     * "后写者胜"消费块序，排序决策单点化即层叠序统一；属性级去重
     *（resolve()）待 C2.5 规则 ID 级烘焙接管。
     *
     * @param array $blocks 每项：['payload'=>mixed, 'origin'=>, 'important'=>, 'specificity'=>, 'order'=>]
     * @return array 按层叠序（低→高）排列的原块列表
     */
    public static function sortDeclarationBlocks(array $blocks): array
    {
        $keyed = [];
        foreach ($blocks as $i => $b) {
            $keyed[] = [
                'block' => $b,
                'slot'  => self::slotOf((string)($b['origin'] ?? self::ORIGIN_AUTHOR), (bool)($b['important'] ?? false)),
                'spec'  => is_array($b['specificity'] ?? null) ? $b['specificity'] : [0, 0, 0, 0],
                'order' => (int)($b['order'] ?? $i),
                'seq'   => $i, // 稳定性：全同则保输入序
            ];
        }
        usort($keyed, static function (array $a, array $b): int {
            if ($a['slot'] !== $b['slot']) return $a['slot'] <=> $b['slot'];
            $sc = self::compareSpecificity($a['spec'], $b['spec']);
            if ($sc !== 0) return $sc;
            if ($a['order'] !== $b['order']) return $a['order'] <=> $b['order'];
            return $a['seq'] <=> $b['seq'];
        });
        $out = [];
        foreach ($keyed as $k) {
            $out[] = $k['block'];
        }
        return $out;
    }

    /**
     * candidate 是否击败 current（成为新胜出者）。
     * 比较序：slot（origin+importance）> specificity > 源顺序（后者胜）。
     */
    private static function beats(array $candidate, array $current): bool
    {
        if ($candidate['slot'] !== $current['slot']) {
            return $candidate['slot'] > $current['slot'];
        }
        $sc = self::compareSpecificity($candidate['spec'], $current['spec']);
        if ($sc !== 0) {
            return $sc > 0;
        }
        // 同槽位同 specificity：源顺序靠后者胜（>= 使等序时后来者覆盖）
        return $candidate['order'] >= $current['order'];
    }

    /**
     * origin + importance → 槽位序号（越大优先级越高）。
     */
    private static function slotOf(string $origin, bool $important): int
    {
        if ($important) {
            return match ($origin) {
                self::ORIGIN_UA       => self::SLOT_UA_IMPORTANT,
                self::ORIGIN_INLINE   => self::SLOT_INLINE_IMPORTANT,
                default               => self::SLOT_AUTHOR_IMPORTANT, // author + 未知
            };
        }
        return match ($origin) {
            self::ORIGIN_UA        => self::SLOT_UA_NORMAL,
            self::ORIGIN_INLINE    => self::SLOT_INLINE_NORMAL,
            self::ORIGIN_ANIMATION => self::SLOT_ANIMATION,
            default                => self::SLOT_AUTHOR_NORMAL, // author + 未知
        };
    }

    /**
     * specificity 比较：[a,b,c,d] 字典序。A>B 返 1，A<B 返 -1，相等 0。
     * 与 CssMappings::compareSpecificity 语义一致（本类自持以保纯函数无依赖）。
     */
    public static function compareSpecificity(array $specA, array $specB): int
    {
        for ($i = 0; $i < 4; $i++) {
            $sa = (int)($specA[$i] ?? 0);
            $sb = (int)($specB[$i] ?? 0);
            if ($sa !== $sb) {
                return $sa > $sb ? 1 : -1;
            }
        }
        return 0;
    }
}
