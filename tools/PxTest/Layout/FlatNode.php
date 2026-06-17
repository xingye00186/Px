<?php

namespace PxTest\Layout;

/**
 * 展平节点值对象 — TreeFlattener 的产出数据。
 */
class FlatNode
{
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly int    $x,
        public readonly int    $y,
        public readonly int    $w,
        public readonly int    $h,
        public readonly int    $visualW = 0,
        public readonly int    $visualH = 0,
        public readonly int    $layer = 0,
        public readonly bool   $isScrollContainer = false,
        public readonly ?string $content = null,
        public readonly array  $style = [],
    ) {}

    /** 从数组创建 */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['path'] ?? '',
            $data['type'] ?? '?',
            (int)($data['x'] ?? 0),
            (int)($data['y'] ?? 0),
            (int)($data['w'] ?? 0),
            (int)($data['h'] ?? 0),
            (int)($data['visualW'] ?? 0),
            (int)($data['visualH'] ?? 0),
            (int)($data['layer'] ?? 0),
            (bool)($data['isScrollContainer'] ?? false),
            $data['content'] ?? null,
            $data['style'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'x' => $this->x, 'y' => $this->y,
            'w' => $this->w, 'h' => $this->h,
            'visualW' => $this->visualW, 'visualH' => $this->visualH,
            'layer' => $this->layer,
            'isScrollContainer' => $this->isScrollContainer,
            'content' => $this->content,
            'style' => $this->style,
        ];
    }
}
