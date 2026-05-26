<?php

namespace Px\Interfaces;

use Px\Core\Scheduler;

interface ComponentInterface
{
    public function getId(): string;
    public function getParent(): ?ComponentInterface;
    public function getChildren(): array;
    public function getProps(): array;
    public function setScheduler(Scheduler $scheduler): void;
    public function onMount(): void;
    public function onUnmount(): void;
}