<?php

namespace PxTest\Pipeline\Strategy;

/**
 * 布局导出策略接口 — 支持 Exe 和 Mock 双轨。
 */
interface DumpStrategy
{
    /** 导出布局 JSON，返回数组 [json, filePath] 或 null */
    public function dump(string $caseName, string $refDir): ?array;
    public function name(): string;
}

/** AOT exe 策略 */
class ExeDumpStrategy implements DumpStrategy
{
    private string $exePath;
    public function __construct(string $exePath) { $this->exePath = $exePath; }
    public function name(): string { return 'exe'; }
    public function dump(string $caseName, string $refDir): ?array
    {
        $outFile = "$refDir/engine_layout_aot.json";
        $cmd = sprintf('"%s" --case=%s --headless --dump-layout 2>&1', $this->exePath, $caseName);
        exec($cmd, $output, $exitCode);
        if (!file_exists($outFile)) return null;
        return [file_get_contents($outFile), $outFile];
    }
}

/** Mock 平台策略（纯 PHP，无需 AOT） */
class MockDumpStrategy implements DumpStrategy
{
    public function name(): string { return 'mock'; }
    public function dump(string $caseName, string $refDir): ?array
    {
        if (!defined('APP_PLATFORM')) define('APP_PLATFORM', 'win32');
        if (!defined('WINDOW_WIDTH'))  define('WINDOW_WIDTH', 1600);
        if (!defined('WINDOW_HEIGHT')) define('WINDOW_HEIGHT', 800);
        if (!defined('WINDOW_TITLE'))  define('WINDOW_TITLE', 'CSS Test');
        // 通过 Application + MockPlatform 渲染
        $app = new \Px\Core\Application(new \PxTest\Mock\MockPlatform(1600, 800), new \Px\Core\Scheduler());
        // 创建简单 VNode 模拟测试用例
        $vnode = \Px\Rendering\VNode::h('div', ['style' => 'width:1600px;height:800px'], []);
        $comp = new \PxTest\Mock\MockComponent($caseName, $app, new \Px\Core\Scheduler());
        $comp->mockVNode = $vnode;
        $app->mount($comp);
        $rm = new \ReflectionMethod(\Px\Core\Application::class, 'render');
        $rm->setAccessible(true);
        $rm->invoke($app); $rm->invoke($app);
        @mkdir($refDir, 0777, true);
        $outFile = "$refDir/engine_layout_mock.json";
        $app->dumpLayoutToFile($outFile);
        $json = file_get_contents($outFile);
        if ($json === false || $json === '') return null;
        return [$json, $outFile];
    }
}

/** 跳过策略 */
class NoopDumpStrategy implements DumpStrategy
{
    public function name(): string { return 'noop'; }
    public function dump(string $caseName, string $refDir): ?array { return null; }
}
