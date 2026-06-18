<?php

namespace PxTest\Pipeline\Strategy;

use PxTest\Infrastructure\BrowserLauncher;

/**
 * 浏览器参考策略接口。
 */
interface BrowserRefStrategy
{
    public function generate(string $htmlPath, string $refDir, string $caseName): bool;
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

        // Save raw --dump-dom output for debugging
        file_put_contents("$refDir/browser_ref_dom.html", $domOutput);

        // Extract JSON from <textarea id="layout-output"> injected by dump_layout.js
        // The textarea is hidden (display:none), but --dump-dom includes its textContent
        if (preg_match('/<textarea[^>]*id="layout-output"[^>]*>([\s\S]*?)<\/textarea>/i', $domOutput, $m)) {
            $layoutJson = trim($m[1]);
            // Validate it's actual JSON
            $decoded = json_decode($layoutJson, true);
            if ($decoded !== null && isset($decoded['elements'])) {
                // Write structured element data for element_compare
                file_put_contents("$refDir/browser_ref_level_0.json", $layoutJson);
                echo "  [edge_dom] extracted " . count($decoded['elements']) . " elements from browser DOM\n";
                return true;
            }
        }

        // Fallback: dump_layout.js didn't execute (maybe non-HTML5 browser?), save raw DOM
        echo "  [edge_dom] WARNING: layout-output textarea not found, saving raw DOM only\n";
        return true;
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
}

/** 跳过策略 */
class NoopBrowserRefStrategy implements BrowserRefStrategy
{
    public function name(): string { return 'noop'; }
    public function generate(string $h, string $d, string $c): bool { return false; }
}
