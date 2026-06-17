<?php

namespace PxTest\Infrastructure;

/**
 * 字体提供器 — 管理 NotoSansSC 字体路径和 @font-face 声明。
 */
class FontProvider
{
    private string $fontDir;

    public function __construct(string $projectRoot)
    {
        $this->fontDir = rtrim($projectRoot, '/\\') . '/cpp/fonts';
    }

    public function getRegularPath(): string
    {
        return $this->fontDir . '/NotoSansSC-Regular.ttf';
    }

    public function getBoldPath(): string
    {
        return $this->fontDir . '/NotoSansSC-Bold.ttf';
    }

    public function isReady(): bool
    {
        return file_exists($this->getRegularPath());
    }

    public function getFontName(): string
    {
        return 'Noto Sans SC';
    }

    /** 生成浏览器用的 @font-face CSS */
    public function fontFaceCss(): string
    {
        $regularPath = $this->getRegularPath();
        $boldPath = $this->getBoldPath();
        $regularUrl = $this->isReady()
            ? "url('file:///" . str_replace('\\', '/', $regularPath) . "')"
            : "local('Noto Sans SC')";
        $boldUrl = $this->isReady() && file_exists($boldPath)
            ? "url('file:///" . str_replace('\\', '/', $boldPath) . "')"
            : "local('Noto Sans SC Bold')";

        return "@font-face {\n"
            . "  font-family: '{$this->getFontName()}';\n"
            . "  src: local('{$this->getFontName()}'), {$regularUrl};\n"
            . "  font-weight: 400;\n"
            . "}\n"
            . "@font-face {\n"
            . "  font-family: '{$this->getFontName()}';\n"
            . "  src: local('{$this->getFontName()} Bold'), {$boldUrl};\n"
            . "  font-weight: 700;\n"
            . "}\n";
    }
}
