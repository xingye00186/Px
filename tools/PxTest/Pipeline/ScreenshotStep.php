<?php

namespace PxTest\Pipeline;

use PxTest\Infrastructure\BrowserLauncher;

/**
 * Screenshot + anchor-aligned pixel comparison step.
 *
 * Alignment: three-tier fallback
 *   1. detectColorAnchors()   — #FF00FF(TL) + #00FFFF(BR) color blocks
 *   2. The 8x8 anchors sit at card padding-box corners in both screenshots
 *   3. Crop to anchor-bounded rectangle → zero-chrome pure content diff
 */
class ScreenshotStep implements PipelineStepInterface
{
    private string $exePath;
    private string $htmlPath;
    private string $refDir;
    private string $caseName;
    private float $threshold;
    private BrowserLauncher $browser;

    /** Anchor color constants */
    private const TL_COLOR = 0xFF00FF;  // #FF00FF — magenta, top-left anchor
    private const BR_COLOR = 0x00FFFF;  // #00FFFF — cyan, bottom-right anchor
    private const ANCHOR_TOLERANCE = 5;  // per-channel tolerance for anchor color match (tight)
    private const ANCHOR_SIZE     = 8;  // anchor block size (8x8)
    private const DIFF_TOLERANCE  = 25; // per-pixel RGB diff threshold

    public function __construct(
        string $exePath,
        string $htmlPath,
        string $refDir,
        string $caseName,
        float $threshold = 5.0,
        ?BrowserLauncher $browser = null,
    ) {
        $this->exePath   = $exePath;
        $this->htmlPath  = $htmlPath;
        $this->refDir    = $refDir;
        $this->caseName  = $caseName;
        $this->threshold = $threshold;
        $this->browser   = $browser ?? new BrowserLauncher();
    }

    public function name(): string { return 'screenshot_compare'; }
    public function requires(): array { return ['build']; }

    public function execute(PipelineContext $ctx): StepResult
    {
        $start = microtime(true);
        @mkdir($this->refDir, 0777, true);

        // I-1: Exe headless screenshot
        $engineFile = $this->refDir . '/engine_screenshot.png';
        if (file_exists($this->exePath)) {
            $cmd = sprintf(
                '"%s" --case=%s --headless --screenshot=%s 2>&1',
                $this->exePath, $this->caseName, $engineFile
            );
            exec($cmd, $output, $exitCode);
            if (!file_exists($engineFile)) {
                return StepResult::err('screenshot_compare', 'Exe screenshot failed');
            }
            echo "  [exe screenshot] " . filesize($engineFile) . " bytes\n";
        } else {
            return StepResult::err('screenshot_compare', 'Exe not found: ' . $this->exePath);
        }

        // I-2: Browser screenshot
        $browserFile = $this->refDir . '/browser_ref.png';
        if (!$this->browser->isAvailable()) {
            return StepResult::err('screenshot_compare', 'Edge not available');
        }
        if (!$this->browser->screenshot($this->htmlPath, $browserFile, 1600, 800)) {
            return StepResult::err('screenshot_compare', 'Browser screenshot failed');
        }
        echo "  [browser screenshot] " . filesize($browserFile) . " bytes\n";

        // I-3: Anchor-aligned pixel diff
        $diffPct = $this->comparePixels($engineFile, $browserFile);
        echo "  [pixel diff] {$diffPct}%\n";

        $elapsed = (microtime(true) - $start) * 1000;
        $ctx->set('pixel_diff_pct', $diffPct);
        $ctx->set('engine_screenshot', $engineFile);
        $ctx->set('browser_screenshot', $browserFile);

        if ($diffPct <= $this->threshold) {
            return StepResult::ok('screenshot_compare', $elapsed);
        }
        return StepResult::err('screenshot_compare',
            "Pixel diff {$diffPct}% exceeds threshold {$this->threshold}%", $elapsed);
    }

    // ========================
    //  Anchor Alignment Engine
    // ========================

    /**
     * Pixel comparison with anchor-based alignment.
     *
     * Strategy:
     *   1. detectColorAnchors() on BOTH images independently
     *   2. Compute per-image crop rect: TL anchor → BR anchor
     *   3. Crop both to their content rectangles
     *   4. Pixel diff on cropped regions
     *   5. Fallback: if anchors not found, auto-detect content bounds
     */
    private function comparePixels(string $fileA, string $fileB): float
    {
        if (!extension_loaded('gd')) {
            return $this->comparePixelsFallback($fileA, $fileB);
        }

        $imgA = @imagecreatefrompng($fileA);
        $imgB = @imagecreatefrompng($fileB);
        if (!$imgA || !$imgB) return 100.0;

        $wA = imagesx($imgA); $hA = imagesy($imgA);
        $wB = imagesx($imgB); $hB = imagesy($imgB);

        // Tier 1: Color anchor detection
        $anchorsA = $this->detectColorAnchors($imgA, $wA, $hA);
        $anchorsB = $this->detectColorAnchors($imgB, $wB, $hB);

        if ($anchorsA && $anchorsB) {
            echo "  [align] color anchors: TL=({$anchorsA['tl_x']},{$anchorsA['tl_y']}) BR=({$anchorsA['br_x']},{$anchorsA['br_y']})\n";
            $cropA = $this->cropImage($imgA, $anchorsA);
            $cropB = $this->cropImage($imgB, $anchorsB);
            $frac = $this->pixelDiff($cropA, $cropB, "anchor");
            imagedestroy($cropA); imagedestroy($cropB);
            imagedestroy($imgA); imagedestroy($imgB);
            return $frac;
        }

        // Tier 2-3 fallback: auto content bounds detection
        $bounds = $this->autoDetectContentBounds($imgA, $wA, $hA, $imgB, $wB, $hB);
        if ($bounds) {
            echo "  [align] auto content bounds: x={$bounds['x']} y={$bounds['y']} w={$bounds['w']} h={$bounds['h']}\n";
            $cropA = $this->cropImageDirect($imgA, $bounds['x'], $bounds['y'], $bounds['w'], $bounds['h']);
            $cropB = $this->cropImageDirect($imgB, $bounds['x'], $bounds['y'], $bounds['w'], $bounds['h']);
            $frac = $this->pixelDiff($cropA, $cropB, "auto_bounds");
            imagedestroy($cropA); imagedestroy($cropB);
            imagedestroy($imgA); imagedestroy($imgB);
            return $frac;
        }

        imagedestroy($imgA); imagedestroy($imgB);
        echo "  [align] fallback: no anchors or bounds found\n";
        return 100.0;
    }

    /**
     * Detect TL(#FF00FF) and BR(#00FFFF) color anchors.
     *
     * Anchors are 8x8px pure color blocks at card padding-box corners.
     * Verifies full 8x8 block match (not just single pixel).
     */
    private function detectColorAnchors(\GdImage $img, int $w, int $h): ?array
    {
        $step = max(4, (int)($w / 400));
        $tl = null; $br = null;

        // TL: scan from top-left inward
        for ($y = 0; $y < $h * 0.4 && $tl === null; $y += $step) {
            for ($x = 0; $x < $w * 0.4 && $tl === null; $x += $step) {
                if ($this->isAnchorBlock($img, $x, $y, self::TL_COLOR, $w, $h)) {
                    $tl = ['x' => $x, 'y' => $y];
                }
            }
        }

        // BR: scan from bottom-right inward
        for ($y = $h - 1; $y > $h * 0.6 && $br === null; $y -= $step) {
            for ($x = $w - 1; $x > $w * 0.6 && $br === null; $x -= $step) {
                if ($this->isAnchorBlock($img, $x, $y, self::BR_COLOR, $w, $h)) {
                    $br = ['x' => $x + self::ANCHOR_SIZE, 'y' => $y + self::ANCHOR_SIZE];
                }
            }
        }

        if (!$tl || !$br) return null;
        if ($tl['x'] >= $br['x'] || $tl['y'] >= $br['y']) return null;

        return ['tl_x' => $tl['x'], 'tl_y' => $tl['y'], 'br_x' => $br['x'], 'br_y' => $br['y']];
    }

    /** Verify a full ANCHOR_SIZE x ANCHOR_SIZE block matches the target color. */
    private function isAnchorBlock(\GdImage $img, int $sx, int $sy, int $target, int $maxW, int $maxH): bool
    {
        if ($sx + self::ANCHOR_SIZE > $maxW || $sy + self::ANCHOR_SIZE > $maxH) return false;
        $matchCount = 0;
        $total = self::ANCHOR_SIZE * self::ANCHOR_SIZE;
        for ($y = $sy; $y < $sy + self::ANCHOR_SIZE; $y++) {
            for ($x = $sx; $x < $sx + self::ANCHOR_SIZE; $x++) {
                if ($this->isAnchorColor(imagecolorat($img, $x, $y), $target)) $matchCount++;
            }
        }
        return $matchCount >= $total * 0.85; // 85%+ pixels match
    }

    /** Check if a color matches the anchor target within tolerance. */
    private function isAnchorColor(int $pixel, int $target): bool
    {
        $dR = abs((($pixel >> 16) & 0xFF) - (($target >> 16) & 0xFF));
        $dG = abs((($pixel >> 8) & 0xFF)  - (($target >> 8) & 0xFF));
        $dB = abs(($pixel & 0xFF)         - ($target & 0xFF));
        return $dR <= self::ANCHOR_TOLERANCE
            && $dG <= self::ANCHOR_TOLERANCE
            && $dB <= self::ANCHOR_TOLERANCE;
    }

    /** Crop image to the bounding rect between TL and BR anchors. */
    private function cropImage(\GdImage $img, array $anchors): \GdImage
    {
        $x = $anchors['tl_x'];
        $y = $anchors['tl_y'];
        $w = $anchors['br_x'] - $anchors['tl_x'];
        $h = $anchors['br_y'] - $anchors['tl_y'];
        return $this->cropImageDirect($img, $x, $y, $w, $h);
    }

    private function cropImageDirect(\GdImage $img, int $x, int $y, int $w, int $h): \GdImage
    {
        $cropped = imagecreatetruecolor($w, $h);
        imagecopy($cropped, $img, 0, 0, $x, $y, $w, $h);
        return $cropped;
    }

    /** Pixel-by-pixel diff on aligned images. */
    private function pixelDiff(\GdImage $imgA, \GdImage $imgB, string $mode): float
    {
        $wA = imagesx($imgA); $hA = imagesy($imgA);
        $wB = imagesx($imgB); $hB = imagesy($imgB);

        // If anchor-cropped regions differ slightly, crop to overlap
        $w = min($wA, $wB);
        $h = min($hA, $hB);

        echo "  [diff:$mode] region={$w}x{$h}";

        $diffCount = 0;
        $totalPixels = $w * $h;
        $step = max(1, (int)sqrt($totalPixels / 20000)); // ~20K samples
        $sampled = 0;

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if ($this->colorDiff(imagecolorat($imgA, $x, $y), imagecolorat($imgB, $x, $y)) > self::DIFF_TOLERANCE) {
                    $diffCount++;
                }
                $sampled++;
            }
        }

        $frac = $sampled > 0 ? round(($diffCount / $sampled) * 100, 2) : 0;
        echo " sampled={$sampled} diff={$diffCount} ({$frac}%)\n";
        return $frac;
    }

    /**
     * Auto content bounds detection — find the non-background content area.
     * Uses top-left and bottom-right edge scanning to crop chrome margins.
     */
    private function autoDetectContentBounds(\GdImage $imgA, int $wA, int $hA, \GdImage $imgB, int $wB, int $hB): ?array
    {
        // Find solid background edges by scanning for uniform-color margins
        $left = 0; $top = 0; $right = min($wA, $wB); $bottom = min($hA, $hB);

        // Simple heuristic: crop 5% from edges (window chrome padding)
        $marginX = (int)($right * 0.02);
        $marginY = (int)($bottom * 0.02);

        if ($marginX < 10) $marginX = 10;
        if ($marginY < 10) $marginY = 10;

        return [
            'x' => $marginX,
            'y' => $marginY,
            'w' => $right - 2 * $marginX,
            'h' => $bottom - 2 * $marginY,
        ];
    }

    private function colorDiff(int $c1, int $c2): int
    {
        $dR = abs((($c1 >> 16) & 0xFF) - (($c2 >> 16) & 0xFF));
        $dG = abs((($c1 >> 8) & 0xFF)  - (($c2 >> 8) & 0xFF));
        $dB = abs(($c1 & 0xFF)         - ($c2 & 0xFF));
        return $dR + $dG + $dB;
    }

    private function comparePixelsFallback(string $fileA, string $fileB): float
    {
        if (!file_exists($fileA) || !file_exists($fileB)) return 100.0;
        return md5_file($fileA) === md5_file($fileB) ? 0.0 : 100.0;
    }
}
