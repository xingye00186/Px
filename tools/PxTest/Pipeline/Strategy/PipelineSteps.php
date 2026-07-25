<?php

namespace PxTest\Pipeline\Strategy;

use PxTest\Layout\LayoutNormalizer;
use PxTest\Pipeline\PipelineStepInterface;
use PxTest\Pipeline\PipelineContext;
use PxTest\Pipeline\CaseContext;
use PxTest\Pipeline\StepResult;

/**
 * LayoutDumpStep — with REF_STALE content validation.
 *
 * Validates that the exported layout JSON actually contains
 * the test case's expected content, preventing stale ref data
 * from being used in comparisons.
 */
class LayoutDumpStep implements PipelineStepInterface
{
    public function __construct(
        private DumpStrategy $strategy,
        private string $caseName,
        private string $appDir,
    ) {}
    public function name(): string { return 'dump_layout'; }
    public function requires(): array { return []; }
    public function execute(CaseContext $ctx): StepResult
    {
        // 从上下文获取当前 case 名（全量运行时每个 case 独立设置）
        $ctxCase = $ctx->get('case_name');
        $currentCase = ($ctxCase !== null && $ctxCase !== '') ? $ctxCase : $this->caseName;
        $caseDir = "{$this->appDir}/test_case/{$currentCase}";
        $refDir = "$caseDir/ref";

        // ─── data-px-id 已由 PxIdGenerateStep 全量预处理完成 ───
        // .vue 已包含 data-px-id，无需在此再次生成或编译。

        // ─── SFC 编译器：检测 .vue 变更后自动重新编译 ───
        // .vue 是源文件，gen/ 是编译产物。当 .vue 比 gen/ 新时需要重新编译。
        $genDir = "{$this->appDir}/gen";
        $appVue = "{$this->appDir}/App.vue";
        $compiledMarker = "$genDir/ComponentFactory.php";
        if ((file_exists($appVue) && file_exists($compiledMarker) && filemtime($appVue) > filemtime($compiledMarker))
            || (file_exists($appVue) && !file_exists($genDir))) {
                echo "  [sfc] .vue changed, recompiling...\n";
                $sfcScript = "{$this->appDir}/../../sfc-compiler.php";
                if (file_exists($sfcScript)) {
                    $cmd = sprintf('%s %s %s 2>&1', PHP_BINARY, escapeshellarg($sfcScript), escapeshellarg($appVue));
                    exec($cmd, $output, $exitCode);
                    if ($exitCode !== 0) {
                        echo "  [sfc] FAILED (exit=$exitCode)\n";
                        foreach ($output as $line) echo "    $line\n";
                        return StepResult::err('dump_layout', 'SFC compilation failed');
                    }
                    echo "  [sfc] OK\n";
                }
            }

        // ─── Vue 结构准入检查（引擎渲染前置条件）───
        $caseDir = "{$this->appDir}/test_case/{$currentCase}";
        $vueFiles = glob("$caseDir/*.vue");
        if (!empty($vueFiles)) {
            $vueSpecErrors = $this->validateVueSpec($vueFiles[0]);
            if (!empty($vueSpecErrors)) {
                $hasInlineVue = false;
                foreach ($vueSpecErrors as $ve) {
                    if (str_contains($ve, 'inline style')) { $hasInlineVue = true; break; }
                }
                if ($hasInlineVue) {
                    echo "  [VUE_SPEC_FAIL] " . basename($vueFiles[0]) . " has forbidden inline font properties:\n";
                    foreach ($vueSpecErrors as $e) { echo "    - $e\n"; }
                    return StepResult::err('dump_layout', 'Inline font properties are forbidden in Vue spec');
                }
                // Style block font properties: warn only
                echo "  [VUE_FONT_WARN] " . basename($vueFiles[0]) . " has font properties in style blocks (allowed for backward compat):\n";
                foreach ($vueSpecErrors as $e) { echo "    - $e\n"; }
            }
        }

        // ─── HTML 规范全面检查（一次性读取文件，避免重复 IO）───
        $htmlFiles = glob("$caseDir/*.html");
        if (!empty($htmlFiles)) {
            $htmlPath = $htmlFiles[0];
            $html = @file_get_contents($htmlPath);
            
            // 1) 根容器 position:relative 检查
            if ($html !== false && preg_match('/<body>\s*<div[^>]*style="[^"]*position:relative/i', $html) === 0) {
                echo "  [HTML_SPEC_WARN] " . basename($htmlPath) . " root container missing position:relative\n";
            }
            
            // 2) * 选择器 line-height:0 检查（警告，非阻塞）
            if ($html !== false && preg_match('/\*\s*\{[^}]*line-height\s*:\s*0[^}]*\}/s', $html) !== 1) {
                echo "  [LH0_SPEC_WARN] " . basename($htmlPath) . " missing 'line-height:0' in * selector (layout may differ slightly)\n";
            }
            
            // 3) 字体属性强制检查
            $fontErrors = self::checkFontPropertiesInHtml($html, 'html');
            if (!empty($fontErrors)) {
                $hasInlineFont = false;
                foreach ($fontErrors as $fe) {
                    if (str_contains($fe, 'inline style')) { $hasInlineFont = true; break; }
                }
                if ($hasInlineFont) {
                    echo "  [HTML_SPEC_FAIL] " . basename($htmlPath) . " has forbidden inline font properties:\n";
                    foreach ($fontErrors as $e) { echo "    - $e\n"; }
                    return StepResult::err('dump_layout', 'Inline font properties are forbidden in test case HTML');
                }
                echo "  [HTML_FONT_WARN] " . basename($htmlPath) . " has font properties in style blocks:\n";
                foreach ($fontErrors as $e) { echo "    - $e\n"; }
            }
            
            // 4) 完整 HTML 规范校验（DOCTYPE/CSS基线/锚点/body结构）
            $specErrors = $this->validateHtmlSpec($htmlPath);
            if (!empty($specErrors)) {
                echo "  [HTML_SPEC_WARN] " . basename($htmlPath) . " spec issues (data may be less accurate):\n";
                foreach ($specErrors as $e) { echo "    - $e\n"; }
                // 非阻塞：继续运行以获取初步对比数据
            }
        }

        $result = $this->strategy->dump($currentCase, $refDir);
        if ($result === null) {
            return StepResult::err('dump_layout', 'Strategy ' . $this->strategy->name() . ' failed');
        }

        // Mode-specific file naming: avoid overwrite between PHP Runtime and AOT
        $isPhpRuntime = getenv('PX_PHP_RUNTIME') !== false && getenv('PX_PHP_RUNTIME') !== '';
        $engineFile = $result[1];
        $modeSuffix = $isPhpRuntime ? '_php' : '_aot';
        $modeFile = dirname($engineFile) . '/engine_layout' . $modeSuffix . '.json';
        if (file_exists($engineFile)) {
            rename($engineFile, $modeFile); // 直接重命名，不保留原始 engine_layout.json
        }
        $result[1] = $modeFile; // update path for subsequent steps

        // 写入上下文供下游步骤使用
        $ctx->set('mode_suffix', $modeSuffix);

        // ─── data-px-testroot 过滤：在 JSON 层提取测试内容子树 ───
        // AOT/PHP 模式均需过滤：AOT exe 内部不做过滤，PHP 的 dumpLayoutToFile 也未实现过滤。
        // 在 PHP 侧对 dump 输出的 JSON 统一做树修剪。
        $filteredJson = file_get_contents($modeFile);
        $treeData = json_decode($filteredJson, true);
        if ($treeData !== null) {
            $filteredTree = self::filterToTestRoot($treeData);
            if ($filteredTree !== null) {
                $newJson = json_encode($filteredTree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents($modeFile, $newJson);
                $result = [0 => $newJson, 1 => $modeFile];
                if (!empty($result[0])) {
                    $filteredJson = $result[0];
                }
                echo "  [px-testroot] filtered to test content only\n";
            }
        }

        // REF_STALE check: verify layout JSON contains test case key content
        $json = $result[0];
        $caseDir = "{$this->appDir}/test_case/{$currentCase}";
        $staleErrors = $this->validateContent($json, $caseDir);
        if (!empty($staleErrors)) {
            foreach ($staleErrors as $e) {
                echo "  [REF_STALE] $e\n";
            }
        }

        $ctx->set('layout_json', $json);
        $ctx->set('layout_path', $result[1]);
        $ctx->set('layout_content_valid', empty($staleErrors));

        // Normalize engine layout to browser-compatible flat format
        try {
            $normalizer = new LayoutNormalizer(1600, 800);
            $normalizedJson = $normalizer->normalize($json);
            $normalizedPath = "{$refDir}/engine_ref_level_0{$modeSuffix}.json";
            file_put_contents($normalizedPath, $normalizedJson);
            $ctx->set('engine_ref', $normalizedJson);
        } catch (\Throwable $e) {
            echo "  [WARN] Layout normalization failed: {$e->getMessage()}\n";
        }

        return StepResult::ok('dump_layout');
    }

    /**
     * Validate that exported layout contains test case content.
     * Extracts text content from .vue/.html and checks it appears in JSON.
     */
    private function validateContent(string $json, string $caseDir): array
    {
        $errors = [];

        // 从原始 .html 提取文本内容（.html 是唯一事实源，不受 PXID 覆写影响）
        $htmlFiles = glob("$caseDir/*.html");
        if (!empty($htmlFiles)) {
            $htmlContent = @file_get_contents($htmlFiles[0]);
            if ($htmlContent !== false) {
                // 提取 <body> 内文本
                if (preg_match('/<body[^>]*>([\s\S]*)<\/body>/i', $htmlContent, $m)) {
                    $bodyHtml = $m[1];
                } else {
                    $bodyHtml = $htmlContent;
                }
                if (preg_match_all('/>([^<]{4,})</', $bodyHtml, $m)) {
                    $texts = array_slice(array_unique($m[1]), 0, 3);
                    foreach ($texts as $text) {
                        if (mb_strlen(trim($text)) < 4) continue;
                        if (str_contains($json, trim($text))) continue;
                        $errors[] = "Content missing: \"" . mb_substr(trim($text), 0, 40) . "\"";
                    }
                }
            }
        }

        // Check layout has at least one non-root element with dimensions
        $data = json_decode($json, true);
        if ($data && isset($data['children']) && count($data['children']) === 0) {
            $errors[] = "Layout has no child elements (possible empty render)";
        }

        return $errors;
    }

    /**
     * Validate .vue file: root div must have position:relative.
     * Anchors use position:absolute and need a relative containing block.
     */
    private function validateVueSpec(string $vuePath): array
    {
        $errors = [];
        $vue = @file_get_contents($vuePath);
        if ($vue === false) return ["Cannot read file: " . basename($vuePath)];

        // Extract template content
        if (!preg_match('/<template>([\s\S]*)<\/template>/i', $vue, $m)) {
            return ["No <template> found in " . basename($vuePath)];
        }
        $template = trim($m[1]);

        // Check root element has position:relative
        if (preg_match('/<div[^>]*style="([^"]*)"[^>]*>/i', $template, $sm)) {
            $style = $sm[1];
            if (stripos($style, 'position:relative') === false) {
                $errors[] = "Root div must have position:relative — "
                    . "anchors use position:absolute and need a relative containing block. "
                    . "Add 'position:relative' to the root div's style.";
            }
        }

        // Check anchors exist inside template
        if (stripos($template, 'data-px-anchor="tl"') === false) {
            $errors[] = "Missing data-px-anchor='tl' in template";
        }
        if (stripos($template, 'data-px-anchor="br"') === false) {
            $errors[] = "Missing data-px-anchor=\"br\" in template";
        }

        // ── 字体属性强制检查（CssTest Layout Spec）──
        // 测试用例中禁止任何字体属性，违反即阻断管线
        $fontErrors = self::checkFontPropertiesInHtml($template, 'vue');
        $errors = array_merge($errors, $fontErrors);

        return $errors;
    }

    // ═══════════════════════════════════════════════════════════════
    //  CssTest Layout Spec — 字体属性规范
    // ═══════════════════════════════════════════════════════════════
    //
    //  目的：引擎与浏览器的字体度量系统不同（Skia/GDI vs DirectWrite），
    //  字体属性（font-size/font-weight/line-height 等）必然产生偏差
    //  （B-018/S-001 已知限制）。为聚焦布局系统测试，CssTest 用例中
    //  **禁止**出现任何字体相关属性。
    //
    //  替代方案：所有文本内容用固定尺寸占位符 span：
    //    <span style="display:inline-block;width:8px;height:25px;background:#xxx;"></span>
    //  宽高固定，不受父元素字体属性影响。
    //
    //  禁止出现在任意元素 style="..." 中的属性：
    //    font-size, font-weight, font-family, color,
    //    letter-spacing, word-spacing
    //
    //  例外：
    //    1. <style> 块中的基线 CSS 不受此限
    //    2. line-height:0 是唯一允许的字体属性值
    //       （用于消除浏览器默认行高间距）
    //
    //  阻断行为：
    //    LayoutDumpStep 会在 execute 中扫描所有 .vue 和 .html
    //    的 inline style，若发现禁止属性立即终止管线并输出修改说明。
    //    所有 case 必须通过此检查才能运行布局对比。
    // ═══════════════════════════════════════════════════════════════

    /** 禁止在测试用例 inline style 中出现的字体属性 */
    private const FORBIDDEN_FONT_PROPS = [
        'font-size', 'font-weight', 'font-family', 'color',
        'letter-spacing', 'word-spacing',
    ];

    /**
     * 扫描 HTML/Vue 模板中所有元素的 inline style，检查字体属性。
     *
     * @param string $html HTML 或 Vue template 字符串
     * @param string $source 来源标记（'html' 或 'vue'）
     * @return array 错误描述数组（空数组=通过）
     */
    private static function checkFontPropertiesInHtml(string $html, string $source = 'html'): array
    {
        $errors = [];
    
        // 检查 inline style="..." 属性
        if (preg_match_all('/style="([^"]*)"/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $styleContent = $m[1];
                $checkResult = self::checkSingleStyle($styleContent, $source, 'inline style');
                if ($checkResult !== null) $errors[] = $checkResult;
            }
        }
    
        // 检查 <style>...</style> 块中的 CSS 规则
        if (preg_match_all('/<style[^>]*>([\s\S]*?)<\/style>/i', $html, $styleBlocks, PREG_SET_ORDER)) {
            foreach ($styleBlocks as $sb) {
                $cssContent = $sb[1];
                // 提取每条 CSS 规则: selector { ... }
                if (preg_match_all('/([^{]+)\{([^}]*)\}/', $cssContent, $cssRules, PREG_SET_ORDER)) {
                    foreach ($cssRules as $match) {
                        $rule = $match[0];
                        // Skip mandatory html,body baseline (may have leading CSS comments)
                        $ruleTrim = preg_replace('/\/\*.*?\*\//s', '', $rule);
                        if (preg_match('/^\s*html\s*,\s*body/i', $ruleTrim)) continue;
                        // Skip * universal selector baseline
                        if (preg_match('/^\s*\*\s*\{/', $ruleTrim)) continue;
                        $checkResult = self::checkSingleStyle($rule, $source, '<style> block');
                        if ($checkResult !== null) $errors[] = $checkResult;
                    }
                }
            }
        }
    
        return $errors;
    }
    
    /**
     * 检查单个样式内容是否含禁止的字体属性。
     *
     * @param string $styleContent 样式内容（inline style 或 CSS 规则体）
     * @param string $source 'html' 或 'vue'
     * @param string $context 上下文描述
     * @return string|null 错误信息或 null（通过）
     */
    private static function checkSingleStyle(string $styleContent, string $source, string $context): ?string
    {
        // line-height:0 是允许的例外，先移除再检查
        $cleaned = preg_replace('/line-height\s*:\s*0\s*(;|$)/i', '', $styleContent);
    
        foreach (self::FORBIDDEN_FONT_PROPS as $prop) {
            if (preg_match('/' . str_replace('-', '\-', $prop) . '\s*:/i', $cleaned)) {
                return "Forbidden font property '$prop' in $source $context. "
                    . "Remove all font properties. "
                    . "Use inline-block placeholder spans instead of real text. "
                    . "Found: " . substr($styleContent, 0, 120);
            }
        }
    
        // line-height 检查（除 0 以外的值都禁止）
        if (preg_match('/line-height\s*:\s*([^;}]+)/i', $styleContent, $lm)) {
            $lhValue = trim($lm[1]);
            if ($lhValue !== '0') {
                return "Forbidden font property 'line-height: $lhValue' in $source $context. "
                    . "Only line-height:0 is allowed (to eliminate browser default line spacing). "
                    . "Found: " . substr($styleContent, 0, 120);
            }
        }
    
        return null;
    }

    /**
     * .html 文件字体属性检查（与 validateVueSpec 相同的逻辑）
     */
    private function validateHtmlSpec(string $htmlPath): array
    {
        $errors = [];
        $html = @file_get_contents($htmlPath);
        if ($html === false) return ["Cannot read file: " . basename($htmlPath)];

        // 1) DOCTYPE
        if (stripos($html, '<!DOCTYPE html>') === false) {
            $errors[] = "Missing <!DOCTYPE html> — add it at the top of " . basename($htmlPath);
        }

        // 2) CSS baseline: html,body must declare viewport size + font
        if (preg_match('/html\s*,\s*body\s*\{[^}]*width\s*:\s*1600/i', $html) === 0) {
            $errors[] = "Missing 'html,body { width:1600px; }' baseline — add a <style> block in <head>";
        }
        if (preg_match('/html\s*,\s*body\s*\{[^}]*height\s*:\s*800/i', $html) === 0) {
            $errors[] = "Missing 'html,body { height:800px; }' baseline";
        }
        if (preg_match('/html\s*,\s*body\s*\{[^}]*font-family/i', $html) === 0) {
            $errors[] = "Missing font-family in html,body baseline — add font-family:\"Segoe UI\",...";
        }
        if (preg_match('/html\s*,\s*body\s*\{[^}]*font-size\s*:\s*16/i', $html) === 0) {
            $errors[] = "Missing 'font-size:16px' in html,body baseline";
        }
        if (preg_match('/html\s*,\s*body\s*\{[^}]*background\s*:\s*#fff/i', $html) === 0) {
            $errors[] = "Missing 'background:#fff' in html,body baseline";
        }

        // 3) Anchor elements
        if (stripos($html, 'data-px-anchor="tl"') === false) {
            $errors[] = "Missing anchor element: data-px-anchor=\"tl\" — add 8x8 div with #FF00FF bg at top-left";
        }
        if (stripos($html, 'data-px-anchor="br"') === false) {
            $errors[] = "Missing anchor element: data-px-anchor=\"br\" — add 8x8 div with #00FFFF bg at bottom-right";
        }

        // 4) Basic structure
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            $errors[] = "No <body> element found";
        } else {
            // Count direct child elements (not text nodes)
            $childCount = 0;
            foreach ($body->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) $childCount++;
            }
            if ($childCount < 1) {
                $errors[] = "Body must have at least one child element — the test content container div";
            } else {
                // 5) Batch mode compatibility: first child of body must be a <div>
                // generateBatch() 注入 id="test-content-wrapper" 到首个 <div>
                $firstChild = $body->firstChild;
                while ($firstChild && $firstChild->nodeType !== XML_ELEMENT_NODE) {
                    $firstChild = $firstChild->nextSibling;
                }
                if ($firstChild && strtolower($firstChild->tagName) !== 'div') {
                    $errors[] = "First element in <body> must be a <div> (the main content container) — "
                        . "batch mode requires it for id=\"test-content-wrapper\" injection";
                }
                // 6) First <div> must NOT be an anchor element
                // If batch injects id="test-content-wrapper" into an anchor div,
                // dump_layout.js will extract content from the wrong subtree root.
                if ($firstChild && $firstChild->getAttribute('data-px-anchor') !== '') {
                    $errors[] = "First element in <body> must be the test content container, "
                        . "not a data-px-anchor element — move anchors inside the container div";
                }
                // 7) Root content container must have position:relative
                // position:absolute anchors need a relative containing block.
                if ($firstChild && $firstChild->nodeType === XML_ELEMENT_NODE) {
                    $style = $firstChild->getAttribute('style');
                    if (stripos($style, 'position:relative') === false) {
                        $errors[] = "Root content container (first <div> in <body>) must have "
                            . "position:relative — anchors use position:absolute and need a relative containing block.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate .html vs .vue structural consistency.
     *
     * The .html typically has extra wrapper layers (sandbox, wrapper-test)
     * that .vue doesn't — these are expected and ignored. The comparison
     * focuses on:
     *   - Content element structure (ignoring wrapper layers)
     *   - Anchor elements match in both
     *   - Test content text appears in both
     *
     * Style strategy (inline vs class) is NOT compared — both are equivalent.
     */
    /**
     * 在 JSON 树中递归搜索 data-px-testroot 标记节点，提取其子树。
     * 替换根节点为该节点，移除侧边栏等非测试内容。
     * AOT exe 输出的完整树包含侧边栏，此过滤在 PHP 侧做树修剪。
     *
     * @param array $node JSON 树节点
     * @return array|null 过滤后的树，或 null（未找到标记）
     */
    private static function filterToTestRoot(array $node): ?array
    {
        // 检查当前节点
        $ds = $node['dataset'] ?? [];
        if (isset($ds['pxTestroot']) && (string)$ds['pxTestroot'] === 'true') {
            // 坐标保持**绝对**（LayoutNG 语义：Fragment 树全绝对坐标）——不再置零根 x/y：
            // 此前置零仅改根不改子孙，制造根(0,0)+子孙绝对的混杂坐标系，
            // 导致对比端父锚点归一化（extractContentSubtree 减父容器坐标）失真。
            // 归一化职责归对比端单通道，过滤层只修剪树。
            // 提取 testroot 的子节点作为新的子树根节点，移除多余的 wrapper 层
            // 使引擎扁平化后的元素层级与浏览器一致（浏览器 batch ref 会提取 body 内内容，无此 wrapper）
            if (!empty($node['children'])) {
                // 递归展开无意义的包装层：如果唯一子节点也没有 pxId 和 pxAnchor，继续深入
                $child = $node['children'][0];
                if (count($node['children']) === 1) {
                    // 检查子节点是否仍有意义：有 pxId 或 pxAnchor 则保留，否则递归深入
                    while ($child !== null && count($child['children'] ?? []) === 1) {
                        $ds2 = $child['dataset'] ?? [];
                        if (isset($ds2['pxId']) || isset($ds2['pxAnchor'])) break;
                        $child = $child['children'][0];
                    }
                    return $child;
                }
                return [
                    'type' => 'div', 'tag' => 'div',
                    // 合成 wrapper 保留 testroot 自身几何（绝对坐标）与 dataset/styles。
                    'x' => (int)($node['x'] ?? 0), 'y' => (int)($node['y'] ?? 0),
                    'w' => (int)($node['w'] ?? 0), 'h' => (int)($node['h'] ?? 0),
                    'dataset' => $node['dataset'] ?? [], 'styles' => $node['styles'] ?? [],
                    'children' => $node['children'],
                ];
            }
            return $node;
        }
        // 递归搜索子节点
        foreach ($node['children'] ?? [] as $child) {
            $result = self::filterToTestRoot($child);
            if ($result !== null) return $result;
        }
        return null;
    }
}

/**
 * BatchBrowserRefStep — 单次 Edge 启动为所有 case 生成 browser ref。
 * 必须放在全量循环之前执行，比逐个 case 启动 Edge 快 20x。
 */
class BatchBrowserRefStep implements \PxTest\Pipeline\PipelineStepInterface
{
    /** 仅首次成功后缓存，避免重复执行 */
    private static bool $batchSucceeded = false;

    public function __construct(
        private BrowserRefStrategy $strategy,
        private array $cases,
    ) {}
    public function name(): string { return 'batch_browser_ref'; }
    public function requires(): array { return ['pxid_generate']; }
    public function execute(CaseContext $ctx): \PxTest\Pipeline\StepResult
    {
        if (self::$batchSucceeded) {
            return \PxTest\Pipeline\StepResult::ok('batch_browser_ref', 0);
        }
        $start = microtime(true);
        if (empty($this->cases)) {
            self::$batchSucceeded = true;
            return \PxTest\Pipeline\StepResult::ok('batch_browser_ref', 0);
        }
        echo "  [batch_browser_ref] " . count($this->cases) . " cases, single Edge launch...\n";
        $ok = $this->strategy->generateBatch($this->cases);
        $elapsed = (microtime(true) - $start) * 1000;
        if ($ok) {
            self::$batchSucceeded = true;
            $ctx->pipeline()->set('batch_ref_done', true);
            echo "  [batch_browser_ref] OK ({$elapsed}ms)\n";
            return \PxTest\Pipeline\StepResult::ok('batch_browser_ref', $elapsed);
        }
        echo "  [batch_browser_ref] FAILED ({$elapsed}ms), will fallback to per-case\n";
        return \PxTest\Pipeline\StepResult::err('batch_browser_ref', 'Batch ref generation failed, per-case fallback', $elapsed);
    }
}

/**
 * PxIdGenerateStep — 全量 data-px-id 预处理（仅执行一次）。
 *
 * 扫描所有 test_case，从 .html 生成含 data-px-id 的 .vue，
 * 然后编译 SFC 一次。静态标记确保多 case 循环中只执行第一轮。
 */
class PxIdGenerateStep implements \PxTest\Pipeline\PipelineStepInterface
{
    private static bool $done = false;
    private string $appDir;
    private string $casePrefix;

    public function __construct(string $appDir, string $casePrefix)
    {
        $this->appDir = $appDir;
        $this->casePrefix = $casePrefix;
    }
    public function name(): string { return 'pxid_generate'; }
    public function requires(): array { return []; }

    public function execute(CaseContext $ctx): \PxTest\Pipeline\StepResult
    {
        if (self::$done) {
            return \PxTest\Pipeline\StepResult::ok('pxid_generate', 0);
        }
        self::$done = true;

        $changed = 0;
        $caseDirs = glob($this->appDir . '/test_case/' . $this->casePrefix . '*', GLOB_ONLYDIR);
        sort($caseDirs);

        require_once __DIR__ . '/../../HtmlDataPxIdInjector.php';
        require_once __DIR__ . '/../../HtmlToVueConverter.php';

        foreach ($caseDirs as $dir) {
            $tag = basename($dir);
            $htmlFiles = glob("$dir/*.html");
            if (empty($htmlFiles)) continue;
            $htmlFile = $htmlFiles[0];
            $markerFile = "$dir/.pxid_done";

            // mtime 检查
            if (file_exists($markerFile) && filemtime($htmlFile) <= filemtime($markerFile)) {
                continue;
            }

            $htmlContent = @file_get_contents($htmlFile);
            if ($htmlContent === false) continue;

            // ── data-px-id 单源注入：直接写入 .html ──
            // .html 是唯一事实源，所有下游消费者（Vue 生成、浏览器 instrument）
            // 都从已注入 data-px-id 的 .html 读取，无需重复注入。
            $injectedHtml = \PxTest\HtmlDataPxIdInjector::inject($htmlContent);

            // 仅在内容变化时回写 .html（避免无效 mtime 更新）
            if ($injectedHtml !== $htmlContent) {
                file_put_contents($htmlFile, $injectedHtml);
            }

            // 从已注入 data-px-id 的 HTML 生成 .vue
            $generatedVue = \PxTest\HtmlToVueConverter::convert($injectedHtml, $tag);

            // 确定 .vue 路径：优先覆盖已有 .vue，否则新建
            $vueFiles = glob("$dir/*.vue");
            $vueFile = !empty($vueFiles) ? $vueFiles[0] : "$dir/$tag.vue";

            file_put_contents($vueFile, $generatedVue);
            touch($markerFile);
            $changed++;
        }

        if ($changed > 0) {
            echo "  [pxid] $changed .vue files regenerated\n";
        } else {
            echo "  [pxid] all .vue up-to-date\n";
        }

        return \PxTest\Pipeline\StepResult::ok('pxid_generate', $changed);
    }
}
