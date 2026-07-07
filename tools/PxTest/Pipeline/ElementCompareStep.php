<?php

namespace PxTest\Pipeline;

use PxTest\Comparison\ComparatorRegistry;

/**
 * Element compare step — 对比引擎与浏览器的扁平 elements[] 数据。
 *
 * 浏览器数据是黄金参照标杆，引擎缺失的属性应作为增强导出的目标。
 * 差异分类输出：
 *   [MISSING]  引擎缺失导出（浏览器有，引擎没有）→ 需增强引擎
 *   [MISMATCH] 值不一致（两边都有但值不同）→ 格式/计算差异
 *   [GEOMETRY] 位置/尺寸偏差
 *   [STRUCTURE] 元素数量/tag 差异
 */
class ElementCompareStep implements PipelineStepInterface
{
    /**
     * 引擎导出但浏览器不导出的默认值白名单——这些不计入真实 MISMATCH。
     * 引擎在序列化时总是输出某些 CSS 初始值，而浏览器不显示它们。
     */
    /**
     * 引擎导出但浏览器不导出的默认值白名单——这些不计入真实 MISMATCH。
     * 引擎在序列化时总是输出某些 CSS 初始值，而浏览器不显示它们。
     * 引擎还按四边单独导出边框属性，浏览器只用 border-width/color 简写。
     */
    private static array $ENGINE_DEFAULT_ONLY_KEYS = [
        'font-style', 'white-space', 'word-break', 'visibility',
        'cursor', 'direction', 'pointer-events',
        'box-sizing', 'border-style',
        // font-family: engine exports as lowercase with single quotes, browser has proper case
        // This is a serialization format artifact, not a real CSS difference.
        'font-family',
        // flex-grow/flex-shrink: engine always exports them, browser only when non-default
        'flex-grow', 'flex-shrink',
        // min/max constraints: engine exports from style, browser getComputedStyle may not show them
        'min-width', 'min-height', 'max-width', 'max-height',
        // border per-side: engine exports all 4, browser only has shorthand
        'border-top-width', 'border-top-color',
        'border-right-width', 'border-right-color',
        'border-bottom-width', 'border-bottom-color',
        'border-left-width', 'border-left-color',
        // outline individual props: engine exports them, browser only has shorthand 'outline'
        'outline-width', 'outline-style', 'outline-color',
        // box-shadow: engine uses pipe-delimited format, browser getComputedStyle may not show it
        'box-shadow',
        // align-content: engine always exports, browser only when non-default (flex-wrap container)
        'align-content',
    ];

    /**
     * 引擎默认导出的字体属性——引擎与浏览器序列化格式不一致，
     * 这些差异是渲染引擎精度限制（B-018/S-001 已知），非布局 Bug。
     * 跳过这些属性的 MISMATCH 对比，专注布局几何偏差。
     */
    private static array $FONT_SERIALIZATION_KEYS = [
        'font-weight', 'font-size', 'color', 'line-height',
        'font-style', 'font-family', 'text-align',
        'letter-spacing', 'word-spacing', 'word-break', 'white-space',
        'font-variant', 'font-stretch',
    ];

    /**
     * 引擎缺失的浏览器属性白名单——这些 MISSING 不计入失败（引擎不导出默认值）。
     */
    private static array $BROWSER_DEFAULT_SKIP_KEYS = [
        'font-size', 'background-color', 'border-left-width', 'border-left-color',
        'border-width', 'border-color', 'border-radius',
        'padding-top', 'padding-left', 'padding-right', 'padding-bottom',
        'margin-top', 'margin-left', 'margin-right', 'margin-bottom',
        'font-family', 'line-height',
        'flex-direction', 'flex-wrap', 'overflow-x', 'overflow-y',
        'flex-grow', 'flex-shrink',
        // min/max: browser may export default values (0px/auto) that engine doesn't
        'min-width', 'min-height', 'max-width', 'max-height',
        'display',
        'border-style',
        // flex-item getComputedStyle artifact: flex items inherit align-items/justify-content
        // from parent flex container in getComputedStyle, but engine correctly doesn't set them.
        'align-items', 'justify-content',
        // overflow shorthand: engine only exports overflowX/overflowY individually,
        // not the shorthand. When both are same value, overflow shorthand is redundant.
        'overflow',
        // text-align: engine start/left vs browser justify/match-parent are contextual defaults
        'text-align',
    ];

    private ComparatorRegistry $registry;
    private string $caseDir;
    private string $caseName;

    public function __construct(ComparatorRegistry $registry = null, string $caseDir = '', string $caseName = '') {
        $this->registry = $registry ?? ComparatorRegistry::default();
        $this->caseDir = $caseDir;
        $this->caseName = $caseName;
    }
    public function name(): string { return 'element_compare'; }
    public function requires(): array { return ['browser_ref']; }

    public function execute(CaseContext $ctx): StepResult
    {
        // 从上下文获取当前 case 名（每个 case 独立设置），覆盖构造时默认值
        $ctxCase = $ctx->get('case_name');
        if ($ctxCase !== null && $ctxCase !== '') {
            $this->caseName = $ctxCase;
            // caseDir 的父目录 = test_case/ 目录，拼接新 case 名
            $testCaseDir = dirname($this->caseDir);
            $this->caseDir = $testCaseDir . '/' . $ctxCase;
        }

        $engineRaw = $ctx->get('engine_ref') ?? $ctx->get('layout_json');
        $browserRaw = $ctx->get('browser_ref');
        if ($engineRaw === null || $browserRaw === null) {
            return StepResult::err('element_compare', 'Missing data');
        }

        $engineData = json_decode($engineRaw, true);
        $browserData = json_decode($browserRaw, true);
        if ($engineData === null) return StepResult::err('element_compare', 'Invalid engine JSON');
        if ($browserData === null) return StepResult::err('element_compare', 'Invalid browser ref JSON');

        $engineElements = $engineData['elements'] ?? [];
        $browserElements = $browserData['elements'] ?? [];

        $missingDiffs = [];   // 引擎缺失：浏览器有但引擎没有
        $mismatchDiffs = [];  // 值不一致：两边都有但不同
        $geoDiffs = [];       // 几何偏差
        $structDiffs = [];    // 结构差异（数量/tag）

        $tol = new \PxTest\Core\ToleranceConfig();

        // ─── 锚点定位 → 提取内容子树 → 坐标归一化对比 ───
        // 以 TL 锚点的父容器作为内容根，只提取该容器下的子节点进行对比
        // 跳过引擎的 app 外壳元素，让两边索引对齐在测试内容区域
        $engineAnchor = $this->findAnchor($engineElements, 'tl');
        $engineContent = $this->extractContentSubtree($engineElements, $engineAnchor);
        $browserAnchor = $this->findAnchor($browserElements, 'tl');
        $browserContent = $this->extractContentSubtree($browserElements, $browserAnchor);

        $eAnchor = $engineContent['anchor'] ?? [0, 0];
        $bAnchor = $browserContent['anchor'] ?? [0, 0];
        $engineSubset = $engineContent['elements'] ?? [];
        $browserSubset = $browserContent['elements'] ?? [];

        echo "  [CONTENT] engine: {$engineContent['count']} elements";
        if (isset($engineContent['skipped'])) {
            echo " (skipped {$engineContent['skipped']} app chrome)";
        }
        echo " | browser: {$browserContent['count']} elements";
        if (isset($browserContent['skipped'])) {
            echo " (skipped {$browserContent['skipped']} app chrome)";
        }
        // 锚点归一化：内容区内元素坐标转为相对锚点的偏移
        echo " | anchor origin: engine=({$eAnchor[0]},{$eAnchor[1]}) browser=({$bAnchor[0]},{$bAnchor[1]})";
        echo "\n";

        // ─── 锚点跨度校验：TL→BR 距离在引擎与浏览器中应一致 ───
        $engineBr = $this->findAnchor($engineSubset, 'br');
        $browserBr = $this->findAnchor($browserSubset, 'br');
        if ($engineBr !== null && $browserBr !== null) {
            $eSpanW = abs((int)$engineBr['x'] - (int)$eAnchor[0]);
            $eSpanH = abs((int)$engineBr['y'] - (int)$eAnchor[1]);
            $bSpanW = abs((int)$browserBr['x'] - (int)$bAnchor[0]);
            $bSpanH = abs((int)$browserBr['y'] - (int)$bAnchor[1]);
            echo "  [ANCHOR_SPAN] engine TL→BR: {$eSpanW}x{$eSpanH} | browser TL→BR: {$bSpanW}x{$bSpanH}\n";
            $spanWTol = max(5, (int)($bSpanW * 0.05));
            $spanHTol = max(5, (int)($bSpanH * 0.05));
            if (abs($eSpanW - $bSpanW) > $spanWTol) {
                echo "  [ANCHOR_WARN] TL→BR width mismatch: engine={$eSpanW}px browser={$bSpanW}px (tolerance={$spanWTol}px). Check HTML body height / wrapper structure\n";
            }
            if (abs($eSpanH - $bSpanH) > $spanHTol) {
                echo "  [ANCHOR_WARN] TL→BR height mismatch: engine={$eSpanH}px browser={$bSpanH}px (tolerance={$spanHTol}px). Check HTML body height / wrapper structure\n";
            }
        }
        $eCount = count($engineSubset);
        $bCount = count($browserSubset);

        // ─── 纯 data-px-id 匹配 ───
        // 注入器确保所有元素都有 data-px-id，匹配 100% 靠 px-id
        // 若任一侧无 px-id，说明注入失败，直接终止
        $matchPairs = [];
        $eByPxId = [];
        $bByPxId = [];
        foreach ($engineSubset as $idx => $el) {
            $pid = $el['dataset']['pxId'] ?? null;
            if ($pid !== null) $eByPxId[$pid] = $idx;
        }
        foreach ($browserSubset as $idx => $el) {
            $pid = $el['dataset']['pxId'] ?? null;
            if ($pid !== null) $bByPxId[$pid] = $idx;
        }

        if (empty($eByPxId) || empty($bByPxId)) {
            $err = 'data-px-id injection failed: engine=' . (empty($eByPxId) ? '0' : count($eByPxId))
                . ' browser=' . (empty($bByPxId) ? '0' : count($bByPxId))
                . ' — check that .html file exists and HtmlDataPxIdInjector ran successfully';
            return StepResult::err('element_compare', $err);
        }

        $eUsed = []; $bUsed = [];
        foreach ($eByPxId as $pid => $eIdx) {
            if (isset($bByPxId[$pid])) {
                $bIdx = $bByPxId[$pid];
                $matchPairs[] = ['eIdx' => $eIdx, 'bIdx' => $bIdx];
                $eUsed[$eIdx] = true;
                $bUsed[$bIdx] = true;
            }
        }
        usort($matchPairs, function(array $a, array $b): int { return $a['eIdx'] - $b['eIdx']; });

        $eRemaining = [];
        $bRemaining = [];
        for ($i = 0; $i < $eCount; $i++) { if (!isset($eUsed[$i])) $eRemaining[] = $i; }
        for ($i = 0; $i < $bCount; $i++) { if (!isset($bUsed[$i])) $bRemaining[] = $i; }

        echo "  [MATCH] px-id=" . count($matchPairs)
            . " engine_extra=" . count($eRemaining)
            . " browser_extra=" . count($bRemaining) . "\n";

        // ─── 逐元素对比（锚点归一化坐标）───
        $perPropStats = []; // ['property-name' => ['match' => N, 'diff' => N]]
        foreach ($matchPairs as $mpIdx => $pair) {
            $e = $engineSubset[$pair['eIdx']];
            $b = $browserSubset[$pair['bIdx']];

            // 归一化坐标：相对各自锚点的偏移
            // position:fixed 元素以视口为包含块，不参与锚点归一化
            $ePos = $e['styles']['position'] ?? 'static';
            $bPos = $b['styles']['position'] ?? 'static';
            if ($ePos === 'fixed' || $bPos === 'fixed') {
                $eRX = (int)($e['x'] ?? 0);
                $eRY = (int)($e['y'] ?? 0);
                $bRX = (int)($b['x'] ?? 0);
                $bRY = (int)($b['y'] ?? 0);
            } else {
                $eRX = (int)($e['x'] ?? 0) - $eAnchor[0];
                $eRY = (int)($e['y'] ?? 0) - $eAnchor[1];
                $bRX = (int)($b['x'] ?? 0) - $bAnchor[0];
                $bRY = (int)($b['y'] ?? 0) - $bAnchor[1];
            }

            // tag
            $eTag = $e['tag'] ?? '';
            $bTag = $b['tag'] ?? '';
            if ($eTag !== $bTag) {
                $structDiffs[] = "elem[{$pair['eIdx']}].tag: engine=$eTag browser=$bTag";
            }

            // 几何（锚点归一化坐标）
            $geoChecks = [
                'x' => [$eRX, $bRX],
                'y' => [$eRY, $bRY],
                'w' => [(int)($e['w'] ?? 0), (int)($b['w'] ?? 0)],
                'h' => [(int)($e['h'] ?? 0), (int)($b['h'] ?? 0)],
            ];
            foreach ($geoChecks as $f => [$ev, $bv]) {
                $delta = abs($ev - $bv);
                $t = $tol->forProperty($f);
                if ($delta > $t) {
                    // 按差异大小分级：CRITICAL > 20px, MAJOR > 5px, MINOR <= 5px
                    $severity = 'MINOR';
                    if ($delta > 20) {
                        $severity = 'CRITICAL';
                    } elseif ($delta > 5) {
                        $severity = 'MAJOR';
                    }
                    $geoDiffs[] = [
                        'text' => "elem[{$pair['eIdx']}].$f: engine=$ev browser=$bv diff=$delta (tol=$t)",
                        'delta' => $delta,
                        'severity' => $severity,
                        'field' => $f,
                        'elem_idx' => $pair['eIdx'],
                    ];
                }
            }

            // 样式
            $eStyle = $e['styles'] ?? [];
            $bStyle = $b['styles'] ?? [];
            $allKeys = array_unique(array_merge(array_keys($eStyle), array_keys($bStyle)));

            foreach ($allKeys as $k) {
                $evs = $eStyle[$k] ?? null;
                $bvs = $bStyle[$k] ?? null;
                if ($evs === null && $bvs === null) continue;

                // Per-property stats tracking
                if (!isset($perPropStats[$k])) {
                    $perPropStats[$k] = ['match' => 0, 'diff' => 0];
                }

                if ($evs === null) {
                    // 浏览器有但引擎没有：Categorize as MISSING
                    // 但有些是浏览器默认值，跳过它们避免大量噪音
                    // color: 仅在浏览器值为 CSS 初始值 rgb(0,0,0) 时才跳过
                    // （否则如实上报引擎遗漏非默认颜色的 bug）
                    if ($k === 'color' && $bvs === 'rgb(0, 0, 0)') {
                        // CSS 2.2 §18.2: color 初始值为 black，引擎不导出时跳过
                    } elseif (!in_array($k, self::$BROWSER_DEFAULT_SKIP_KEYS, true) && !in_array($k, self::$FONT_SERIALIZATION_KEYS, true)) {
                        $missingDiffs[] = "elem[{$pair['eIdx']}].$k: browser=$bvs";
                        $perPropStats[$k]['diff']++;
                    }
                } elseif ($bvs === null) {
                    // 引擎有但浏览器没有：如果是引擎默认值白名单，直接跳过
                    // 同时也跳过 top/left（已在 GEOMETRY 比较）
                    if (!in_array($k, self::$ENGINE_DEFAULT_ONLY_KEYS, true) && !in_array($k, ['top', 'left'], true) && !in_array($k, self::$BROWSER_DEFAULT_SKIP_KEYS, true) && !in_array($k, self::$FONT_SERIALIZATION_KEYS, true)) {
                        $mismatchDiffs[] = "elem[{$pair['eIdx']}].$k: engine=$evs (browser has no value)";
                        $perPropStats[$k]['diff']++;
                    }
                } elseif ((string)$evs !== (string)$bvs) {
                    // 跳过已知噪音：
                    // 1. top/left 已在 GEOMETRY 中比较（x/y），style 中的 top/left 是不同维度
                    if (in_array($k, ['top', 'left'], true)) continue;
                    // 2. background-color: 引擎从 linear-gradient 提取首色作为 bg，
                    //    浏览器对只有渐变的元素不导出背景色（rgba(0,0,0,0) 表示透明）
                    if ($k === 'background-color') {
                        $bIsTransparent = $bvs === 'rgba(0, 0, 0, 0)' || $bvs === 'transparent';
                        if ($bIsTransparent) {
                            continue; // 引擎的 bg 来自渐变色，非真实背景色
                        }
                    }
                    // 3. 字体属性序列化噪声（B-018/S-001 已知渲染限制），非布局 Bug
                    //    引擎与浏览器的 font-face/font-metric 系统不同
                    if (in_array($k, self::$FONT_SERIALIZATION_KEYS, true)) {
                        continue;
                    }
                    // 4. 格式噪声：引擎与浏览器对同一属性使用不同序列化格式
                    //    border-radius: 引擎单值16px vs 浏览器多值16px 4px
                    //    border-color/width: 引擎4值 vs 浏览器单值（当全部相同时）
                    //    font-family: 引擎 lower/single-quotes vs 浏览器 proper case/double-quotes
                    if (in_array($k, ['border-radius', 'border-color', 'border-width', 'display', 'font-family'], true)) {
                        continue;
                    }
                    $totalLen = strlen((string)$evs) + strlen((string)$bvs);
                    if ($totalLen < 100) {
                        $mismatchDiffs[] = "elem[{$pair['eIdx']}].$k: engine=$evs browser=$bvs";
                        $perPropStats[$k]['diff']++;
                    }
                } else {
                    // 两者都有且值相同 → 匹配
                    $perPropStats[$k]['match']++;
                }
            }
        }

        // ─── 未匹配元素报告 ───
        foreach ($eRemaining as $eIdx) {
            $structDiffs[] = "elem[engine_only_$eIdx]: engine-only element (tag={$engineSubset[$eIdx]['tag']})";
        }
        foreach ($bRemaining as $bIdx) {
            $structDiffs[] = "elem[browser_only_$bIdx]: browser-only element (tag={$browserSubset[$bIdx]['tag']})";
        }

        // 更新元素计数（使用匹配对数量替代 min 截断）
        $matchedCount = count($matchPairs);
        if ($matchedCount !== $eCount || $matchedCount !== $bCount) {
            $structDiffs[] = "content_element_count: engine=$eCount browser=$bCount matched=$matchedCount";
        }

        // Store per-property stats in context for summary report
        $ctx->set('element_prop_stats', $perPropStats);
        $ctx->set('element_engine_count', $eCount);
        $ctx->set('element_browser_count', $bCount);

        // ═══════════════════════════════════════════════
        // 输出：结构差异 → 几何差异 → 引擎缺失 → 值不一致
        // 引擎缺失排在最前面，因为这是增强引擎导出的直接目标
        // ═══════════════════════════════════════════════

        $hasOutput = false;

        // 1) 结构差异（数量少，全显示）
        if (!empty($structDiffs)) {
            echo "  [STRUCTURE]\n";
            foreach ($structDiffs as $d) echo "    - $d\n";
            $hasOutput = true;
        }

        // 2) 引擎缺失（浏览器有但引擎没有）→ 全部显示，不截断
        //    这是增强引擎导出的首要依据
        if (!empty($missingDiffs)) {
            echo "  [MISSING] engine missing " . count($missingDiffs) . " properties (browser has them):\n";
            $showMissing = count($missingDiffs) <= 60 ? $missingDiffs : array_slice($missingDiffs, 0, 60);
            foreach ($showMissing as $d) echo "    - $d\n";
            if (count($missingDiffs) > 60) {
                echo "    ... and " . (count($missingDiffs) - 60) . " more missing\n";
            }
            $hasOutput = true;
        }

        // 3) 几何偏差（按严重程度排序显示）
        if (!empty($geoDiffs)) {
            // Sort by delta descending so largest diffs appear first
            usort($geoDiffs, function($a, $b) { return $b['delta'] - $a['delta']; });

            // Count severity buckets
            $critical = 0; $major = 0; $minor = 0;
            foreach ($geoDiffs as $g) {
                if ($g['severity'] === 'CRITICAL') $critical++;
                elseif ($g['severity'] === 'MAJOR') $major++;
                else $minor++;
            }

            // Store severity counts in context for summary report
            $ctx->set('geo_critical_count', $critical);
            $ctx->set('geo_major_count', $major);
            $ctx->set('geo_minor_count', $minor);

            if ($critical > 0) {
                echo "  [CRITICAL_GEOMETRY] $critical large diffs (diff > 20px, 重点布局偏差):\n";
                $idx = 0;
                foreach ($geoDiffs as $g) {
                    if ($g['severity'] === 'CRITICAL') {
                        if ($idx >= 10) break;
                        echo "    - {$g['text']}\n";
                        $idx++;
                    }
                }
                if ($critical > 10) {
                    echo "    ... and " . ($critical - 10) . " more CRITICAL\n";
                }
            }

            if ($major > 0) {
                echo "  [MAJOR_GEOMETRY] $major moderate diffs (diff 5-20px):\n";
                $idx = 0;
                foreach ($geoDiffs as $g) {
                    if ($g['severity'] === 'MAJOR') {
                        if ($idx >= 10) break;
                        echo "    - {$g['text']}\n";
                        $idx++;
                    }
                }
                if ($major > 10) {
                    echo "    ... and " . ($major - 10) . " more MAJOR\n";
                }
            }

            if ($minor > 0) {
                $minorTexts = [];
                foreach ($geoDiffs as $g) {
                    if ($g['severity'] === 'MINOR') {
                        $minorTexts[] = $g['text'];
                    }
                }
                $showMinor = array_slice($minorTexts, 0, 10);
                if (!empty($showMinor)) {
                    echo "  [MINOR_GEOMETRY] " . count($showMinor) . " small diffs (diff <= 5px, 含字体度量 & 1px偏移):\n";
                    foreach ($showMinor as $g) echo "    - $g\n";
                    if ($minor > 10) {
                        echo "    ... and " . ($minor - 10) . " more MINOR\n";
                    }
                }
            }

            $hasOutput = true;
        }

        // 4) 值不一致
        if (!empty($mismatchDiffs)) {
            $showMismatch = array_slice($mismatchDiffs, 0, 20);
            echo "  [MISMATCH] " . count($mismatchDiffs) . " value diffs (showing first " . count($showMismatch) . "):\n";
            foreach ($showMismatch as $d) echo "    - $d\n";
            if (count($mismatchDiffs) > 20) {
                echo "    ... and " . (count($mismatchDiffs) - 20) . " more\n";
            }
            $hasOutput = true;
        }

        // ═══ 汇总 ═══
        $totalDiffs = count($structDiffs) + count($missingDiffs) + count($geoDiffs) + count($mismatchDiffs);
        if ($totalDiffs > 0) {
            echo "  [SUMMARY] total=$totalDiffs | MISSING=" . count($missingDiffs)
                . " (engine需增强导出) | GEOMETRY=" . count($geoDiffs)
                . " | MISMATCH=" . count($mismatchDiffs)
                . " | STRUCTURE=" . count($structDiffs) . "\n";
        }

        // ─── Phase F: 文本溢出检测 ───
        $overflowIssues = $this->detectOverflow($engineRaw);
        if (!empty($overflowIssues)) {
            echo "  [Phase F] overflow detected:\n";
            foreach ($overflowIssues as $issue) {
                echo "    - $issue\n";
            }
            $ctx->set('overflow_issues', $overflowIssues);
        }

        // ─── Phase G: 容器溢出检测（仅锚点子树，排除侧边栏）───
        $containerIssues = [];
        $layoutPath = $ctx->get('layout_path');
        if ($layoutPath && file_exists($layoutPath)) {
            $engineLayout = json_decode(file_get_contents($layoutPath), true);
            // 在树中搜索 TL 锚点（bg=16711935=0xFF00FF），取其父节点子树
            $anchorParent = $this->findAnchorParentInTree($engineLayout);
            if ($anchorParent !== null) {
                $containerIssues = $this->detectContainerOverflow($anchorParent);
            }
        }
        if (!empty($containerIssues)) {
            echo "  [Phase G] container overflow:\n";
            foreach ($containerIssues as $issue) {
                echo "    - $issue\n";
            }
            $containerOverflowDiffs = count($containerIssues);
        } else {
            $containerOverflowDiffs = 0;
        }

        // Store diff counts in context for summary report
        $ctx->set('compare_missing_count', count($missingDiffs));
        $ctx->set('compare_geometry_count', count($geoDiffs));
        $ctx->set('compare_mismatch_count', count($mismatchDiffs));
        $ctx->set('compare_structure_count', count($structDiffs));
        $ctx->set('container_overflow_issues', $containerIssues);

        // ─── 保存报告文件 ───
        $this->saveReport($structDiffs, $missingDiffs, $geoDiffs, $mismatchDiffs, $totalDiffs, $overflowIssues, $containerIssues);

        // MISSING（引擎未导出属性）不计入失败——工作流规则：仅引擎未导出属性时可通过
        $realDiffs = count($geoDiffs) + count($mismatchDiffs) + count($structDiffs) + $containerOverflowDiffs;
        $allPassed = $realDiffs === 0;
        return $allPassed ? StepResult::ok('element_compare') : StepResult::err('element_compare', 'Differences found');
    }

    /**
     * 从扁平元素列表中提取锚点父容器下的内容子树。
     *
     * 算法：
     *   1) 找到锚点的索引和深度
     *   2) 回溯找到父容器（depth = anchorDepth - 1 的最近前置元素）
     *   3) 从父容器之后、同层或更浅层元素之前截取 = 容器子节点
     *   4) 返回截取的子节点列表 + 锚点坐标（用于归一化）
     *
     * @param array      $elements 扁平元素列表
     * @param array|null $anchor   锚点元素
     * @return array ['elements' => [...], 'anchor' => [x, y], 'count' => N, 'skipped' => M]
     */
    private function extractContentSubtree(array $elements, ?array $anchor): array
    {
        if ($anchor === null) {
            // 没找到锚点，回退到全量对比
            return [
                'elements' => $elements,
                'anchor'   => [0, 0],
                'count'    => count($elements),
                'skipped'  => 0,
            ];
        }

        // 找到锚点索引和深度
        $anchorIdx = -1;
        $anchorDepth = -1;
        foreach ($elements as $idx => $el) {
            if ($el === $anchor) {
                $anchorIdx = $idx;
                $anchorDepth = (int)($el['depth'] ?? 0);
                break;
            }
        }
        if ($anchorIdx < 0) {
            return ['elements' => $elements, 'anchor' => [0, 0], 'count' => count($elements), 'skipped' => 0];
        }

        // 回溯找父容器：depth = anchorDepth - 1 的最近前置元素
        $parentIdx = -1;
        for ($i = $anchorIdx - 1; $i >= 0; $i--) {
            if ((int)($elements[$i]['depth'] ?? 0) === $anchorDepth - 1) {
                $parentIdx = $i;
                break;
            }
        }

        if ($parentIdx < 0) {
            // 没找到父容器，从锚点之后开始（跳过锚点自身，与有父容器时的行为一致）
            return [
                'elements' => array_slice($elements, $anchorIdx + 1),
                'anchor'   => [(int)$anchor['x'], (int)$anchor['y']],
                'count'    => count($elements) - $anchorIdx - 1,
                'skipped'  => $anchorIdx + 1,
            ];
        }

        // 父容器之后开始，但跳过锚点自身
        $startIdx = $anchorIdx + 1;

        // 找到父容器的结束位置：下一个 depth <= parentDepth 的元素
        $parentDepth = (int)($elements[$parentIdx]['depth'] ?? 0);
        $endIdx = count($elements);
        for ($i = $startIdx; $i < count($elements); $i++) {
            if ((int)($elements[$i]['depth'] ?? 0) <= $parentDepth) {
                $endIdx = $i;
                break;
            }
        }

        $subset = array_slice($elements, $startIdx, $endIdx - $startIdx);

        // 使用父容器坐标作为锚点（父容器边界更稳定，不受 absolute 定位偏移影响）
        $anchorX = (int)($elements[$parentIdx]['x'] ?? 0);
        $anchorY = (int)($elements[$parentIdx]['y'] ?? 0);

        return [
            'elements' => $subset,
            'anchor'   => [$anchorX, $anchorY],
            'count'    => count($subset),
            'skipped'  => $startIdx,
        ];
    }

    /**
     * 在元素列表中查找指定锚点。
     * 按优先级：
     *   1) dataset.pxAnchor 匹配（最快、最确定）
     *   2) background-color 匹配（回退）
     *   3) 8x8 尺寸 + 预期位置（最后回退）
     *
     * @param array  $elements 扁平元素列表
     * @param string $which    'tl' 或 'br'
     * @return array|null 锚点元素，或 null
     */
    private function findAnchor(array $elements, string $which): ?array
    {
        $isTl = $which === 'tl';
        $targetColor = $isTl ? 'rgb(255, 0, 255)' : 'rgb(0, 255, 255)';

        foreach ($elements as $el) {
            $dataset = $el['dataset'] ?? [];
            // 1) data-px-anchor 属性匹配（最优先）
            if (isset($dataset['pxAnchor']) && $dataset['pxAnchor'] === $which) {
                return $el;
            }
        }

        foreach ($elements as $el) {
            $styles = $el['styles'] ?? [];
            $w = (int)($el['w'] ?? 0);
            $h = (int)($el['h'] ?? 0);
            // 2) background-color 匹配
            if ($w === 8 && $h === 8) {
                $bg = $styles['background-color'] ?? '';
                if (str_contains($bg, $targetColor)) {
                    return $el;
                }
            }
        }

        // 3) 简单回退：找 8x8 尺寸的元素
        foreach ($elements as $el) {
            $w = (int)($el['w'] ?? 0);
            $h = (int)($el['h'] ?? 0);
            if ($w === 8 && $h === 8) {
                return $el;
            }
        }

        return null;
    }

    /**
     * 保存对比报告到 ref/ 目录。
     * 生成两份文件：
     *   element_compare_report_{mode}.json — 结构化数据，供程序读取
     *   element_compare_report_{mode}.md   — 可读报告，供人工查阅
     *   {mode} = aot|php（由 PX_PHP_RUNTIME 环境变量决定）
     */
    private function saveReport(array $structDiffs, array $missingDiffs, array $geoDiffs, array $mismatchDiffs, int $totalDiffs, array $overflowIssues = [], array $containerIssues = []): void
    {
        if ($this->caseDir === '' || $this->caseName === '') return;

        $refDir = "{$this->caseDir}/ref";
        if (!is_dir($refDir)) {
            @mkdir($refDir, 0777, true);
        }

        // 模式后缀：PHP Runtime 与 AOT 报告隔离
        $isPhpRuntime = getenv('PX_PHP_RUNTIME') !== false && getenv('PX_PHP_RUNTIME') !== '';
        $modeSuffix = $isPhpRuntime ? '_php' : '_aot';

        $counts = [
            'MISSING'  => count($missingDiffs),
            'MISMATCH' => count($mismatchDiffs),
            'GEOMETRY' => count($geoDiffs),
            'STRUCTURE'=> count($structDiffs),
            'TEXT_OVERFLOW' => count($overflowIssues),
            'CONTAINER_OVERFLOW' => count($containerIssues),
            'total'    => $totalDiffs,
        ];

        // ─── JSON 报告（结构化，程序用） ───
        $jsonReport = json_encode([
            'case'       => $this->caseName,
            'timestamp'  => date('Y-m-d H:i:s'),
            'summary'    => $counts,
            'categories' => [
                'MISSING'  => $missingDiffs,
                'MISMATCH' => $mismatchDiffs,
                'GEOMETRY' => $geoDiffs,
                'STRUCTURE'=> $structDiffs,
                'TEXT_OVERFLOW' => $overflowIssues,
                'CONTAINER_OVERFLOW' => $containerIssues,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents("{$refDir}/element_compare_report{$modeSuffix}.json", $jsonReport);
        
        // ─── Markdown 报告（可读，人工用） ───
        $md = "# 元素对比报告: {$this->caseName}\n\n";
        $md .= "**生成时间**: " . date('Y-m-d H:i:s') . "\n\n";
        $md .= "## 汇总\n\n";
        $md .= "| 分类 | 数量 | 含义 |\n";
        $md .= "|------|------|------|\n";
        $md .= "| MISSING | {$counts['MISSING']} | 引擎缺失（浏览器有，引擎没导出）→ 需优先增强导出 |\n";
        $md .= "| GEOMETRY | {$counts['GEOMETRY']} | 几何偏差（位置/尺寸）→ 引擎布局计算需修正 |\n";
        $md .= "| MISMATCH | {$counts['MISMATCH']} | 值不一致（两边都有但值不同）→ 需修正格式/计算 |\n";
        $md .= "| STRUCTURE | {$counts['STRUCTURE']} | 结构差异（元素数量/tag映射）→ 需对齐 |\n";
        $md .= "| TEXT_OVERFLOW | {$counts['TEXT_OVERFLOW']} | 文本溢出（文本宽度超父容器）→ 需修正布局/渲染 |\n";
        $md .= "| CONTAINER_OVERFLOW | {$counts['CONTAINER_OVERFLOW']} | 容器溢出（子项超出父边界）→ 需修正布局计算 |\n";
        $md .= "| **合计** | **{$counts['total']}** | |\n\n";

        $md .= "## 详细差异\n\n";

        if (!empty($missingDiffs)) {
            $md .= "### MISSING（引擎缺失导出，共 {$counts['MISSING']} 项）\n\n";
            $md .= "浏览器有这些样式属性，引擎没有导出：\n\n";
            $md .= "```\n";
            foreach ($missingDiffs as $d) $md .= "$d\n";
            $md .= "```\n\n";
        }

        if (!empty($geoDiffs)) {
            // Count severity buckets for MD report
            $gCritical = 0; $gMajor = 0; $gMinor = 0;
            foreach ($geoDiffs as $g) {
                if ($g['severity'] === 'CRITICAL') $gCritical++;
                elseif ($g['severity'] === 'MAJOR') $gMajor++;
                else $gMinor++;
            }
            $md .= "### GEOMETRY（几何偏差，共 {$counts['GEOMETRY']} 项）\n\n";
            if ($gCritical > 0) $md .= "- **CRITICAL（>20px）**: $gCritical 项 — 重点布局偏差，需优先排查\n";
            if ($gMajor > 0) $md .= "- **MAJOR（5-20px）**: $gMajor 项\n";
            if ($gMinor > 0) $md .= "- **MINOR（<=5px）**: $gMinor 项（含字体度量差异 & 1px 系统性偏移）\n";
            $md .= "\n```\n";
            foreach ($geoDiffs as $g) $md .= $g['text'] . "\n";
            $md .= "```\n\n";
        }

        if (!empty($mismatchDiffs)) {
            $md .= "### MISMATCH（值不一致，共 {$counts['MISMATCH']} 项）\n\n";
            $md .= "```\n";
            foreach ($mismatchDiffs as $d) $md .= "$d\n";
            $md .= "```\n\n";
        }

        if (!empty($structDiffs)) {
            $md .= "### STRUCTURE（结构差异，共 {$counts['STRUCTURE']} 项）\n\n";
            $md .= "```\n";
            foreach ($structDiffs as $d) $md .= "$d\n";
            $md .= "```\n\n";
        }

        if (!empty($overflowIssues)) {
            $md .= "### TEXT_OVERFLOW（文本溢出，共 {$counts['TEXT_OVERFLOW']} 项）\n\n";
            $md .= "引擎文本宽度超出父容器内容区：\n\n";
            $md .= "```\n";
            foreach ($overflowIssues as $d) $md .= "$d\n";
            $md .= "```\n\n";
        }

        if (!empty($containerIssues)) {
            $md .= "### CONTAINER_OVERFLOW（容器溢出，共 {$counts['CONTAINER_OVERFLOW']} 项）\n\n";
            $md .= "子元素超出父容器 content 边界：\n\n";
            $md .= "```\n";
            foreach ($containerIssues as $d) $md .= "$d\n";
            $md .= "```\n\n";
        }

        file_put_contents("{$refDir}/element_compare_report{$modeSuffix}.md", $md);
        
        echo "  [REPORT] saved to {$refDir}/element_compare_report{$modeSuffix}.json + .md\n";
    }

    /**
     * Phase F: detect text overflow where textRenderInfo.textWidth exceeds parent contentW.
     */
    private function detectOverflow(string $layoutJson): array
    {
        $issues = [];
        $data = json_decode($layoutJson, true);
        if (!$data || !isset($data['children'])) return $issues;

        $this->scanOverflow($data, null, $issues);
        return $issues;
    }

    private function scanOverflow(array $node, ?array $parent, array &$issues): void
    {
        if (($node['type'] ?? '') === 'text' || ($node['type'] ?? '') === 'span') {
            $textWidth = $node['textRenderInfo']['textWidth']
                ?? $node['textWidth']
                ?? $node['w']
                ?? 0;
            if ($parent && isset($parent['contentWidth'])) {
                $contentW = $parent['contentWidth'];
                if ($textWidth > $contentW && $contentW > 0) {
                    $text = $node['content'] ?? $node['text'] ?? '(unknown)';
                    if (is_string($text) && mb_strlen($text) > 20) {
                        $text = mb_substr($text, 0, 20) . '...';
                    }
                    $issues[] = sprintf(
                        'text "%s" width=%d exceeds parent contentW=%d (overflow by %dpx)',
                        $text, $textWidth, $contentW, $textWidth - $contentW
                    );
                }
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->scanOverflow($child, $node, $issues);
            }
        }
    }

    /**
     * Phase G: 检测容器溢出——子元素超出父容器 content 边界。
     * 使用原始 engine_layout.json 树结构遍历。
     */
    private function detectContainerOverflow(array $node): array
    {
        $issues = [];
        $this->scanContainerOverflow($node, null, $issues);
        return $issues;
    }

    /**
     * 在引擎布局树中搜索 TL 锚点（bg=16711935=0xFF00FF），返回其父节点。
     * Phase G 只检测锚点父容器下的子树，排除侧边栏误报。
     */
    private function findAnchorParentInTree(array $node): ?array
    {
        $bg = $node['style']['bg'] ?? null;
        if ($bg === 16711935) {
            // 找到锚点，返回 null 由调用方在父递归中处理
            return null;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $childBg = $child['style']['bg'] ?? null;
                if ($childBg === 16711935) {
                    return $node; // 当前节点是锚点的父容器
                }
                $found = $this->findAnchorParentInTree($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function scanContainerOverflow(array $node, ?array $parent, array &$issues): void
    {
        if ($parent !== null) {
            // CSS 2.2 §10.6.3: absolute/fixed 定位元素不参与 normal flow,
            // 不会导致父容器溢出（它们被定位在容器的 padding box 内）
            $childPosition = $node['style']['position'] ?? 'static';
            if ($childPosition !== 'absolute' && $childPosition !== 'fixed') {
                $pW = (int)($parent['w'] ?? 0);
                $pH = (int)($parent['h'] ?? 0);
                $pX = (int)($parent['x'] ?? 0);
                $pY = (int)($parent['y'] ?? 0);

                // 计算父容器的实际内容区边界
                // 引擎中 w/h 始终是内容尺寸（content size），与 boxSizing 无关
                // 子元素的坐标 = 父坐标 + border + padding + 子内容偏移
                // 因此内容区右下边界 = 父坐标 + border + padding + 内容尺寸
                $pPadL = (int)($parent['style']['paddingLeft'] ?? $parent['style']['padding'] ?? 0);
                $pPadT = (int)($parent['style']['paddingTop'] ?? $parent['style']['padding'] ?? 0);
                $pBL = (int)($parent['style']['borderLeftWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $pBT = (int)($parent['style']['borderTopWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $pContentRight = $pX + $pBL + $pPadL + $pW;
                $pContentBottom = $pY + $pBT + $pPadT + $pH;

                $cX = (int)($node['x'] ?? 0);
                $cY = (int)($node['y'] ?? 0);
                $cW = (int)($node['w'] ?? 0);
                $cH = (int)($node['h'] ?? 0);
                $cRight = $cX + $cW;
                $cBottom = $cY + $cH;

                // 只检查有意义的容器（排除 0 尺寸内部节点）
                // 跳过 overflow:hidden 父容器——CSS §11.1.1: 子项可溢出，仅被裁切
                // 跳过所有子项都有 flex-shrink:0 的容器——设计溢出（如水平滚动容器）
                $parentOverflow = $parent['style']['overflow'] ?? 'visible';
                $parentScroll = $parent['isScrollContainer'] ?? false;
                $skipOverflow = ($parentOverflow === 'hidden' || $parentScroll);

                // 检查当前节点自己是否有 flex-shrink:0（设计不可收缩）
                $nodeFlexShrink = $node['style']['flexShrink'] ?? null;
                $nodeAllShrinkZero = ($nodeFlexShrink !== null && (int)$nodeFlexShrink === 0);

                if ($pW > 10 && $cW > 0 && !$skipOverflow && !$nodeAllShrinkZero) {
                    if ($cRight > $pContentRight + 2) {
                        $over = $cRight - $pContentRight;
                        $type = $node['type'] ?? '?';
                        $pType = $parent['type'] ?? '?';
                        $issues[] = "child(type=$type right=$cRight) overflows parent(type=$pType contentRight=$pContentRight) by {$over}px (w: child=$cW parent=$pW)";
                    }
                }
                // 底部溢出检测（滚动容器或 overflow:hidden 内子元素溢出是正常的）
                if (!$skipOverflow && $pH > 10 && $cH > 0) {
                    if ($cBottom > $pContentBottom + 2) {
                        $over = $cBottom - $pContentBottom;
                        $issues[] = "child(type={$node['type']} bottom=$cBottom) overflows parent(type={$parent['type']} contentBottom=$pContentBottom) by {$over}px (bottom overflow)";
                    }
                }
            }
        }

        // ═══ Phase G+：flex 容器子项宽度偏差检测 ═══
        $display = $node['style']['display'] ?? '';
        $isFlex = ($display === 'flex' || $display === 'inline-flex');
        if ($isFlex && !empty($node['children'])) {
            $flexDir = $node['style']['flexDirection'] ?? 'row';
            $flexWrap = $node['style']['flexWrap'] ?? 'nowrap';
            $gap = (int)($node['style']['gap'] ?? 0);
            $isRow = ($flexDir !== 'column');

            // 收集非 absolute 且非 display:none 子项
            $flexChildren = [];
            foreach ($node['children'] as $ch) {
                if (!is_array($ch)) continue;
                $pos = $ch['style']['position'] ?? 'static';
                $disp = $ch['style']['display'] ?? 'block';
                if ($pos === 'absolute' || $pos === 'fixed' || $disp === 'none') continue;
                $flexChildren[] = $ch;
            }

            $count = count($flexChildren);
            if ($count >= 2) {
                $available = $isRow ? (int)($node['w'] ?? 0) : (int)($node['h'] ?? 0);

                if ($available > 20) {
                    if ($isRow) {
                        // 累计子项宽度 + gap
                        $totalW = 0;
                        $allShrinkZero = true;  // 检查是否所有子项都有 flex-shrink:0（设计溢出如水平滚动）
                        foreach ($flexChildren as $ch) {
                            $totalW += (int)($ch['visualW'] ?? $ch['w'] ?? 0);
                            $fs = $ch['style']['flexShrink'] ?? null;
                            if ($fs === null || (int)$fs !== 0) {
                                $allShrinkZero = false;
                            }
                        }
                        $totalW += ($count - 1) * $gap;
                        $diff = $totalW - $available;
                        // 如果所有子项都是 flex-shrink:0（设计溢出，如水平滚动容器），跳过检测
                        if (!$allShrinkZero) {
                            // 如果总宽度 + gap 显著超出可用宽度（>10%），标记偏差
                            if ($diff > $available * 0.1 && $diff > 5) {
                                $issues[] = "[FLEX-WIDTH] row flex items total width ($totalW) exceeds container content width ($available) by {$diff}px (gap=\${gap}px, children=$count, wrap=$flexWrap)";
                            }
                        }
                        // 对 flex-wrap:wrap，且不换行时超额严重，额外提示
                        if ($flexWrap === 'wrap' && $diff > $available * 0.2) {
                            $issues[] = "[FLEX-WRAP-WIDTH] wrap container: items exceed row width by {$diff}px — items may be too wide for flex:1 distribution";
                        }
                        // 对 flex:1 等分子项，检查宽度是否大致相等
                        // 只检查非 wrap 容器——wrap 容器中固定宽度子项是正常的设计
                        if ($flexWrap !== 'wrap') {
                            $gcCount = count($flexChildren);
                            if ($gcCount >= 2) {
                                // 先检查是否有子项设置了显式 width——有则跳过 UNBALANCED
                                $anyExplicitW = false;
                                foreach ($flexChildren as $ch) {
                                    if (array_key_exists('width', ($ch['style'] ?? []))) {
                                        $anyExplicitW = true;
                                        break;
                                    }
                                }
                                if (!$anyExplicitW) {
                                    // 额外检查：至少有一些子项有 flex-grow（表示它们应该等分空间）
                                    // 没有 flex-grow 的子项按自然内容宽度排列，宽度不同是正常的
                                    $hasFlexGrow = false;
                                    foreach ($flexChildren as $ch) {
                                        $fg = $ch['style']['flexGrow'] ?? 0;
                                        if ($fg > 0) {
                                            $hasFlexGrow = true;
                                            break;
                                        }
                                    }
                                    // 使用 content width (w) 而非 visualW 进行比较
                                    // visualW 包含 padding/border，不同子项 padding 不同是正当的
                                    $firstW = (int)($flexChildren[0]['w'] ?? 0);
                                    $allSimilar = true;
                                    $maxDiff = 0;
                                    for ($i = 1; $i < $gcCount; $i++) {
                                        $wi = (int)($flexChildren[$i]['w'] ?? 0);
                                        $d = abs($wi - $firstW);
                                        if ($d > $maxDiff) $maxDiff = $d;
                                    }
                                    if ($maxDiff > 10 && $firstW > 30 && $hasFlexGrow) {
                                        $issues[] = "[FLEX-UNBALANCED] flex items have uneven widths: first={$firstW}px max-diff={$maxDiff}px (gap={$gap}px, children=$gcCount)";
                                    }
                                }
                            }
                        }
                    } else {
                        // column 方向：累计高度 + gap
                        $totalH = 0;
                        foreach ($flexChildren as $ch) {
                            $totalH += (int)($ch['h'] ?? 0);
                        }
                        $totalH += ($count - 1) * $gap;
                        $diff = $totalH - $available;
                        if ($diff > $available * 0.1 && $diff > 5) {
                            $issues[] = "[FLEX-HEIGHT] column flex items total height ($totalH) exceeds container content height ($available) by {$diff}px";
                        }
                    }
                }
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->scanContainerOverflow($child, $node, $issues);
            }
        }
    }
}
