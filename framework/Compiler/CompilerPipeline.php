<?php

use Px\Dom\VNode;

/**
 * CompilerPipeline — 编译器管线编排器
 *
 * 编排完整的 .vue → PHP 类编译流程：
 *   1. Block extraction
 *   2. Style parsing + class merge
 *   3. Template parsing → VNode tree
 *   4. Component resolution
 *   5. Transform pipeline (StaticHoist, StyleArray, PatchFlag)
 *   6. Data collection (handlers, bind keys, v-for loops)
 *   7. Code generation (render, dispatch, bind, v-for, reactive)
 *   8. Class assembly → validation → write
 *
 * 当前版本与 compileOneComponent() 协同工作。
 * transforms 已注册但不影响现有 codegen，确保渐进式迁移安全。
 */
class CompilerPipeline
{
    /** @var TransformPipeline Transform 管线 */
    private TransformPipeline $transformPipeline;

    /** @var array 共享元数据（transforms 产出） */
    private array $metadata = [];

    public function __construct()
    {
        $this->transformPipeline = new TransformPipeline();
    }

    // ============================================================
    // Transform 管线管理
    // ============================================================

    /**
     * 注册一个 Transform 到管线。
     */
    public function addTransform(TransformInterface $transform): void
    {
        $this->transformPipeline->add($transform);
    }

    /**
     * 注册内置的 Transform 集。
     * 当编译器的 codegen 逐步改为读取 annotations 后，
     * 这些 transforms 将逐步替代内联计算逻辑。
     */
    public function registerDefaultTransforms(): void
    {
        // StyleTransform 是样式处理的唯一入口（强制关卡）：
        // 解析 + 简写展开 + key 归一化，一次遍历完成
        $this->addTransform(new StyleTransform());
        $this->addTransform(new StaticHoistTransform());
        $this->addTransform(new PatchFlagTransform());
    }

    // ============================================================
    // 核心管线执行
    // ============================================================

    /**
     * 对已解析的 VNode 树执行全部变换趟。
     *
     * @param VNode $root 已解析的 VNode 树
     * @return array 变换产生的元数据
     */
    public function runTransforms(VNode $root): array
    {
        $this->metadata = $this->transformPipeline->run($root);
        return $this->metadata;
    }

    /**
     * 获取变换元数据。
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 从元数据中提取静态 VNode 声明代码。
     */
    public function getStaticNodeDeclarations(): string
    {
        $decls = $this->metadata['staticNodeDeclarations'] ?? [];
        return !empty($decls) ? implode("\n", $decls) . "\n" : '';
    }

    /**
     * 从元数据中提取静态 VNode 初始化代码前缀。
     */
    public function getStaticNodeInitPrefix(): string
    {
        $initCode = $this->metadata['staticNodeInitCode'] ?? [];
        return !empty($initCode) ? implode("\n        ", $initCode) . "\n        " : '';
    }

    /**
     * 获取已注册的 transform 数量。
     */
    public function getTransformCount(): int
    {
        return $this->transformPipeline->count();
    }
}
