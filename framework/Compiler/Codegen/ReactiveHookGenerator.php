<?php

/**
 * ReactiveHookGenerator — 响应式属性钩子代码生成器
 *
 * 生成 #[Reactive] 属性相关的所有代码：
 * - $_px_react_storage 数组声明
 * - 属性 get/set 钩子
 * - $_px_effect 字段
 * - getVNodeTree() 覆写
 * - onUnmount() 清理覆写
 * - 移除原 #[Reactive] 声明
 * - 注入数组变异通知
 * - int 类型强转包装
 *
 * 提取自 sfc-compiler.php 的 reactive codegen 函数群。
 */
class ReactiveHookGenerator
{
    /**
     * 生成 $_px_react_storage 数组声明。
     */
    public function generateStorage(array $reactiveProps): string
    {
        if (empty($reactiveProps)) return '';
        $entries = [];
        foreach ($reactiveProps as $prop) {
            $name = $prop['name'];
            $default = $prop['default'];
            $entries[] = "            '{$name}' => {$default}";
        }
        return "    private array \$_px_react_storage = [\n"
             . implode(",\n", $entries) . ",\n    ];\n";
    }

    /**
     * 生成属性钩子代码（PHP 8.4 Property Hook 语法）。
     */
    public function generateHooks(array $reactiveProps): string
    {
        if (empty($reactiveProps)) return '';
        $code = '';
        foreach ($reactiveProps as $prop) {
            $name    = $prop['name'];
            $type    = $prop['type'];
            $default = $prop['default'];

            $code .= "    public {$type} \${$name} {\n";

            // get hook
            $code .= "        get {\n";
            $code .= "            \\Px\\Reactive\\DependencyTracker::track(\$this, '{$name}');\n";
            $code .= "            return \$this->_px_react_storage['{$name}'] ?? {$default};\n";
            $code .= "        }\n";

            // set hook
            $code .= "        set ({$type} \$value) {\n";
            $code .= "            if (\$this->_px_react_storage['{$name}'] !== \$value) {\n";
            $code .= "                \$this->_px_react_storage['{$name}'] = \$value;\n";
            $code .= "                \\Px\\Reactive\\Notifier::notify(\$this, '{$name}');\n";
            $code .= "            }\n";
            $code .= "        }\n";

            $code .= "    }\n\n";
        }
        return $code;
    }

    /**
     * 生成 $_px_effect 字段声明。
     */
    public function generateEffectField(): string
    {
        return "    protected ?\\Px\\Reactive\\Effect \$_px_effect = null;\n";
    }

    /**
     * 生成 getVNodeTree() 覆写。
     */
    public function generateGetVNodeTreeOverride(): string
    {
        return "    public function getVNodeTree(): \\Px\\Dom\\VNode\n"
             . "    {\n"
             . "        if (!\$this->dirty && \$this->vnodeCache !== null) {\n"
             . "            return \$this->vnodeCache;\n"
             . "        }\n"
             . "        if (\$this->_px_effect === null) {\n"
             . "            \$this->_px_effect = new \\Px\\Reactive\\Effect(\$this);\n"
             . "        }\n"
             . "        return \\Px\\Reactive\\DependencyTracker::runWithEffect(\n"
             . "            \$this->_px_effect,\n"
             . "            function(): \\Px\\Dom\\VNode {\n"
             . "                return parent::getVNodeTree();\n"
             . "            }\n"
             . "        );\n"
             . "    }\n";
    }

    /**
     * 生成 onUnmount() 覆写（清理 Effect）。
     */
    public function generateOnUnmountWithCleanup(): string
    {
        return "    public function onUnmount(): void\n"
             . "    {\n"
             . "        if (\$this->_px_effect !== null) {\n"
             . "            \$this->_px_effect->cleanup();\n"
             . "            \$this->_px_effect = null;\n"
             . "        }\n"
             . "    }\n";
    }

    /**
     * 从类体中移除原始的 #[Reactive] 属性声明。
     */
    public function removeReactiveDeclarations(string $classBody, array $reactiveProps): string
    {
        if (empty($reactiveProps)) return $classBody;
        foreach ($reactiveProps as $prop) {
            $name = preg_quote($prop['name'], '/');
            // 匹配可选的注释行 + #[Reactive] + public 声明
            $classBody = preg_replace(
                '/^\s*\/\*\*[^*]*\*+\/\s*\n\s*#\[Reactive\]\s*\n\s*public\s+\w+\s+\$' . $name . '\s*[=;][^;]*;\s*\n?/m',
                '',
                $classBody
            );
            // 降级: 如果注释不在, 只移除 #[Reactive] + 声明
            $classBody = preg_replace(
                '/^\s*#\[Reactive\]\s*\n\s*public\s+\w+\s+\$' . $name . '\s*[=;][^;]*;\s*\n?/m',
                '',
                $classBody
            );
        }
        return $classBody;
    }

    /**
     * 注入数组变异触发通知 (v10 Reactive)
     */
    public function injectArrayMutationTriggers(string $classBody, ScriptAnalyzer $analyzer): string
    {
        $lines   = explode("\n", $classBody);
        $output  = [];
        $state   = 'outside';
        $buffer  = [];
        $braceDepth = 0;
        $methodName = '';
        $headerLines = [];

        foreach ($lines as $line) {
            if ($state === 'outside') {
                if (preg_match('/^\s*(public\s+)?function\s+(\w+)\s*\(/', $line, $m)) {
                    $methodName  = $m[2];
                    $state       = 'header';
                    $headerLines = [$line];
                    $braceDepth  = 0;
                    $buffer      = [];

                    $opens  = substr_count($line, '{');
                    $closes = substr_count($line, '}');
                    $braceDepth = $opens - $closes;

                    if ($braceDepth > 0) {
                        $bracePos = strrpos($line, '{');
                        $headerPart = substr($line, 0, $bracePos + 1);
                        $bodyPart   = substr($line, $bracePos + 1);
                        $headerLines = [$headerPart];
                        if (trim($bodyPart) !== '') {
                            $buffer[] = $bodyPart;
                        }
                        $state = 'body';
                    }
                } else {
                    $output[] = $line;
                }
            } elseif ($state === 'header') {
                $headerLines[] = $line;
                $opens  = substr_count($line, '{');
                $closes = substr_count($line, '}');
                $braceDepth += ($opens - $closes);

                if ($braceDepth > 0) {
                    $bracePos = strrpos($line, '{');
                    $headerPart = substr($line, 0, $bracePos + 1);
                    $bodyPart   = substr($line, $bracePos + 1);
                    $headerLines[count($headerLines) - 1] = $headerPart;
                    if (trim($bodyPart) !== '') {
                        $buffer[] = $bodyPart;
                    }
                    $state = 'body';
                }
            } elseif ($state === 'body') {
                $opens  = substr_count($line, '{');
                $closes = substr_count($line, '}');
                $braceDepth += ($opens - $closes);

                if ($braceDepth <= 0) {
                    $closeIndent = '';
                    if (preg_match('/^(\s*)/', $line, $m)) {
                        $closeIndent = $m[1];
                    }
                    $closeBracePos = strrpos($line, '}');
                    $bodyLastPart  = substr($line, strlen($closeIndent), $closeBracePos - strlen($closeIndent));

                    if (trim($bodyLastPart) !== '') {
                        $buffer[] = $bodyLastPart;
                    }

                    $methodBody = implode("\n", $buffer);
                    $mutatedProps = $analyzer->detectArrayMutation($methodBody);

                    foreach ($headerLines as $hl) {
                        $output[] = $hl;
                    }
                    $lastIdx = count($output) - 1;

                    if (!empty($mutatedProps)) {
                        $notifyLines = [];
                        foreach ($mutatedProps as $propName) {
                            $notifyLines[] = $closeIndent . '        \\Px\\Reactive\\Notifier::notify($this, \'' . $propName . '\');';
                        }
                        $injectedNotify = implode("\n", $notifyLines);
                        if ($methodBody !== '') {
                            $output[$lastIdx] .= "\n" . $methodBody . "\n" . $injectedNotify;
                        } else {
                            $output[$lastIdx] .= "\n" . $injectedNotify;
                        }
                    } elseif ($methodBody !== '') {
                        $output[$lastIdx] .= "\n" . $methodBody;
                    }

                    $output[] = $closeIndent . '}';

                    $state    = 'outside';
                    $buffer   = [];
                    $headerLines = [];
                    $methodName = '';
                } else {
                    $buffer[] = $line;
                }
            }
        }

        return implode("\n", $output);
    }

    /**
     * int 属性赋值添加显式 (int) 类型转换。
     */
    public function wrapIntAssignments(string $classBody, array $intProps): string
    {
        foreach ($intProps as $prop) {
            $escapedProp = preg_quote($prop, '/');

            // $this->prop++ -> $this->prop = (int)$this->prop + 1
            $classBody = preg_replace(
                '/\$this->' . $escapedProp . '\s*\+\+\s*;/',
                '$this->' . $prop . ' = (int)$this->' . $prop . ' + 1;',
                $classBody
            );

            // $this->prop-- -> $this->prop = (int)$this->prop - 1
            $classBody = preg_replace(
                '/\$this->' . $escapedProp . '\s*--\s*;/',
                '$this->' . $prop . ' = (int)$this->' . $prop . ' - 1;',
                $classBody
            );

            // $this->prop = expr -> $this->prop = (int)(expr)
            $classBody = preg_replace_callback(
                '/(\$this->' . $escapedProp . ')\s*=\s*([^;]+);/',
                function(array $m) use ($prop): string {
                    $rhs = trim($m[2]);
                    if (str_starts_with($rhs, '(int)')) return $m[0];
                    if (str_starts_with($rhs, '=')) return $m[0];
                    if (preg_match('/^[.\+\-\*\/%&|^]/', $rhs)) return $m[0];
                    return $m[1] . ' = (int)(' . $rhs . ');';
                },
                $classBody
            );
        }
        return $classBody;
    }
}
