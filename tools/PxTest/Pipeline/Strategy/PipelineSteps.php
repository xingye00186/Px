<?php

namespace PxTest\Pipeline\Strategy;

use PxTest\Layout\LayoutNormalizer;
use PxTest\Pipeline\PipelineStepInterface;
use PxTest\Pipeline\PipelineContext;
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
    public function execute(PipelineContext $ctx): StepResult
    {
        // 从上下文获取当前 case 名（全量运行时每个 case 独立设置）
        $ctxCase = $ctx->get('case_name');
        $currentCase = ($ctxCase !== null && $ctxCase !== '') ? $ctxCase : $this->caseName;
        $refDir = "{$this->appDir}/test_case/{$currentCase}/ref";
        $result = $this->strategy->dump($currentCase, $refDir);
        if ($result === null) {
            return StepResult::err('dump_layout', 'Strategy ' . $this->strategy->name() . ' failed');
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
            $normalizedPath = "$refDir/engine_ref_level_0.json";
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

        // Find the .vue template file
        $vueFiles = glob("$caseDir/*.vue");
        if (empty($vueFiles)) return ['No .vue template found'];

        $vueContent = @file_get_contents($vueFiles[0]);
        if ($vueContent === false) return ['Cannot read .vue file'];

        // Extract key text content between template tags
        if (preg_match_all('/>([^<]{4,})</', $vueContent, $m)) {
            $texts = array_slice(array_unique($m[1]), 0, 5); // top 5 unique texts
            foreach ($texts as $text) {
                if (mb_strlen(trim($text)) < 4) continue;
                if (str_contains($json, trim($text))) continue;
                $errors[] = "Content missing: \"" . mb_substr(trim($text), 0, 40) . "\"";
            }
        }

        // Check layout has at least one non-root element with dimensions
        $data = json_decode($json, true);
        if ($data && isset($data['children']) && count($data['children']) === 0) {
            $errors[] = "Layout has no child elements (possible empty render)";
        }

        return $errors;
    }
}

/**
 * BrowserRefStep — validate .html spec compliance, inject instrumentation.
 *
 * The .html file must already contain CSS baseline declarations (width/height,
 * font-family, font-size) and anchor elements. This step validates the spec,
 * checks .html vs .vue consistency, and injects only infrastructure
 * (dump_layout.js + textarea) — no CSS modification.
 *
 * Spec requirements for .html:
 *   1. <!DOCTYPE html> + <meta charset="utf-8">
 *   2. CSS baseline: html,body { width:1600px; height:800px; font-family:...; font-size:16px; ... }
 *   3. data-px-anchor="tl" and data-px-anchor="br" anchor elements
 *   4. Structure consistent with .vue template (element count, nesting, CSS props)
 *
 * If validation fails, the step aborts with detailed fix instructions.
 */
class BrowserRefStep implements PipelineStepInterface
{
    public function __construct(
        private BrowserRefStrategy $strategy,
        private string $appDir,
        private string $caseName,
    ) {}
    public function name(): string { return 'browser_ref'; }
    public function requires(): array { return []; }
    public function execute(PipelineContext $ctx): StepResult
    {
        $ctxCase = $ctx->get('case_name');
        $currentCase = ($ctxCase !== null && $ctxCase !== '') ? $ctxCase : $this->caseName;
        $caseDir = "{$this->appDir}/test_case/{$currentCase}";
        $htmlFiles = glob("$caseDir/*.html");
        if (empty($htmlFiles)) return StepResult::err('browser_ref', 'No HTML file found');
        $htmlPath = $htmlFiles[0];

        // ─── Step 1: Validate .html spec compliance ───
        $specErrors = $this->validateHtmlSpec($htmlPath);
        if (!empty($specErrors)) {
            echo "  [SPEC_FAIL] " . basename($htmlPath) . " violates PxTest HTML spec:\n";
            foreach ($specErrors as $e) {
                echo "    - $e\n";
            }
            return StepResult::err('browser_ref', 'HTML spec validation failed');
        }

        // ─── Step 2: Validate .html vs .vue consistency ───
        $vueFiles = glob("$caseDir/*.vue");
        if (!empty($vueFiles)) {
            $vueErrors = $this->validateVueConsistency($htmlPath, $vueFiles[0]);
            if (!empty($vueErrors)) {
                echo "  [VUE_MISMATCH] .html vs .vue inconsistency:\n";
                foreach ($vueErrors as $e) {
                    echo "    - $e\n";
                }
                return StepResult::err('browser_ref', '.html/.vue consistency check failed');
            }
        }

        // ─── Step 3: Inject dump_layout.js + textarea only (no CSS) ───
        $instrumented = $this->instrumentHtml($htmlPath);
        $refDir = "$caseDir/ref";
        @mkdir($refDir, 0777, true);
        // Write to temp file with .html extension — original .html IS the benchmark
        $tempDir = sys_get_temp_dir();
        $instrumentedPath = $tempDir . '/px_browser_ref_' . $currentCase . '.html';
        file_put_contents($instrumentedPath, $instrumented);

        // ─── Step 4: Run browser (Edge headless) ───
        $ok = $this->strategy->generate($instrumentedPath, $refDir, $currentCase);
        $ctx->set('browser_wrapper_html', $instrumented);
        @unlink($instrumentedPath); // clean up temp file

        $refJsonPath = "$refDir/browser_ref_level_0.json";
        if ($ok && file_exists($refJsonPath)) {
            $refJson = file_get_contents($refJsonPath);
            $ctx->set('browser_ref', $refJson);
        }

        return $ok ? StepResult::ok('browser_ref') : StepResult::err('browser_ref', 'Strategy ' . $this->strategy->name() . ' failed');
    }

    /**
     * Validate .html file against PxTest HTML spec.
     *
     * The .html must be self-contained with:
     *   - DOCTYPE + charset
     *   - CSS baseline: html,body { width:1600px; height:800px; font-family; font-size; }
     *   - Anchor elements (data-px-anchor="tl", data-px-anchor="br")
     *
     * Any violation returns specific fix instruction.
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
        if (!$body || $body->childNodes->length < 2) {
            $errors[] = "Body has < 2 child elements — add test content container and anchor";
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
    private function validateVueConsistency(string $htmlPath, string $vuePath): array
    {
        $errors = [];
        $html = @file_get_contents($htmlPath);
        $vue = @file_get_contents($vuePath);
        if ($html === false || $vue === false) return [];

        // Extract .vue template content
        if (!preg_match('/<template>([\s\S]*?)<\/template>/i', $vue, $m)) return [];
        $vueTemplate = trim($m[1]);

        // Extract .html body content
        $htmlBody = '';
        $htmlDom = new \DOMDocument();
        @$htmlDom->loadHTML($html);
        $bodyNode = $htmlDom->getElementsByTagName('body')->item(0);
        if ($bodyNode) {
            foreach ($bodyNode->childNodes as $child) {
                $htmlBody .= $htmlDom->saveHTML($child);
            }
        } else {
            $htmlBody = $html;
        }

        // Verify anchors match
        $hasVueTl = stripos($vueTemplate, 'data-px-anchor="tl"') !== false;
        $hasHtmlTl = stripos($htmlBody, 'data-px-anchor="tl"') !== false;
        if ($hasVueTl !== $hasHtmlTl) {
            $errors[] = "TL anchor mismatch: .vue=" . ($hasVueTl ? 'yes' : 'no') . " .html=" . ($hasHtmlTl ? 'yes' : 'no') . " — add/remove data-px-anchor=\"tl\"";
        }
        $hasVueBr = stripos($vueTemplate, 'data-px-anchor="br"') !== false;
        $hasHtmlBr = stripos($htmlBody, 'data-px-anchor="br"') !== false;
        if ($hasVueBr !== $hasHtmlBr) {
            $errors[] = "BR anchor mismatch: .vue=" . ($hasVueBr ? 'yes' : 'no') . " .html=" . ($hasHtmlBr ? 'yes' : 'no') . " — add/remove data-px-anchor=\"br\"";
        }

        // Extract anchor and text content for basic consistency check
        // .vue: get all text snippets (between > and <)
        preg_match_all('/>([^<]{3,})</', $vueTemplate, $vueTexts);
        // .html: get all text snippets in body
        preg_match_all('/>([^<]{3,})</', $htmlBody, $htmlTexts);

        $vueUnique = array_unique(array_map('trim', $vueTexts[1]));
        $htmlUnique = array_unique(array_map('trim', $htmlTexts[1]));

        // Check key test content text appears in .html (allow extra elements like sidebar nav)
        $foundCount = 0;
        foreach ($vueUnique as $txt) {
            if (empty($txt)) continue;
            $normalized = trim(preg_replace('/\s+/', ' ', $txt));
            foreach ($htmlUnique as $ht) {
                if (stripos($ht, $normalized) !== false || stripos($normalized, $ht) !== false) {
                    $foundCount++;
                    break;
                }
            }
        }

        // Require at least 50% of .vue text content to appear in .html
        $threshold = max(1, (int)(count($vueUnique) * 0.5));
        if ($foundCount < $threshold) {
            $errors[] = "Text content mismatch: only $foundCount/" . count($vueUnique) . " .vue texts found in .html — expected at least $threshold";
        }

        return $errors;
    }

    /**
     * Inject dump_layout.js and textarea only (no CSS modification).
     *
     * The .html must already contain CSS baseline per validateHtmlSpec().
     * This method ONLY adds the testing infrastructure: hidden textarea
     * output container + dump_layout.js script before </body>.
     */
    private function instrumentHtml(string $htmlPath): string
    {
        $html = @file_get_contents($htmlPath);
        if ($html === false) return '';

        // Load dump_layout.js from tools/
        $projectRoot = dirname($this->appDir, 2);
        $dumpLayoutJsPath = $projectRoot . '/tools/dump_layout.js';
        $dumpLayoutJs = file_exists($dumpLayoutJsPath) ? @file_get_contents($dumpLayoutJsPath) : '';

        // Build injection: textarea + dump_layout.js (no CSS, CSS must be in .html)
        $injectJs = '<textarea id="layout-output" style="display:none;"></textarea>' . "\n";
        if ($dumpLayoutJs !== '') {
            $injectJs .= '<script>' . $dumpLayoutJs . '</script>';
        } else {
            echo "  [instrument] WARNING: dump_layout.js not found at $dumpLayoutJsPath\n";
        }

        // Inject before </body> so DOM is guaranteed to be ready
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $injectJs . '</body>', $html);
        } else {
            $html .= $injectJs;
        }

        return $html;
    }
}
