<?php

use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\CssLength;
use Px\Rendering\CssKeyword;
use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\BlockAlgorithm;

class SimpleFragment
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

class SimpleSpace
{
    public readonly int $contentWidth;
    public readonly int $contentHeight;
    public function __construct(int $w, int $h) { $this->contentWidth=$w; $this->contentHeight=$h; }
    public function getContentWidth(): int { return $this->contentWidth; }
    public function getContentHeight(): int { return $this->contentHeight; }
}

class Reader
{
    public static function readFragDirect(SimpleFragment $f): array {
        return ['x'=>$f->x, 'y'=>$f->y, 'w'=>$f->w, 'h'=>$f->h];
    }
    public static function readFragGetter(SimpleFragment $f): array {
        return ['x'=>$f->getX(), 'y'=>$f->getY(), 'w'=>$f->getW(), 'h'=>$f->getH()];
    }
    public static function readSpaceDirect(SimpleSpace $s): array {
        return ['w'=>$s->contentWidth, 'h'=>$s->contentHeight];
    }
    public static function readSpaceGetter(SimpleSpace $s): array {
        return ['w'=>$s->getContentWidth(), 'h'=>$s->getContentHeight()];
    }
    public static function readCSDirect(ComputedStyle $cs): array {
        return ['fontSize'=>$cs->fontSize, 'lineHeight'=>$cs->lineHeight, 'bold'=>$cs->bold ? 1 : 0, 'btw'=>$cs->borderTopWidth];
    }
    public static function readCSGetter(ComputedStyle $cs): array {
        return ['fontSize'=>$cs->getFontSize(), 'lineHeight'=>$cs->getLineHeight(), 'bold'=>$cs->getBold() ? 1 : 0, 'btw'=>$cs->getBorderTopWidth()];
    }
    public static function algoLayout(): array {
        $algo = new BlockAlgorithm();
        $space = new ConstraintSpace(1600, 800, 0, 0, 1500, 700, 1500, 700, 0, 0, 0, 0, 0, 0, 0, 0, false, false, 0, 0, 'block');
        $cs = new ComputedStyle(['fontSize' => new CssLength(16.0)]);
        $f = $algo->layout($space, $cs, 'Hello');
        return ['fx'=>$f->getX(), 'fy'=>$f->getY(), 'fw'=>$f->getW(), 'fh'=>$f->getH()];
    }
}

function main(): void
{
    $f = new SimpleFragment(10, 20, 100, 200);
    echo "Frag direct:  x=" . Reader::readFragDirect($f)['x'] . " y=" . Reader::readFragDirect($f)['y'] .
         " w=" . Reader::readFragDirect($f)['w'] . " h=" . Reader::readFragDirect($f)['h'] . "\n";
    echo "Frag getter:  x=" . Reader::readFragGetter($f)['x'] . " y=" . Reader::readFragGetter($f)['y'] .
         " w=" . Reader::readFragGetter($f)['w'] . " h=" . Reader::readFragGetter($f)['h'] . "\n";
    echo "Frag toArray: x=" . $f->toArray()['x'] . " y=" . $f->toArray()['y'] .
         " w=" . $f->toArray()['w'] . " h=" . $f->toArray()['h'] . "\n";

    $s = new SimpleSpace(1600, 800);
    echo "Space direct: w=" . Reader::readSpaceDirect($s)['w'] . " h=" . Reader::readSpaceDirect($s)['h'] . "\n";
    echo "Space getter: w=" . Reader::readSpaceGetter($s)['w'] . " h=" . Reader::readSpaceGetter($s)['h'] . "\n";

    $cs = new ComputedStyle([]);
    echo "CS direct:  fs=" . Reader::readCSDirect($cs)['fontSize'] .
         " lh=" . Reader::readCSDirect($cs)['lineHeight'] .
         " b=" . Reader::readCSDirect($cs)['bold'] .
         " btw=" . Reader::readCSDirect($cs)['btw'] . "\n";
    echo "CS getter:  fs=" . Reader::readCSGetter($cs)['fontSize'] .
         " lh=" . Reader::readCSGetter($cs)['lineHeight'] .
         " b=" . Reader::readCSGetter($cs)['bold'] .
         " btw=" . Reader::readCSGetter($cs)['btw'] . "\n";
}
