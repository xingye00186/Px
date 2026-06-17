<?php

/**
 * PxTest 断言工具 — 全局辅助函数。
 *
 * 提供 assertRenderNodeTreeEquals、assertVNodeEquals 等专用断言。
 * 由 bootstrap.php 自动 require。在 PHPUnit 环境中扩展 TestCase。
 */

use Px\Rendering\RenderNode;
use Px\Rendering\VNode;

if (!function_exists('assert_render_node_equals')) {
    /**
     * 断言两个 RenderNode 的关键字段相等。
     * 自动跳过动态指针字段（parent, sourceVNode, positioningAncestor, animatedStyle）。
     */
    function assert_render_node_equals(RenderNode $expected, RenderNode $actual, string $message = ''): void
    {
        $fields = ['type', 'x', 'y', 'w', 'h', 'visualW', 'visualH', 'layer',
                   'isScrollContainer', 'key', 'groupId', 'layoutDirty'];

        $errors = [];
        foreach ($fields as $f) {
            $exp = $expected->$f;
            $act = $actual->$f;
            if ($exp !== $act) {
                $errors[] = "  $f: expected=" . var_export($exp, true)
                           . " actual=" . var_export($act, true);
            }
        }

        // 对比 style 关键字段
        $styleKeys = ['bg', 'fg', 'fontSize', 'bold', 'display', 'flexDirection',
                      'justifyContent', 'alignItems', 'boxSizing'];
        foreach ($styleKeys as $sk) {
            $exp = $expected->style[$sk] ?? null;
            $act = $actual->style[$sk] ?? null;
            if ($exp !== $act) {
                $errors[] = "  style.$sk: expected=" . var_export($exp, true)
                           . " actual=" . var_export($act, true);
            }
        }

        if (!empty($errors)) {
            $msg = $message ?: 'RenderNode mismatch';
            $msg .= "\n" . implode("\n", $errors);
            if (class_exists('PHPUnit\Framework\Assert')) {
                \PHPUnit\Framework\Assert::fail($msg);
            } else {
                throw new \RuntimeException($msg);
            }
        }
    }
}

if (!function_exists('assert_vnode_equals')) {
    /**
     * 断言两个 VNode 的类型/属性/文本内容相等。
     */
    function assert_vnode_equals(VNode $expected, VNode $actual, string $message = ''): void
    {
        $errors = [];

        if ($expected->type !== $actual->type) {
            $errors[] = "type: expected=" . $expected->type . " actual=" . $actual->type;
        }

        // 对比关键 props
        $keys = ['style', 'class', '@click'];
        foreach ($keys as $k) {
            $exp = $expected->getProp($k, null);
            $act = $actual->getProp($k, null);
            if ($exp !== $act) {
                $errors[] = "prop[$k]: expected=" . var_export($exp, true)
                           . " actual=" . var_export($act, true);
            }
        }

        // 对比文本内容
        $expText = is_string($expected->children) ? $expected->children : null;
        $actText = is_string($actual->children) ? $actual->children : null;
        if ($expText !== $actText) {
            $errors[] = "text: expected=" . var_export($expText, true)
                       . " actual=" . var_export($actText, true);
        }

        if (!empty($errors)) {
            $msg = $message ?: 'VNode mismatch';
            $msg .= "\n" . implode("\n", $errors);
            if (class_exists('PHPUnit\Framework\Assert')) {
                \PHPUnit\Framework\Assert::fail($msg);
            } else {
                throw new \RuntimeException($msg);
            }
        }
    }
}

if (!function_exists('assert_event_fired')) {
    /**
     * 断言组件的 emit 方法被调用，且事件名和 payload 匹配。
     *
     * @param object $component  MockComponent 实例
     * @param string $eventName  期望的事件名
     * @param mixed  $payload    期望的 payload（null=不检查）
     */
    function assert_event_fired(object $component, string $eventName, mixed $payload = null, string $message = ''): void
    {
        // MockComponent 的 assertEmitted
        if (method_exists($component, 'assertEmitted')) {
            $passed = $component->assertEmitted($eventName, $payload !== null
                ? fn($p) => $p === $payload
                : null);
        } else {
            // 通用：检查 emitCalls 数组
            $calls = $component->emitCalls ?? [];
            $passed = false;
            foreach ($calls as $call) {
                if (($call['event'] ?? '') === $eventName) {
                    if ($payload === null || ($call['payload'] ?? null) === $payload) {
                        $passed = true;
                        break;
                    }
                }
            }
        }

        if (!$passed) {
            $msg = $message ?: "Event '$eventName' was not fired";
            if (class_exists('PHPUnit\Framework\Assert')) {
                \PHPUnit\Framework\Assert::fail($msg);
            } else {
                throw new \RuntimeException($msg);
            }
        }
    }
}
