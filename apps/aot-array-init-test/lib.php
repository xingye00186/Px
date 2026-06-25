<?php
use native_types;

class LikeScheduler {
    private array $items = [];
    public function __construct() {
        $this->items = [];
    }
    public function countItems(): int {
        return count($this->items);
    }
    public function hasItems(): bool {
        return !empty($this->items);
    }
}
