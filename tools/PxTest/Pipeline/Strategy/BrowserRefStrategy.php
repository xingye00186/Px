<?php

namespace PxTest\Pipeline\Strategy;

use PxTest\Infrastructure\BrowserLauncher;

/**
 * 浏览器参考策略接口。
 */
interface BrowserRefStrategy
{
    public function generate(string $htmlPath, string $refDir, string $caseName): bool;
    /**
     * 批次生成多个 case 的 browser ref（单次 Edge 启动）。
     * @param array $cases [['tag'=>'case-xxx', 'htmlPath'=>'...', 'refDir'=>'...'], ...]
     */
    public function generateBatch(array $cases): bool;
    public function name(): string;
}

/**
 * Edge DOM dump 策略 — 注入 dump_layout.js 提取浏览器元素位置/样式。
 *
 * 工作流程:
 *   1. BrowserRefStep::buildCssTestWrapper() 已注入 dump_layout.js + <textarea id="layout-output">
 *   2. Edge headless --dump-dom 渲染页面，JS 自动执行
 *   3. dump_layout.js 遍历 DOM，用 getBoundingClientRect() + getComputedStyle() 提取元素数据
 *   4. JSON 写入 <textarea id="layout-output">，--dump-dom 输出完整 HTML
 *   5. 此策略从 HTML 输出中提取该 textarea 的 JSON 内容
 */
class EdgeDomStrategy implements BrowserRefStrategy
{
    private BrowserLauncher $browser;
    public function __construct(BrowserLauncher $b = null) { $this->browser = $b ?? new BrowserLauncher(); }
    public function name(): string { return 'edge_dom'; }
    public function generate(string $htmlPath, string $refDir, string $caseName): bool
    {
        if (!$this->browser->isAvailable()) return false;
        $domOutput = $this->browser->dumpDom($htmlPath, 1600, 800);
        if ($domOutput === null) return false;

        // Extract JSON from <textarea id="layout-output"> injected by dump_layout.js
        if (preg_match('/<textarea[^>]*id="layout-output"[^>]*>([\s\S]*?)<\/textarea>/i', $domOutput, $m)) {
            $layoutJson = trim($m[1]);
            $decoded = json_decode($layoutJson, true);
            if ($decoded !== null && isset($decoded['elements'])) {
                // Store HTML source hash for skip-ref detection
                $htmlHash = md5_file($htmlPath);
                $decoded['_html_hash'] = $htmlHash;
                $layoutJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                file_put_contents("$refDir/browser_ref_level_0.json", $layoutJson);
                echo "  [edge_dom] extracted " . count($decoded['elements']) . " elements from browser DOM\n";
                return true;
            }
        }
        echo "  [edge_dom] WARNING: layout-output textarea not found\n";
        return true;
    }

    /**
     * 批次模式：单次 Edge 启动，生成所有 case 的 browser ref。
     * 由 instrumentHtml() 生成带 data-case 容器的合并 HTML，
     * dump_layout.js 检测到 data-case 后自动进入批次模式。
     */
    public function generateBatch(array $cases): bool
    {
        if (!$this->browser->isAvailable() || empty($cases)) return false;

        $tempDir = sys_get_temp_dir();
        $projectRoot = dirname(dirname(dirname(__DIR__))); // 3 levels up from tools/PxTest/Pipeline
        $dumpLayoutJsPath = $projectRoot . '/tools/dump_layout.js';
        $dumpLayoutJs = file_exists($dumpLayoutJsPath) ? file_get_contents($dumpLayoutJsPath) : '';

        $caseStyles = '';
        $caseBodies = '';
        foreach ($cases as $case) {
            $tag = $case['tag'];
            $htmlPath = $case['htmlPath'];
            $html = file_get_contents($htmlPath);
            if ($html === false) continue;

            // 提取 <style> 块并作用域化
            $scopedStyles = [];
            if (preg_match_all('/<style[^>]*>([\s\S]*?)<\/style>/i', $html, $styleMatches)) {
                foreach ($styleMatches[1] as $css) {
                    $scoped = preg_replace(
                        '/([.#]?[a-zA-Z*][\w-]*(?:\s*,\s*[.#]?[a-zA-Z][\w-]*)*)\s*\{/',
                        "[data-case=\"$tag\"] $0",
                        $css
                    );
                    $scopedStyles[] = $scoped;
                }
            }
            $caseStyles .= implode("\n", $scopedStyles) . "\n";

            // 提取 <body> 内内容
            if (preg_match('/<body[^>]*>([\s\S]*)<\/body>/i', $html, $bodyMatch)) {
                $caseBodies .= "<div data-case=\"$tag\">{$bodyMatch[1]}</div>\n";
            }
        }

        if (empty($caseBodies)) return false;

        // 构建批次 HTML
        $batchHtml = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $batchHtml .= '<style>' . $caseStyles . '</style>';
        $batchHtml .= '</head><body>';
        $batchHtml .= $caseBodies;
        $batchHtml .= '<textarea id="layout-output" style="display:none;"></textarea>';
        if ($dumpLayoutJs !== '') {
            $batchHtml .= '<script>' . $dumpLayoutJs . '</script>';
        }
        $batchHtml .= '</body></html>';

        $batchPath = "$tempDir/px_batch_ref.html";
        file_put_contents($batchPath, $batchHtml);

        // 单次 Edge 启动
        $domOutput = $this->browser->dumpDom($batchPath);
        @unlink($batchPath);

        if ($domOutput === null) return false;

        // 从 DOM 输出中提取 #layout-output
        if (!preg_match('/<textarea[^>]*id="layout-output"[^>]*>([\s\S]*?)<\/textarea>/i', $domOutput, $m)) {
            echo "  [edge_dom] WARNING: batch layout-output not found\n";
            return false;
        }

        $batchJson = trim($m[1]);
        $decoded = json_decode($batchJson, true);
        if ($decoded === null) {
            echo "  [edge_dom] WARNING: batch JSON parse failed\n";
            return false;
        }

        // 拆分结果写入各 case ref 目录
        $count = 0;
        foreach ($cases as $case) {
            $tag = $case['tag'];
            $refDir = $case['refDir'];
            $htmlPath = $case['htmlPath'];

            if (!isset($decoded[$tag]) || !isset($decoded[$tag]['elements'])) continue;

            $refData = $decoded[$tag];
            $refData['_html_hash'] = md5_file($htmlPath);
            @mkdir($refDir, 0777, true);
            file_put_contents("$refDir/browser_ref_level_0.json", json_encode($refData, JSON_UNESCAPED_UNICODE));
            echo "  [edge_dom] batch {$tag}: " . count($refData['elements']) . " elements\n";
            $count++;
        }

        echo "  [edge_dom] batch done: $count/" . count($cases) . " cases\n";
        return $count > 0;
    }
}

/** Edge 截图策略 */
class EdgeScreenshotStrategy implements BrowserRefStrategy
{
    private BrowserLauncher $browser;
    public function __construct(BrowserLauncher $b = null) { $this->browser = $b ?? new BrowserLauncher(); }
    public function name(): string { return 'edge_screenshot'; }
    public function generate(string $htmlPath, string $refDir, string $caseName): bool
    {
        return $this->browser->screenshot($htmlPath, "$refDir/browser_ref.png", 1280, 600);
    }
    public function generateBatch(array $cases): bool { return false; }
}

/** 跳过策略 */
class NoopBrowserRefStrategy implements BrowserRefStrategy
{
    public function name(): string { return 'noop'; }
    public function generate(string $h, string $d, string $c): bool { return false; }
    public function generateBatch(array $cases): bool { return false; }
}
