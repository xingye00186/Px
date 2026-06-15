<?php
/**
 * 运行时类定义文件 — 通过 include/require 动态加载
 *
 * 测试 AOT 编译后的二进制能否加载运行时文件中定义的类，
 * 以及编译代码能否实例化、调用这些类的方法。
 *
 * 测试场景:
 *   - 运行时类实例化 + 构造方法
 *   - 运行时类实例方法调用
 *   - 运行时类静态方法调用
 *   - 运行时类调用 AOT 编译函数（桥接模式）
 *   - 运行时类的继承
 *   - 运行时类静态属性跨实例追踪
 */

/**
 * 基础运行时类：构造方法 + 实例方法 + 静态方法
 */
class RuntimeGreeter
{
    public string $name;
    public static int $instanceCount = 0;

    public function __construct(string $name = 'World')
    {
        $this->name = $name;
        self::$instanceCount++;
    }

    public function greet(): string
    {
        return 'Hello, ' . $this->name . '!';
    }

    public static function staticHello(): string
    {
        return 'Static hello from runtime class!';
    }

    public static function getInstanceCount(): int
    {
        return self::$instanceCount;
    }
}

/**
 * 桥接运行时类：方法内部调用 AOT 编译的函数
 */
class RuntimeCompiledBridge
{
    public function callCompiledMultiply(int $a, int $b): int
    {
        // 调用 main.php 中 AOT 编译的 compiledMultiply()
        return compiledMultiply($a, $b);
    }

    public static function staticCallCompiled(int $a, int $b): int
    {
        return compiledMultiply($a, $b);
    }
}

/**
 * 运行时类 — 调用编译类的 static / instance 方法
 */
class RuntimeCompiledClassBridge
{
    /**
     * 实例方法：调用编译类静态方法
     */
    public function callCompiledClassStatic(int $a, int $b): int
    {
        return CompiledCalculator::staticMultiply($a, $b);
    }

    /**
     * 实例方法：实例化编译类并调用其实例方法
     */
    public function callCompiledClassInstance(int $a, int $b): int
    {
        $calc = new CompiledCalculator();
        return $calc->instanceSubtract($a, $b);
    }

    /**
     * 实例方法：调用编译类的静态加法
     */
    public function callCompiledClassAdd(int $a, int $b): int
    {
        return CompiledCalculator::staticAdd($a, $b);
    }

    /**
     * 静态方法：调用编译类的静态方法
     */
    public static function staticCallCompiledClass(int $a, int $b): int
    {
        return CompiledCalculator::staticMultiply($a, $b);
    }

    /**
     * 静态方法：观察编译类的静态属性
     */
    public static function readCompiledTotalCalls(): int
    {
        return CompiledCalculator::getTotalCalls();
    }
}

/**
 * 继承类：继承自 RuntimeGreeter（全运行时继承链）
 */
class SpecificGreeter extends RuntimeGreeter
{
    public function greet(): string
    {
        return 'Hi there, ' . $this->name . '!';
    }

    public function parentGreet(): string
    {
        // 调用父类方法
        return parent::greet();
    }
}
