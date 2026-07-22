<?php

/**
 * BindValueGenerator — bind 值读写代码生成器
 *
 * 生成 setBindValue() / getBindValue() 方法体。
 * 提取自 sfc-compiler.php 的 generateSetBindValue() / generateGetBindValue()。
 */
class BindValueGenerator
{
    /**
     * 生成 setBindValue() 方法体。
     *
     * @param array $bindKeys [bindKey => true]
     * @param array $arrayBindKeys [bindKey => true] 数组类型的 bind key
     * @param array $reactiveProps [{name, type, default}, ...]
     * @return string PHP 代码
     */
    public function generateSet(array $bindKeys, array $arrayBindKeys = [], array $reactiveProps = []): string
    {
        // Build reactive type lookup + reactive names
        $reactiveTypes = [];
        $reactiveNames = [];
        foreach ($reactiveProps as $rp) {
            $reactiveTypes[$rp['name']] = $rp['type'];
            $reactiveNames[$rp['name']] = true;
        }

        // 合并：模板扫描出的 bindKeys + 所有 Reactive prop 名
        //   原因：父组件可能传任意 Reactive prop（即使模板内未插值引用），
        //   setBindValue 需能路由到对应字段。例如递归组件中：
        //     <deep-tree-node :depth="depth - 1"> 传递 depth，但自身模板只
        //     在 v-if="depth > 0" 条件里用 depth，不会被 collectVNodeBindKeys 扫到。
        //   无 Reactive props 时降级为旧行为。
        $binds = array_keys(array_merge($bindKeys, $reactiveNames));
        if (count($binds) === 0) {
            return "        // No bind keys defined";
        }

        $cases = [];
        foreach ($binds as $key) {
            if ($key === '') continue;
            if (is_numeric($key)) continue;
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) continue;
            if (isset($arrayBindKeys[$key])) {
                $cases[] = "            case '" . addslashes($key) . "': \$decoded = json_decode(\$value, true); if (is_array(\$decoded) && \$this->{$key} !== \$decoded) { \$this->{$key} = \$decoded; } break;";
            } else {
                $cast = '';
                if (isset($reactiveTypes[$key])) {
                    $t = $reactiveTypes[$key];
                    if ($t === 'int')   { $cast = '(int)'; }
                    elseif ($t === 'bool')  { $cast = '(bool)'; }
                    elseif ($t === 'float') { $cast = '(float)'; }
                }
                $cases[] = "            case '" . addslashes($key) . "': if (\$this->{$key} !== {$cast}\$value) { \$this->{$key} = {$cast}\$value; } break;";
            }
        }
        if (count($cases) === 0) {
            return "        // No bind keys defined";
        }

        $cases[] = "            default: break;";
        $caseStr = implode("\n", $cases);
        return "        switch (\$bindKey) {\n{$caseStr}\n        }";
    }

    /**
     * 生成 getBindValue() 方法体。
     *
     * @param array $bindKeys [bindKey => true]
     * @param array $arrayBindKeys [bindKey => true] 数组类型的 bind key
     * @return string PHP 代码
     */
    public function generateGet(array $bindKeys, array $arrayBindKeys = [], array $reactiveProps = []): string
    {
        // 同样合并 Reactive prop 名（与 generateSet 保持一致）
        $reactiveNames = [];
        foreach ($reactiveProps as $rp) {
            $reactiveNames[$rp['name']] = true;
        }
        $binds = array_keys(array_merge($bindKeys, $reactiveNames));
        if (count($binds) === 0) {
            return "        return '';";
        }

        $cases = [];
        foreach ($binds as $key) {
            if ($key === '') continue;
            if (is_numeric($key)) continue;
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) continue;
            if (isset($arrayBindKeys[$key])) {
                $cases[] = "            case '" . addslashes($key) . "': return json_encode(\$this->{$key});";
            } else {
                $cases[] = "            case '" . addslashes($key) . "': return \$this->{$key};";
            }
        }
        if (count($cases) === 0) {
            return "        return '';";
        }

        $cases[] = "            default: return '';";
        $caseStr = implode("\n", $cases);
        return "        switch (\$bindKey) {\n{$caseStr}\n        }";
    }
}
