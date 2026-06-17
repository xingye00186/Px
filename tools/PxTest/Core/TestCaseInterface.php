<?php

namespace PxTest\Core;

/**
 * 测试用例抽象接口。
 * 所有单元/集成/E2E 测试用例均实现此接口。
 */
interface TestCaseInterface
{
    /** 测试用例名称（用于报告） */
    public function name(): string;

    /** 执行测试，返回结构化结果 */
    public function run(): TestResult;
}
