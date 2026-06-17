<?php

namespace PxTest\Pipeline\Strategy;

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
        $refDir = "{$this->appDir}/test_case/{$this->caseName}/ref";
        $result = $this->strategy->dump($this->caseName, $refDir);
        if ($result === null) {
            return StepResult::err('dump_layout', 'Strategy ' . $this->strategy->name() . ' failed');
        }

        // REF_STALE check: verify layout JSON contains test case key content
        $json = $result[0];
        $caseDir = "{$this->appDir}/test_case/{$this->caseName}";
        $staleErrors = $this->validateContent($json, $caseDir);
        if (!empty($staleErrors)) {
            foreach ($staleErrors as $e) {
                echo "  [REF_STALE] $e\n";
            }
        }

        $ctx->set('layout_json', $json);
        $ctx->set('layout_path', $result[1]);
        $ctx->set('layout_content_valid', empty($staleErrors));
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
 * BrowserRefStep — with wrapper HTML injection at maximum CSS priority.
 *
 * Injects normalize.css reset + @font-face + font-size baseline
 * with !important to ensure engine-consistent rendering in Edge.
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
        $caseDir = "{$this->appDir}/test_case/{$this->caseName}";
        $htmlFiles = glob("$caseDir/*.html");
        if (empty($htmlFiles)) return StepResult::err('browser_ref', 'No HTML file found');

        // Build wrapper with CSS baseline injection at !important priority
        $wrappedHtml = $this->buildCssTestWrapper($htmlFiles[0], $caseDir);
        $refDir = "$caseDir/ref";
        @mkdir($refDir, 0777, true);
        $wrapperPath = "$refDir/wrapper.html";
        file_put_contents($wrapperPath, $wrappedHtml);

        $ok = $this->strategy->generate($wrapperPath, $refDir, $this->caseName);
        $ctx->set('browser_wrapper_html', $wrappedHtml);
        return $ok ? StepResult::ok('browser_ref') : StepResult::err('browser_ref', 'Strategy ' . $this->strategy->name() . ' failed');
    }

    /**
     * Build wrapper HTML with CSS baseline injection.
     *
     * Injected styles use !important to ensure MAX priority over any
     * existing styles in the test HTML, guaranteeing engine-consistent
     * rendering baseline (box-sizing, font, line-height, background).
     */
    private function buildCssTestWrapper(string $htmlPath, string $caseDir): string
    {
        $html = @file_get_contents($htmlPath);
        if ($html === false) return '';

        $injectCss = '<style>
/* PxTest Wrapper Baseline — !important max priority */
*,*::before,*::after {
    margin:0 !important;
    padding:0 !important;
    box-sizing:border-box !important;
}
html,body {
    width:1600px !important;
    height:800px !important;
    overflow:hidden !important;
    font-family:"Segoe UI","Noto Sans SC",sans-serif !important;
    font-size:16px !important;
    line-height:1.2 !important;
    background:#fff !important;
    color:#000 !important;
}</style>';

        // Inject before </head> or after <html>
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $injectCss . '</head>', $html);
        } elseif (stripos($html, '<body') !== false) {
            $html = preg_replace('/(<body[^>]*>)/i', $injectCss . '$1', $html);
        } else {
            $html = '<!DOCTYPE html><html><head>' . $injectCss . '</head>' . $html . '</html>';
        }

        // Verify font declarations are present
        if (stripos($html, 'font-family') === false) {
            echo "  [wrapper] WARNING: No font-family declaration found\n";
        }

        return $html;
    }
}
