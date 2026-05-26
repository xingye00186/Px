<?php

namespace Px;

use Px\Interfaces\ComponentInterface;
use Px\Core\Scheduler;

abstract class BaseComponent implements ComponentInterface
{
    protected string $id = '';
    protected ?ComponentInterface $parent = null;
    protected array $children = [];
    protected array $props = [];
    protected ?Scheduler $scheduler = null;

    public function __construct(string $id = '')
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getParent(): ?ComponentInterface
    {
        return $this->parent;
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function getProps(): array
    {
        return $this->props;
    }

    public function setParent(ComponentInterface $parent): void
    {
        $this->parent = $parent;
    }

    public function setProps(array $props): void
    {
        $this->props = $props;
    }

    public function setScheduler(Scheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }

    public function addChild(ComponentInterface $child, array $props = []): void
    {
        $childId = $child->getId();
        $this->children[$childId] = $child;
        $child->setParent($this);
        if (count($props) > 0) {
            $child->setProps($props);
        }
    }

    public function removeChild(string $childId): void
    {
        if (isset($this->children[$childId])) {
            $child = objval($this->children[$childId], ComponentInterface::class);
            $child->onUnmount();
            unset($this->children[$childId]);
        }
    }

    abstract public function onMount(): void;
    abstract public function onUnmount(): void;

    /**
     * 默认点击事件分发（沿 parent 链冒泡）。
     * 子组件可 override 此方法来处理自身事件，未匹配的 handler 通过 default 分支调用 parent::dispatchClick()。
     */
    public function dispatchClick(string $handler, ?string $arg = null): void
    {
        if ($this->parent !== null) {
            $this->parent->dispatchClick($handler, $arg);
        }
    }

    /**
     * 默认键盘事件分发（沿 parent 链冒泡）。
     */
    public function dispatchKey(string $handler, string $action, int $keyCode, string $char): void
    {
        if ($this->parent !== null) {
            $this->parent->dispatchKey($handler, $action, $keyCode, $char);
        }
    }
}
