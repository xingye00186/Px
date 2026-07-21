<?php

/**
 * DispatchGenerator — 事件分发代码生成器
 *
 * 生成 dispatchClick() / dispatchKey() 方法体。
 * 提取自 sfc-compiler.php 的 generateDispatchClick() / generateDispatchKey()。
 */
class DispatchGenerator
{
    /**
     * 生成 dispatchClick() 方法体。
     *
     * @param array $handlers [handler => ['hasArg'=>bool, 'arg'=>?string]]
     * @return string PHP 代码（方法体内容）
     */
    public function generateClick(array $handlers): string
    {
        if (count($handlers) === 0) {
            return "        // No event handlers defined — bubbling to parent\n        if (\$this->parent !== null) {\n            \$this->parent->dispatchClick(\$handler, \$arg);\n        }";
        }

        $cases = [];
        foreach ($handlers as $handler => $info) {
            if ($info['hasArg'] && $info['arg'] !== null) {
                $cases[] = "            case '{$handler}': \$this->{$handler}(\$arg); break;";
            } else {
                $cases[] = "            case '{$handler}': \$this->{$handler}(); break;";
            }
        }
        $cases[] = "            default:\n                // Bubble to parent component\n                if (\$this->parent !== null) {\n                    \$this->parent->dispatchClick(\$handler, \$arg);\n                }\n                break;";

        $caseStr = implode("\n", $cases);
        return "        switch (\$handler) {\n{$caseStr}\n        }";
    }

    /**
     * 生成 dispatchKey() 方法体。
     *
     * @param array $handlers [handler => true]
     * @return string PHP 代码（方法体内容）
     */
    public function generateKey(array $handlers): string
    {
        if (count($handlers) === 0) {
            return "        // No keyboard handlers defined — bubbling to parent\n        if (\$this->parent !== null) {\n            \$this->parent->dispatchKey(\$handler, \$action, \$keyCode, \$char);\n        }";
        }

        $cases = [];
        foreach ($handlers as $handler => $_) {
            $cases[] = "            case '{$handler}': \$this->{$handler}(\$action, \$keyCode, \$char); break;";
        }
        $cases[] = "            default:\n                // Bubble to parent component\n                if (\$this->parent !== null) {\n                    \$this->parent->dispatchKey(\$handler, \$action, \$keyCode, \$char);\n                }\n                break;";

        $caseStr = implode("\n", $cases);
        return "        switch (\$handler) {\n{$caseStr}\n        }";
    }
}
