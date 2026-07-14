<?php

namespace Px$1;

use Px\Core\Scheduler;

interface ComponentInterface
{
    public function getId(): string;
    public function getParent(): ?ComponentInterface;
    public function getProps(): array;
    public function setScheduler(Scheduler $scheduler): void;
    public function onMount(): void;
    public function onUnmount(): void;
}
