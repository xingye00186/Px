<?php

/**
 * Script Analyzer for SFC Compiler v10 (Reactive Mode)
 *
 * Analyzes PHP script blocks extracted from .vue <script> sections.
 * 不再自动注入 $this->markDirty() —— 响应式更新由 PHP 8.4 Property Hooks
 * + DependencyTracker 自动管理。
 *
 * 核心职责切换到:
 *   1. extractReactiveProperties() — 提取 #[Reactive] 标记的属性
 *   2. detectArrayMutation() — 检测方法体中 $this->xxx[] = 变异
 *   3. extractClassDeclaration() — 提取类声明信息 (复用)
 *   4. injectDirty() — 简化为仅提取类体, 不做 dirty 注入
 *
 * 移除:
 *   - injectDirtyIntoMethods()
 *   - processMethodBody()
 *   - methodModifiesProperties()
 *   - detectBodyIndent()
 *   - removeExistingDirty()
 *
 * 保留:
 *   - extractClassDeclaration()
 *   - injectDirty() 作为类体提取器 (pass-through)
 */

class ScriptAnalyzer
{
    /** @var array{name:string, type:string, default:string}[] 提取的响应式属性列表 */
    private array $reactiveProps = [];

    /** @var string[] 所有属性名 (含非 #[Reactive]) */
    private array $propertyNames = [];

    /**
     * 提取 #[Reactive] 标记的响应式属性。
     *
     * 匹配模式:
     *   #[Reactive]
     *   public int $count = 0;
     *
     * @param string $script 原始 <script> 内容
     * @return array{name:string, type:string, default:string}[]
     */
    public function extractReactiveProperties(string $script): array
    {
        $props = [];
        // 匹配 #[Reactive] 紧接 public (type) $name [= default];
        // 支持:
        //   #[Reactive]
        //   public int $count = 0;
        //   #[Reactive]
        //   public string $name = '';
        //   #[Reactive]
        //   public array $items = [];
        if (preg_match_all(
            '/#\[Reactive\]\s*\n\s*public\s+(string|int|bool|float|array)\s+\$(\w+)\s*(?:=\s*([^;]+))?\s*;/',
            $script,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $type = $m[1];
                $name = $m[2];
                $default = isset($m[3]) ? trim($m[3]) : $this->getDefaultForType($type);
                $props[] = [
                    'name'    => $name,
                    'type'    => $type,
                    'default' => $default,
                ];
            }
        }
        $this->reactiveProps = $props;
        return $props;
    }

    /**
     * 获取已提取的响应式属性列表
     * @return array{name:string, type:string, default:string}[]
     */
    public function getReactiveProps(): array
    {
        return $this->reactiveProps;
    }

    /**
     * 提取类体 (保留原 injectDirty 接口签名)
     *
     * 在依赖追踪模式下, 不再注入任何 dirty 标记。
     * 所有更新触发由 Property Hook 的 set → Notifier::notify()
     * → Effect::schedule() → performUpdate() 自动处理。
     *
     * @param string $script 原始 <script> 内容 (可能包含 class 声明)
     * @return string ONLY the class body, without class declaration
     */
    public function injectDirty(string $script): string
    {
        // v6 M4: 提取类体
        $script = trim($script);
        if (preg_match('/^class\s+\w+\s+extends\s+\w+\s*\{(.*)\}\s*$/s', $script, $m)) {
            $classBody = $m[1];
        } else {
            $classBody = $script;
        }

        // 仍然提取属性名 (供 wrapIntAssignments 等后续 AOT 处理使用)
        $this->propertyNames = $this->extractPropertyNames($classBody);

        // 依赖追踪模式: 不注入 dirty, 直接返回
        return $classBody;
    }

    /**
     * 检测方法体中的数组变异操作
     *
     * PHP 8.4 Property Hooks 的 set 钩子在 $this->arr[] = $val
     * 时不会被调用。此方法检测到这类模式, 供编译器在方法体
     * 末尾追加 $this->arr = $this->arr 以显式触发 set 钩子。
     *
     * @param string $methodBody 方法体文本
     * @return string[] 需要追加触发语句的数组属性名
     */
    public function detectArrayMutation(string $methodBody): array
    {
        $mutated = [];
        foreach ($this->reactiveProps as $prop) {
            if ($prop['type'] !== 'array') {
                continue;
            }
            $name = $prop['name'];
            // 匹配 $this->name[] = ...  (push 模式)
            if (preg_match('/\$this->' . preg_quote($name, '/') . '\s*\[\]\s*=/', $methodBody)) {
                $mutated[$name] = true;
            }
            // 匹配 $this->name[expr] = ...  (键赋值模式)
            if (preg_match('/\$this->' . preg_quote($name, '/') . '\s*\[.*?\]\s*=/s', $methodBody)) {
                $mutated[$name] = true;
            }
        }
        return array_keys($mutated);
    }

    /**
     * 提取类声明信息 (供 SFC 编译器使用)
     *
     * @return array{className:string, extends:string}|null
     */
    public function extractClassDeclaration(string $script): ?array
    {
        $script = trim($script);
        if (preg_match('/^class\s+(\w+)\s+extends\s+(\w+)/', $script, $m)) {
            return ['className' => $m[1], 'extends' => $m[2]];
        }
        return null;
    }

    // ─── 内部辅助 ─────────────────────────────────

    /**
     * 获取类型对应的默认值表达式
     */
    private function getDefaultForType(string $type): string
    {
        return match ($type) {
            'int'    => '0',
            'float'  => '0.0',
            'bool'   => 'false',
            'string' => "''",
            'array'  => '[]',
            default  => 'null',
        };
    }

    /**
     * 从类体中提取所有属性名 (供 wrapIntAssignments 引用)
     */
    private function extractPropertyNames(string $classBody): array
    {
        $props = [];
        // 匹配 typed property 声明 (不含 #[Reactive] 前缀)
        if (preg_match_all(
            '/public\s+(?:string|bool|int|float|array)\s+\$(\w+)\s*[=;]/',
            $classBody,
            $matches
        )) {
            $props = $matches[1];
        }
        return $props;
    }
}
