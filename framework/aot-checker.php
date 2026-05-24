<?php

/**
 * AOT Checker - AOT 兼容性全面检查工具
 *
 * 检测所有 AOT 禁止规则，包括：
 *   - 可变变量 $$var
 *   - 动态属性访问 ->$var
 *   - 动态方法调用 ->$method()
 *   - 可变函数调用 $fn()
 *   - extract() / eval() / include() 等
 *   - __get/__set 等魔术方法
 *   - 字符串包含 \0
 *   - 资源所有权规则 (hWnd/hdc)
 *
 * 使用方式:
 *   php framework/aot-checker.php <path>                    # 扫描目录
 *   php framework/aot-checker.php --project <dir>            # 基于 project.yml 扫描
 *   php framework/aot-checker.php --all                      # 扫描所有项目
 *   php framework/aot-checker.php --json <path>             # JSON 输出
 *   php framework/aot-checker.php --skip direct_cpp_call    # 跳过特定规则检查
 *   php framework/aot-checker.php --skip hwnd,hdc            # 跳过多个规则（逗号分隔）
 *   php framework/aot-checker.php --help                    # 显示帮助
 *
 * 注意: any() 是 AOT 内置函数，用于将变量类型标注为 php::Var，是可用的，不检测！
 */

class AotChecker
{
    /** 检测规则定义 */
    private array $rules = [
        // ========== 核心 AOT 禁止规则 ==========

        // 1. 可变变量 $$var
        'aot_dynamic_variable' => [
            'severity' => 'ERROR',
            'pattern' => '/\$\$\w+/',
            'message' => 'AOT: 可变变量 $$var（AOT 不支持）',
        ],

        // 2. 动态属性访问 ->$var (包括嵌套链)
        'aot_variable_property' => [
            'severity' => 'ERROR',
            'pattern' => '/->\$\w+(?!\s*\()/',
            'message' => 'AOT: 动态属性访问 ->$var（AOT 不支持）',
        ],

        // 3. 动态方法调用 ->$method()
        'aot_variable_method' => [
            'severity' => 'ERROR',
            'pattern' => '/->\$\w+\s*\(/',
            'message' => 'AOT: 动态方法调用 ->$method()（AOT 不支持）',
        ],

        // 4. 变量调用 $fn() — AOT 支持闭包调用，此规则仅作提醒
        'aot_variable_function' => [
            'severity' => 'WARNING',
            'pattern' => '/(?<![>\w])\$\w+\s*\(/',
            'message' => 'AOT: $fn() 调用 — 闭包合法，确保变量类型是 Closure 而非字符串函数名',
        ],

        // 5. extract()
        'aot_extract' => [
            'severity' => 'ERROR',
            'pattern' => '/\bextract\s*\(/',
            'message' => 'AOT: extract() 动态变量注入（AOT 不支持）',
        ],

        // 6. yield 生成器
        'aot_yield' => [
            'severity' => 'ERROR',
            'pattern' => '/\byield\s+/',
            'message' => 'AOT: yield 生成器（AOT 不支持）',
        ],

        // 7. eval/include 动态加载
        'aot_eval_include' => [
            'severity' => 'ERROR',
            'pattern' => '/\b(eval|include|require|include_once|require_once)\s*\(/',
            'message' => 'AOT: eval/include 动态加载（AOT 不支持）',
        ],

        // 8. __get/__set 魔术方法
        'aot_magic_methods' => [
            'severity' => 'ERROR',
            'pattern' => '/function\s+__(get|set|call|__callStatic)\s*\(/',
            'message' => 'AOT: __get/__set 等魔术方法（AOT 中不可靠）',
        ],

        // 9. 字符串包含 \0 (null 字节)
        'aot_null_byte' => [
            'severity' => 'ERROR',
            'pattern' => '/["\']\\[0]["\']]/',
            'message' => 'AOT: 字符串包含 \\0（AOT 不支持 null 字节）',
        ],

        // 10. call_user_func 系列
        'aot_call_user_func' => [
            'severity' => 'ERROR',
            'pattern' => '/\b(call_user_func|call_user_func_array)\s*\(/',
            'message' => 'AOT: call_user_func 动态调用（AOT 不支持）',
        ],

        // ========== 资源所有权规则 ==========

        'hwnd_in_app' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+Application\b[\s\S]*?\{[\s\S]*?private\s+.*?\$hWnd/s',
            'message' => 'Application 不应持有 hWnd 属性',
        ],

        'hwnd_in_renderer' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+VNodeRenderer\b[\s\S]*?\{[\s\S]*?private\s+.*?\$hWnd/s',
            'message' => 'VNodeRenderer 不应持有 hWnd 属性',
        ],

        'hdc_in_renderer' => [
            'severity' => 'ERROR',
            'pattern' => '/class\s+VNodeRenderer\b[\s\S]*?\{[\s\S]*?private\s+.*?\$hdc/s',
            'message' => 'VNodeRenderer 不应持有 hdc 属性',
        ],

        'hdc_param_in_methods' => [
            'severity' => 'WARN',
            'pattern' => '/(fillRect|drawText|drawButton)\s*\(\s*[^)]*?\$hdc[^)]*?\)/',
            'message' => '绘制方法不应接收 hdc 参数',
        ],

        'direct_cpp_call' => [
            'severity' => 'ERROR',
            'pattern' => '/vue_(window_create|window_show|begin_paint|end_paint|fill_rect|draw_text|draw_button|peek_message|quit_requested)\s*\(/',
            'message' => '业务代码不应直接调用 vue_* C++ 函数，应通过 GdiRenderContext',
        ],
    ];

    /** 排除的文件（平台封装层，直接调用 C++ 函数是合法的） */
    private array $excludedFiles = [
        'GdiRenderContext.php',
        'Win32Platform.php',
        'vue_calc.cc',
        'aot-checker.php',
        'aot-validator.php',
    ];

    /** 排除的目录 */
    private array $excludedDirs = [
        'compiler',
    ];

    /** 检查结果 */
    private array $errors = [];
    private array $warnings = [];
    private array $filesScanned = [];

    /** 输出格式 */
    private bool $jsonOutput = false;

    /** 跳过的规则列表 */
    private array $skipRules = [];

    /**
     * 解析命令行参数并执行检查
     */
    public static function main(array $argv): int
    {
        $checker = new self();

        // 解析参数
        $path = null;
        $mode = 'dir'; // 'dir', 'project', 'all'

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '--project') {
                $mode = 'project';
                $path = $argv[$i + 1] ?? null;
                $i++;
            } elseif ($arg === '--all') {
                $mode = 'all';
                $path = dirname(__DIR__); // 框架根目录
            } elseif ($arg === '--json') {
                $checker->jsonOutput = true;
            } elseif ($arg === '--skip') {
                // 解析跳过的规则（支持逗号分隔）
                $skipArg = $argv[$i + 1] ?? '';
                $skipList = explode(',', $skipArg);
                foreach ($skipList as $rule) {
                    $rule = trim($rule);
                    if ($rule !== '') {
                        $checker->skipRules[] = $rule;
                    }
                }
                $i++;
            } elseif ($arg === '--help' || $arg === '-h') {
                echo "AOT Checker - AOT 兼容性检查工具\n";
                echo "\n用法:\n";
                echo "  php aot-checker.php <path>                    # 扫描目录\n";
                echo "  php aot-checker.php --project <dir>         # 基于 project.yml 扫描\n";
                echo "  php aot-checker.php --all                  # 扫描所有项目\n";
                echo "  php aot-checker.php --json <path>          # JSON 输出\n";
                echo "  php aot-checker.php --skip <rule>          # 跳过特定规则检查\n";
                echo "  php aot-checker.php --skip rule1,rule2     # 跳过多个规则（逗号分隔）\n";
                echo "  php aot-checker.php --help                  # 显示帮助\n";
                echo "\n可用规则:\n";
                echo "  aot_dynamic_variable, aot_variable_property, aot_variable_method,\n";
                echo "  aot_variable_function, aot_extract, aot_yield, aot_eval_include,\n";
                echo "  aot_magic_methods, aot_null_byte, aot_call_user_func,\n";
                echo "  hwnd_in_app, hwnd_in_renderer, hdc_in_renderer,\n";
                echo "  hdc_param_in_methods, direct_cpp_call\n";
                return 0;
            } elseif ($arg[0] !== '-') {
                $path = $arg;
            }
        }

        if ($path === null) {
            $path = __DIR__; // 默认扫描 framework/
        }

        // 执行检查
        switch ($mode) {
            case 'project':
                $files = $checker->scanProject($path);
                break;
            case 'all':
                $files = $checker->scanAllProjects($path);
                break;
            default:
                $files = $checker->scanDirectory($path);
        }

        // 扫描文件
        foreach ($files as $file) {
            $checker->checkFile($file);
        }

        // 输出结果
        $checker->report();

        return $checker->hasErrors() ? 1 : 0;
    }

    /**
     * 扫描目录
     */
    public function scanDirectory(string $path): array
    {
        $files = [];
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $fullPath = $file->getPathname();
                // 排除指定目录下的文件
                $relativePath = $this->getRelativePath($path, $fullPath);
                foreach ($this->excludedDirs as $excludedDir) {
                    if ($this->pathStartsWith($relativePath, $excludedDir)) {
                        continue 2;
                    }
                }
                $files[] = $fullPath;
            }
        } elseif (is_file($path)) {
            $files[] = $path;
        }
        return $files;
    }

    /**
     * 基于 project.yml 扫描
     */
    public function scanProject(string $projectDir): array
    {
        $ymlPath = rtrim($projectDir, '/\\') . '/project.yml';
        if (!file_exists($ymlPath)) {
            throw new \RuntimeException("project.yml not found: $ymlPath");
        }

        $content = file_get_contents($ymlPath);
        $sources = $this->extractSources($content);

        $files = [];
        foreach ($sources as $source) {
            $fullPath = $this->resolvePath($source, $projectDir);
            if (is_dir($fullPath)) {
                $files = array_merge($files, $this->scanDirectory($fullPath));
            } elseif (is_file($fullPath)) {
                // 排除指定目录下的文件
                $relativePath = $this->getRelativePath($projectDir, $fullPath);
                $skip = false;
                foreach ($this->excludedDirs as $excludedDir) {
                    if ($this->pathStartsWith($relativePath, $excludedDir)) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $files[] = $fullPath;
                }
            }
        }

        return $files;
    }

    /**
     * 扫描所有项目
     */
    public function scanAllProjects(string $frameworkRoot): array
    {
        $appsDir = $frameworkRoot . '/apps';
        if (!is_dir($appsDir)) {
            return [];
        }

        $files = [];
        $dirs = glob($appsDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (is_dir($dir . '/gen') || file_exists($dir . '/project.yml')) {
                try {
                    $projectFiles = $this->scanProject($dir);
                    $files = array_merge($files, $projectFiles);
                } catch (\RuntimeException $e) {
                    // 跳过没有 project.yml 的目录
                }
            }
        }

        return $files;
    }

    /**
     * 从 project.yml 提取 sources
     */
    private function extractSources(string $ymlContent): array
    {
        $sources = [];
        // 简单解析：提取 sources: 下的所有 - 开头的行
        if (preg_match('/^sources:\s*$/m', $ymlContent)) {
            $lines = explode("\n", $ymlContent);
            $inSources = false;
            foreach ($lines as $line) {
                if (preg_match('/^sources:\s*$/', $line)) {
                    $inSources = true;
                    continue;
                }
                if ($inSources) {
                    if (preg_match('/^\s+- (.+)$/', $line, $matches)) {
                        $sources[] = trim($matches[1]);
                    } elseif (preg_match('/^\S/', $line) && !preg_match('/^-/', $line)) {
                        // 遇到非 sources 段落的顶级键，退出
                        if (!preg_match('/^\s+/', $line)) {
                            break;
                        }
                    }
                }
            }
        }
        return $sources;
    }

    /**
     * 解析相对路径
     */
    private function resolvePath(string $source, string $projectDir): string
    {
        // 处理相对路径
        if (strpos($source, '../') === 0) {
            return $projectDir . '/' . $source;
        }
        // 处理 ./gen 等相对路径
        if (strpos($source, './') === 0) {
            return $projectDir . '/' . $source;
        }
        // 相对路径
        if ($source[0] !== '/' && !preg_match('/^[a-zA-Z]:/', $source)) {
            return $projectDir . '/' . $source;
        }
        return $source;
    }

    /**
     * 获取相对路径（统一路径分隔符）
     */
    private function getRelativePath(string $base, string $full): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $full = rtrim(str_replace('\\', '/', $full), '/');
        // 移除 base 前缀
        if (str_starts_with($full, $base)) {
            $relative = substr($full, strlen($base));
            return ltrim($relative, '/');
        }
        return $full;
    }

    /**
     * 检查路径是否以指定目录开头（跨平台）
     */
    private function pathStartsWith(string $path, string $dir): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $dir = ltrim(str_replace('\\', '/', $dir), '/');
        return str_starts_with($path, $dir . '/') || $path === $dir;
    }

    /**
     * 检查单个文件
     */
    public function checkFile(string $filepath): void
    {
        $filename = basename($filepath);

        // 跳过排除的文件
        if (in_array($filename, $this->excludedFiles)) {
            return;
        }

        $this->filesScanned[] = $filepath;

        $content = file_get_contents($filepath);
        if ($content === false) {
            return;
        }

        // 移除注释以避免误报
        $content = $this->stripComments($content);

        foreach ($this->rules as $ruleId => $rule) {
            // 跳过指定的规则
            if (in_array($ruleId, $this->skipRules)) {
                continue;
            }

            // 对于 direct_cpp_call 规则，排除 GdiRenderContext
            if ($ruleId === 'direct_cpp_call' && $filename === 'GdiRenderContext.php') {
                continue;
            }

            if (preg_match_all($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $pos = $match[1];
                    $lineNumber = substr_count(substr($content, 0, $pos), "\n") + 1;
                    $matchedText = $match[0];

                    // 提取上下文代码（周围 60 字符）
                    $start = max(0, $pos - 30);
                    $end = min(strlen($content), $pos + strlen($matchedText) + 30);
                    $context = substr($content, $start, $end - $start);
                    $context = str_replace(["\n", "\r"], ' ', $context);
                    if ($start > 0) $context = '...' . $context;
                    if ($end < strlen($content)) $context = $context . '...';

                    if ($rule['severity'] === 'ERROR') {
                        $this->errors[] = [
                            'file' => $filepath,
                            'line' => $lineNumber,
                            'rule' => $ruleId,
                            'code' => $matchedText,
                            'context' => $context,
                            'message' => $rule['message'],
                        ];
                    } else {
                        $this->warnings[] = [
                            'file' => $filepath,
                            'line' => $lineNumber,
                            'rule' => $ruleId,
                            'code' => $matchedText,
                            'context' => $context,
                            'message' => $rule['message'],
                        ];
                    }
                }
            }
        }
    }

    /**
     * 移除 PHP 注释以避免误报
     */
    private function stripComments(string $code): string
    {
        // 移除单行注释 //
        $code = preg_replace('#//.*$#m', '', $code) ?? $code;
        // 移除多行注释 /* */
        $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
        // 移除 heredoc/nowdoc
        $code = preg_replace('/<<<[A-Z]+\s*\$?\w*\s*[\s\S]*?^[A-Z]+;$/m', '', $code) ?? $code;
        return $code;
    }

    /**
     * 输出检查结果
     */
    public function report(): void
    {
        if ($this->jsonOutput) {
            $this->reportJson();
            return;
        }

        echo "========================================\n";
        echo "  AOT Checker - AOT 兼容性检查工具\n";
        echo "========================================\n\n";

        echo "已扫描 " . count($this->filesScanned) . " 个文件\n\n";

        if (empty($this->errors) && empty($this->warnings)) {
            echo "[OK] 未检测到 AOT 兼容性问题\n";
            return;
        }

        if (!empty($this->errors)) {
            echo "ERRORS (" . count($this->errors) . "):\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($this->errors as $err) {
                echo "  [ERROR] {$err['file']}:{$err['line']}\n";
                echo "          Rule: {$err['rule']}\n";
                echo "          Code: {$err['code']}\n";
                echo "          {$err['message']}\n\n";
            }
        }

        if (!empty($this->warnings)) {
            echo "WARNINGS (" . count($this->warnings) . "):\n";
            echo str_repeat('-', 60) . "\n";
            foreach ($this->warnings as $warn) {
                echo "  [WARN] {$warn['file']}:{$warn['line']}\n";
                echo "         Rule: {$warn['rule']}\n";
                echo "         Code: {$warn['code']}\n";
                echo "         {$warn['message']}\n\n";
            }
        }

        echo "\n共发现 " . count($this->errors) . " 个错误, " . count($this->warnings) . " 个警告\n";
    }

    /**
     * JSON 格式输出
     */
    private function reportJson(): void
    {
        $result = [
            'files_scanned' => count($this->filesScanned),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'summary' => [
                'error_count' => count($this->errors),
                'warning_count' => count($this->warnings),
            ],
        ];

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 返回是否有错误
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * 获取错误列表
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 获取警告列表
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}

// CLI 入口
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    exit(AotChecker::main($argv));
}