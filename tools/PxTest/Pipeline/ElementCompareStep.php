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
        'box-sizing',
        // border per-side: engine exports all 4, browser only has shorthand
        'border-top-width', 'border-top-color',
        'border-right-width', 'border-right-color',
        'border-bottom-width', 'border-bottom-color',
        'border-left-width', 'border-left-color',
    ];

    /**
     * 引擎缺失的浏览器属性白名单——这些 MISSING 不计入失败（引擎不导出默认值）。
     */
    private static array $BROWSER_DEFAULT_SKIP_KEYS = [
        'font-size', 'color', 'background-color', 'border-left-width', 'border-left-color',
        'border-width', 'border-color', 'border-radius',
        'padding-top', 'padding-left', 'padding-right', 'padding-bottom',
        'margin-top', 'margin-left', 'margin-right', 'margin-bottom',
        'font-family', 'line-height',
        'flex-direction', 'flex-wrap', 'overflow-x', 'overflow-y',
        'display',
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

    public function execute(PipelineContext $ctx): StepResult
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

        // ─── 元素数量 ───
        $eCount = count($engineSubset);
        $bCount = count($browserSubset);
        if ($eCount !== $bCount) {
            $structDiffs[] = "content_element_count: engine=$eCount browser=$bCount";
        }

        // ─── 逐元素对比（锚点归一化坐标）───
        $max = min($eCount, $bCount);
        for ($i = 0; $i < $max; $i++) {
            $e = $engineSubset[$i];
            $b = $browserSubset[$i];

            // 归一化坐标：相对各自锚点的偏移
            $eRX = (int)($e['x'] ?? 0) - $eAnchor[0];
            $eRY = (int)($e['y'] ?? 0) - $eAnchor[1];
            $bRX = (int)($b['x'] ?? 0) - $bAnchor[0];
            $bRY = (int)($b['y'] ?? 0) - $bAnchor[1];

            // tag
            $eTag = $e['tag'] ?? '';
            $bTag = $b['tag'] ?? '';
            if ($eTag !== $bTag) {
                $structDiffs[] = "elem[$i].tag: engine=$eTag browser=$bTag";
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
                    $geoDiffs[] = "elem[$i].$f: engine=$ev browser=$bv diff=$delta (tol=$t)";
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
                if ($evs === null) {
                    // 浏览器有但引擎没有：Categorize as MISSING
                    // 但有些是浏览器默认值，跳过它们避免大量噪音
                    if (!in_array($k, self::$BROWSER_DEFAULT_SKIP_KEYS, true)) {
                        $missingDiffs[] = "elem[$i].$k: browser=$bvs";
                    }
                } elseif ($bvs === null) {
                    // 引擎有但浏览器没有：如果是引擎默认值白名单，直接跳过
                    // 同时也跳过 top/left（已在 GEOMETRY 比较）
                    if (!in_array($k, self::$ENGINE_DEFAULT_ONLY_KEYS, true) && !in_array($k, ['top', 'left'], true)) {
                        $mismatchDiffs[] = "elem[$i].$k: engine=$evs (browser has no value)";
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
                    $totalLen = strlen((string)$evs) + strlen((string)$bvs);
                    if ($totalLen < 100) {
                        $mismatchDiffs[] = "elem[$i].$k: engine=$evs browser=$bvs";
                    }
                }
            }
        }

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

        // 3) 几何偏差
        if (!empty($geoDiffs)) {
            $showGeo = array_slice($geoDiffs, 0, 20);
            echo "  [GEOMETRY] " . count($geoDiffs) . " diffs (showing first " . count($showGeo) . "):\n";
            foreach ($showGeo as $d) echo "    - $d\n";
            if (count($geoDiffs) > 20) {
                echo "    ... and " . (count($geoDiffs) - 20) . " more\n";
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

        // ─── 保存报告文件 ───
        $this->saveReport($structDiffs, $missingDiffs, $geoDiffs, $mismatchDiffs, $totalDiffs, $containerIssues);

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
            // 没找到父容器，从锚点自身开始
            return [
                'elements' => array_slice($elements, $anchorIdx),
                'anchor'   => [(int)$anchor['x'], (int)$anchor['y']],
                'count'    => count($elements) - $anchorIdx,
                'skipped'  => $anchorIdx,
            ];
        }

        // 父容器之后开始
        $startIdx = $parentIdx + 1;

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

        return [
            'elements' => $subset,
            'anchor'   => [(int)$anchor['x'], (int)$anchor['y']],
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
     *   element_compare_report.json  — 结构化数据，供程序读取
     *   element_compare_report.md    — 可读报告，供人工查阅
     */
    private function saveReport(array $structDiffs, array $missingDiffs, array $geoDiffs, array $mismatchDiffs, int $totalDiffs, array $containerIssues = []): void
    {
        if ($this->caseDir === '' || $this->caseName === '') return;

        $refDir = "{$this->caseDir}/ref";
        if (!is_dir($refDir)) {
            @mkdir($refDir, 0777, true);
        }

        $counts = [
            'MISSING'  => count($missingDiffs),
            'MISMATCH' => count($mismatchDiffs),
            'GEOMETRY' => count($geoDiffs),
            'STRUCTURE'=> count($structDiffs),
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
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents("$refDir/element_compare_report.json", $jsonReport);

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
            $md .= "### GEOMETRY（几何偏差，共 {$counts['GEOMETRY']} 项）\n\n";
            $md .= "```\n";
            foreach ($geoDiffs as $d) $md .= "$d\n";
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

        file_put_contents("$refDir/element_compare_report.md", $md);

        echo "  [REPORT] saved to {$refDir}/element_compare_report.json + .md\n";
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
            if ($parent && isset($parent['contentW'])) {
                $contentW = $parent['contentW'];
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

                // 计算父容器的实际内容区边界（包含 padding+border 偏移）
                // 子元素的坐标已包含父容器的 padding/border 偏移，
                // 因此内容区右/下边界 = pX/pY + padding + border + w/h
                $pPadL = (int)($parent['style']['paddingLeft'] ?? $parent['style']['padding'] ?? 0);
                $pPadT = (int)($parent['style']['paddingTop'] ?? $parent['style']['padding'] ?? 0);
                $pBL = (int)($parent['style']['borderLeftWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $pBT = (int)($parent['style']['borderTopWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $pContentRight = $pX + $pPadL + $pBL + $pW;
                $pContentBottom = $pY + $pPadT + $pBT + $pH;

                $cX = (int)($node['x'] ?? 0);
                $cY = (int)($node['y'] ?? 0);
                $cW = (int)($node['w'] ?? 0);
                $cH = (int)($node['h'] ?? 0);
                $cRight = $cX + $cW;
                $cBottom = $cY + $cH;

                // 只检查有意义的容器（排除 0 尺寸内部节点）
                if ($pW > 10 && $cW > 0) {
                    if ($cRight > $pContentRight + 2) {
                        $over = $cRight - $pContentRight;
                        $type = $node['type'] ?? '?';
                        $pType = $parent['type'] ?? '?';
                        $issues[] = "child(type=$type right=$cRight) overflows parent(type=$pType contentRight=$pContentRight) by {$over}px (w: child=$cW parent=$pW)";
                    }
                }
                // 底部溢出检测（滚动容器内子元素溢出是正常的）
                // 仅当父容器非滚动容器时检查
                $parentScroll = $parent['isScrollContainer'] ?? false;
                if (!$parentScroll && $pH > 10 && $cH > 0) {
                    if ($cBottom > $pContentBottom + 2) {
                        $over = $cBottom - $pContentBottom;
                        $issues[] = "child(type={$node['type']} bottom=$cBottom) overflows parent(type={$parent['type']} contentBottom=$pContentBottom) by {$over}px (bottom overflow)";
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
