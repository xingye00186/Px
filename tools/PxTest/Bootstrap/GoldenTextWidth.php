<?php

namespace PxTest\Bootstrap;

/**
 * GoldenTextWidth — 黄金宽度表查询类
 *
 * 存储预测量的浏览器文本宽度，使 PHP Runtime 模式下的文本测量
 * 与浏览器（Edge）精确一致。宽度数据在浏览器中预测量后，
 * 存入 golden_text_widths.json。
 *
 * 仅在 PX_PHP_RUNTIME=1 且 class_exists 路径下激活，
 * 对 AOT 编译模式零影响。
 */
class GoldenTextWidth
{
    /** @var array<string, array<string, int>> [text][fontSize,bold] = width */
    private static ?array $table = null;

    /** @var string JSON 数据文件路径 */
    private static string $dataFile = '';

    /**
     * 设置数据文件路径（在 PhpDumpStrategy 中调用）。
     */
    public static function setDataFile(string $path): void
    {
        self::$dataFile = $path;
        self::$table = null; // 强制重载
    }

    /**
     * 测量文本宽度，优先查黄金表，未命中返回 null。
     *
     * @param string $text     文本内容
     * @param int    $fontSize 字号 (px)
     * @param bool   $bold     是否粗体
     * @return int|null 宽度 px，未命中返回 null
     */
    public static function measure(string $text, int $fontSize, bool $bold): ?int
    {
        if (self::$table === null) {
            self::loadTable();
        }

        $boldKey = $bold ? 1 : 0;
        if (isset(self::$table[$text][$fontSize][$boldKey])) {
            return self::$table[$text][$fontSize][$boldKey];
        }

        return null;
    }

    /**
     * 批量注册黄金宽度（供生成工具调用）。
     *
     * @param array $entries 每条 {text, fontSize, bold, width}
     */
    public static function registerBatch(array $entries): void
    {
        if (self::$table === null) {
            self::$table = [];
        }
        foreach ($entries as $e) {
            $text = $e['text'] ?? '';
            $fs = (int)($e['fontSize'] ?? 16);
            $bd = $e['bold'] ? 1 : 0;
            $w = (int)($e['width'] ?? 0);
            if ($text !== '' && $w > 0) {
                self::$table[$text][$fs][$bd] = $w;
            }
        }
    }

    /**
     * 从 JSON 文件加载黄金表。
     */
    private static function loadTable(): void
    {
        self::$table = [];
        if (self::$dataFile === '' || !file_exists(self::$dataFile)) {
            return;
        }
        $json = file_get_contents(self::$dataFile);
        $data = json_decode($json, true);
        if (!$data || !isset($data['entries'])) {
            return;
        }
        foreach ($data['entries'] as $e) {
            $text = $e['text'] ?? '';
            $fs = (int)($e['fontSize'] ?? 16);
            $bd = $e['bold'] ? 1 : 0;
            $w = (int)($e['width'] ?? 0);
            if ($text !== '' && $w > 0) {
                self::$table[$text][$fs][$bd] = $w;
            }
        }
    }
}
