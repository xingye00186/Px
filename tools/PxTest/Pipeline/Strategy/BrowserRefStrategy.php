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

/** Edge DOM dump 策略 */
class EdgeDomStrategy implements BrowserRefStrategy
{
    private BrowserLauncher $browser;
    public function __construct(BrowserLauncher $b = null) { $this->browser = $b ?? new BrowserLauncher(); }
    public function name(): string { return 'edge_dom'; }
    public function generate(string $htmlPath, string $refDir, string $caseName): bool
    {
        if (!$this->browser->isAvailable()) return false;
        $json = $this->browser->dumpDom($htmlPath, 1600, 800);
        if ($json === null) return false;
        file_put_contents("$refDir/browser_ref_elements.json", $json);
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
