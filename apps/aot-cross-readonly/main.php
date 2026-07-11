<?php

use native_types;

// ── 被测 DTO ──
class Frag
{
    public readonly int $x;
    public readonly int $y;
    public readonly int $w;
    public readonly int $h;
    public function __construct(int $x, int $y, int $w, int $h) { $this->x=$x; $this->y=$y; $this->w=$w; $this->h=$h; }
    public function getX(): int { return $this->x; }
    public function getY(): int { return $this->y; }
    public function getW(): int { return $this->w; }
    public function getH(): int { return $this->h; }
    public function toArray(): array { return ['x'=>$this->x, 'y'=>$this->y, 'w'=>$this->w, 'h'=>$this->h]; }
}

// ── 跨类读取器 ──
class Reader
{
    public static function direct(Frag $f): array {
        return ['x'=>$f->x, 'y'=>$f->y, 'w'=>$f->w, 'h'=>$f->h];
    }
    public static function getter(Frag $f): array {
        return ['x'=>$f->getX(), 'y'=>$f->getY(), 'w'=>$f->getW(), 'h'=>$f->getH()];
    }
}

function main(): void
{
    $f = new Frag(10, 20, 100, 200);

    $d = Reader::direct($f);
    echo "direct: x={$d['x']} y={$d['y']} w={$d['w']} h={$d['h']}\n";

    $g = Reader::getter($f);
    echo "getter: x={$g['x']} y={$g['y']} w={$g['w']} h={$g['h']}\n";

    $t = $f->toArray();
    echo "toArr:  x={$t['x']} y={$t['y']} w={$t['w']} h={$t['h']}\n";

    if (($d['x'] === '' || $d['x'] == 0) && $g['x'] === 10 && $t['x'] === 10) {
        echo "\n判定: 跨类 readonly 直接读取返回 0 ❌  getter 正常 ✅\n";
    } elseif ($d['x'] === 10 && $g['x'] === 10 && $t['x'] === 10) {
        echo "\n判定: 所有方式正常 ✅\n";
    } else {
        echo "\n判定: 部分异常\n";
    }
}
