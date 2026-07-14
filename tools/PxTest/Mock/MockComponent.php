<?php

namespace PxTest\Mock;

use Px\Component\ReactiveComponent;
use Px\Dom\VNode;
use Px\Core\Application;
use Px\Core\Scheduler;

/**
 * 可配置行为的测试用组件。
 *
 * 允许在测试中精确控制 render() 返回值，以及观察 markDirty/事件调用。
 */
class MockComponent extends ReactiveComponent
{
    /** 预设的 render() 返回值 */
    public VNode $mockVNode;

    /** @var array<string, int> 方法调用计数 */
    public array $callCount = [
        'render'     => 0,
        'markDirty'  => 0,
        'onMount'    => 0,
        'onUnmount'  => 0,
    ];

    /** @var array<int, array{event: string, payload: mixed}> emit 调用记录 */
    public array $emitCalls = [];

    /** @var array<int, array{child: string, event: string}> on 调用记录 */
    public array $onCalls = [];

    public function __construct(
        string $groupId = 'mock',
        ?Application $app = null,
        ?Scheduler $scheduler = null,
    ) {
        parent::__construct($groupId);
        $this->mockVNode = VNode::h('div', [], 'mock');

        $sched = $scheduler ?? new Scheduler();
        $this->setScheduler($sched);

        if ($app !== null) {
            $this->setRenderCallback(function () use ($app) {
                $rm = new \ReflectionMethod(Application::class, 'handleRenderRequest');
                $rm->invoke($app);
            });
        }
    }

    public function render(): VNode
    {
        $this->callCount['render']++;
        // 框架要求根组件返回 #root 以触发 rootRenderNode 设置
        return VNode::h('#root', [], $this->mockVNode);
    }

    public function markDirty(): void
    {
        $this->callCount['markDirty']++;
        parent::markDirty();
    }

    public function onMount(): void
    {
        $this->callCount['onMount']++;
    }

    public function on(ReactiveComponent $child, string $eventName, callable $callback): void
    {
        $this->onCalls[] = ['child' => get_class($child), 'event' => $eventName];
        parent::on($child, $eventName, $callback);
    }

    public function setBindValue(string $key, string $val): void {}

    public function getBindValue(string $key): string
    {
        return '';
    }

    public function emit(string $event, mixed $payload = null): void
    {
        $this->emitCalls[] = ['event' => $event, 'payload' => $payload];
        parent::emit($event, $payload);
    }

    /** 验证特定方法被调用了预期次数 */
    public function assertCalled(string $method, int $expectedCount): bool
    {
        return ($this->callCount[$method] ?? 0) === $expectedCount;
    }

    /** 验证 emit 被调用 */
    public function assertEmitted(string $event, ?callable $payloadCheck = null): bool
    {
        foreach ($this->emitCalls as $call) {
            if ($call['event'] === $event) {
                if ($payloadCheck === null || $payloadCheck($call['payload'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
