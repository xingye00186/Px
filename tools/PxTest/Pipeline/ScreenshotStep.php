<?php

namespace PxTest\Pipeline;

use PxTest\Infrastructure\BrowserLauncher;

/**
 * Screenshot + anchor-aligned pixel comparison step.
 *
 * Alignment: three-tier fallback
 *   1. detectColorAnchors()   鈥?#FF00FF(TL) + #00FFFF(BR) color blocks
 *   2. The 8x8 anchors sit at card padding-box corners in both screenshots
 *   3. Crop to anchor-bounded rectangle 鈫?zero-chrome pure content diff
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
    private const TL_COLOR = 0xFF00FF;  // #FF00FF 鈥?magenta, top-left anchor
    private const BR_COLOR = 0x00FFFF;  // #00FFFF 鈥?cyan, bottom-right anchor
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

    public function execute(CaseContext $ctx): StepResult
    {
        $start = microtime(true);

        // 从上下文读取当前 case 名（全量运行时每个 case 独立设置），覆盖构造时默认值
        $ctxCase = $ctx->get('case_name');
        if ($ctxCase !== null && $ctxCase !== '') {
            $this->caseName = $ctxCase;
            // 根据当前 case 名重新计算 htmlPath 和 refDir
            $caseDir = dirname($this->refDir, 2) . '/' . $ctxCase;
            $htmlFiles = glob($caseDir . '/*.html');
            if (!empty($htmlFiles)) {
                $this->htmlPath = $htmlFiles[0];
            }
            $this->refDir = $caseDir . '/ref';
        }

        @mkdir($this->refDir, 0777, true);
        $ts = date('Ymd_His');

        // Read window dimensions from main.php for anchor validation
        $viewW = $this->readWindowDimension('WINDOW_WIDTH', 1600);
        $viewH = $this->readWindowDimension('WINDOW_HEIGHT', 800);

        // I-1: Exe headless screenshot (timestamped filename)
        $engineFile = "{$this->refDir}/engine_screenshot_{$ts}.png";
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

        // I-2: Browser screenshot (timestamped filename)
        $browserFile = "{$this->refDir}/browser_ref_{$ts}.png";
        if (!$this->browser->isAvailable()) {
            return StepResult::err('screenshot_compare', 'Edge not available');
        }
        if (!$this->browser->screenshot($this->htmlPath, $browserFile, $viewW, $viewH)) {
            return StepResult::err('screenshot_compare', 'Browser screenshot failed');
        }
        echo "  [browser screenshot] " . filesize($browserFile) . " bytes (view={$viewW}x{$viewH})\n";

        // I-2.5: Anchor visibility validation
        $anchorStatus = $this->validateAnchorsInViewport($engineFile, $viewW, $viewH);
        if (!$anchorStatus['valid']) {
            echo "  [anchor] WARNING: anchor visibility issue 鈥?{$anchorStatus['reason']}\n";
        }

        // I-3: Anchor-aligned pixel diff + 锚点裁剪后 diff 图
        $diffResult = $this->comparePixels($engineFile, $browserFile);
        $diffPct = $diffResult['diff'];
        echo "  [pixel diff] {$diffPct}%\n";

        if ($diffPct > 0 && $diffResult['diffFile'] !== null) {
            echo "  [diff image] {$diffResult['diffFile']}\n";
        }

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
     *   2. Compute per-image crop rect: TL anchor 鈫?BR anchor
     *   3. Crop both to their content rectangles
     *   4. Pixel diff on cropped regions
     *   5. Fallback: if anchors not found, auto-detect content bounds
     */
    private function comparePixels(string $fileA, string $fileB): array
    {
        if (!extension_loaded('gd')) {
            return ['diff' => 100.0, 'diffFile' => null];
        }

        $imgA = @imagecreatefrompng($fileA);
        $imgB = @imagecreatefrompng($fileB);
        if (!$imgA || !$imgB) return ['diff' => 100.0, 'diffFile' => null];

        $wA = imagesx($imgA); $hA = imagesy($imgA);
        $wB = imagesx($imgB); $hB = imagesy($imgB);

        // Tier 1: Color anchor detection
        $anchorsA = $this->detectColorAnchors($imgA, $wA, $hA);
        $anchorsB = $this->detectColorAnchors($imgB, $wB, $hB);

        if ($anchorsA && $anchorsB) {
            echo "  [align] color anchors: TL=({$anchorsA['tl_x']},{$anchorsA['tl_y']}) BR=({$anchorsA['br_x']},{$anchorsA['br_y']})\n";
            $cropA = $this->cropImage($imgA, $anchorsA);
            $cropB = $this->cropImage($imgB, $anchorsB);

            // 以浏览器截图为基准，等比例缩放引擎图使 BR.x 对齐
            // 补偿字体度量差异导致的宽度偏差
            $bw = imagesx($cropB);
            $bh = imagesy($cropB);
            $ew = imagesx($cropA);
            $eh = imagesy($cropA);
            if ($ew > 0 && $ew !== $bw) {
                $scale = $bw / $ew;
                $newH = (int)round($eh * $scale);
                $scaledA = imagescale($cropA, $bw, $newH);
                if ($scaledA !== false) {
                    imagedestroy($cropA);
                    $cropA = $scaledA;
                    echo "  [align] engine scaled: {$ew}x{$eh} → {$bw}x{$newH} (factor={$scale})\n";
                }
            }

            $frac = $this->pixelDiff($cropA, $cropB, "anchor");
            // 从对齐后的图片生成 diff 图
            $diffFile = $this->generateDiffImageFromCropped($cropA, $cropB);
            imagedestroy($cropA); imagedestroy($cropB);
            imagedestroy($imgA); imagedestroy($imgB);
            return ['diff' => $frac, 'diffFile' => $diffFile];
        }

        // Tier 2-3 fallback: auto content bounds detection
        $bounds = $this->autoDetectContentBounds($imgA, $wA, $hA, $imgB, $wB, $hB);
        if ($bounds) {
            echo "  [align] auto content bounds: x={$bounds['x']} y={$bounds['y']} w={$bounds['w']} h={$bounds['h']}\n";
            $cropA = $this->cropImageDirect($imgA, $bounds['x'], $bounds['y'], $bounds['w'], $bounds['h']);
            $cropB = $this->cropImageDirect($imgB, $bounds['x'], $bounds['y'], $bounds['w'], $bounds['h']);
            $frac = $this->pixelDiff($cropA, $cropB, "auto_bounds");
            $diffFile = $this->generateDiffImageFromCropped($cropA, $cropB);
            imagedestroy($cropA); imagedestroy($cropB);
            imagedestroy($imgA); imagedestroy($imgB);
            return ['diff' => $frac, 'diffFile' => $diffFile];
        }

        imagedestroy($imgA); imagedestroy($imgB);
        echo "  [align] fallback: no anchors or bounds found\n";
        return ['diff' => 100.0, 'diffFile' => null];
    }

    /**
     * Detect TL(#FF00FF) and BR(#00FFFF) color anchors.
     *
     * Anchors are 8x8px pure color blocks at card padding-box corners.
     * Two-phase: coarse scan for matching pixel, then verify 8x8 block
     * at optimal alignment around the found pixel.
     */
    private function detectColorAnchors(\GdImage $img, int $w, int $h): ?array
    {
        $tl = null; $br = null;
        $step = max(2, (int)($w / 600));

        // Phase 1: coarse scan for TL matching pixel (top-left region)
        for ($y = 0; $y < $h && $tl === null; $y += $step) {
            for ($x = 0; $x < $w && $tl === null; $x += $step) {
                if ($this->isAnchorColor(imagecolorat($img, $x, $y), self::TL_COLOR)) {
                    // Phase 2: verify 8x8 block at best alignment
                    $aligned = $this->findAnchorBlockAt($img, $x, $y, self::TL_COLOR, $w, $h);
                    if ($aligned !== null) {
                        $tl = $aligned;
                    }
                }
            }
        }

        // Phase 1: coarse scan for BR matching pixel (bottom-right region, full height)
        for ($y = $h - 1; $y >= 0 && $br === null; $y -= $step) {
            for ($x = $w - 1; $x >= 0 && $br === null; $x -= $step) {
                if ($this->isAnchorColor(imagecolorat($img, $x, $y), self::BR_COLOR)) {
                    // Phase 2: verify 8x8 block at best alignment
                    $aligned = $this->findAnchorBlockAt($img, $x, $y, self::BR_COLOR, $w, $h);
                    if ($aligned !== null) {
                        // Return bottom-right corner (+ ANCHOR_SIZE for BR)
                        $br = ['x' => $aligned['x'] + self::ANCHOR_SIZE,
                                'y' => $aligned['y'] + self::ANCHOR_SIZE];
                    }
                }
            }
        }

        if (!$tl || !$br) return null;
        if ($tl['x'] >= $br['x'] || $tl['y'] >= $br['y']) return null;

        return ['tl_x' => $tl['x'], 'tl_y' => $tl['y'], 'br_x' => $br['x'], 'br_y' => $br['y']];
    }

    /**
     * Given a candidate anchor pixel, search nearby 8x8 alignments
     * to find the one that best matches the anchor color.
     */
    private function findAnchorBlockAt(\GdImage $img, int $px, int $py, int $target, int $maxW, int $maxH): ?array
    {
        // Try all 8x8 alignments that include (px, py)
        for ($dy = -(self::ANCHOR_SIZE - 1); $dy <= 0; $dy++) {
            for ($dx = -(self::ANCHOR_SIZE - 1); $dx <= 0; $dx++) {
                $sx = $px + $dx;
                $sy = $py + $dy;
                if ($sx < 0 || $sy < 0) continue;
                if ($sx + self::ANCHOR_SIZE > $maxW || $sy + self::ANCHOR_SIZE > $maxH) continue;
                if ($this->isAnchorBlock($img, $sx, $sy, $target, $maxW, $maxH)) {
                    return ['x' => $sx, 'y' => $sy];
                }
            }
        }
        return null;
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
     * Auto content bounds detection 鈥?find the non-background content area.
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

    /**
     * Read WINDOW_WIDTH/HEIGHT from main.php.
     * Falls back to default if file not found.
     */
    private function readWindowDimension(string $constName, int $default): int
    {
        $mainPhp = dirname($this->refDir, 2) . '/main.php';
        if (!file_exists($mainPhp)) return $default;
        $content = @file_get_contents($mainPhp);
        if ($content === false) return $default;
        if (preg_match('/const\s+' . $constName . '\s*=\s*(\d+)/', $content, $m)) {
            return (int)$m[1];
        }
        return $default;
    }

    /**
     * Validate TL/BR anchors are within the visible viewport.
     * Anchors outside viewport 鈫?screenshot alignment will fail.
     */
    private function validateAnchorsInViewport(string $pngPath, int $viewW, int $viewH): array
    {
        if (!extension_loaded('gd')) return ['valid' => true, 'reason' => 'GD not available, skip'];
        $img = @imagecreatefrompng($pngPath);
        if (!$img) return ['valid' => true, 'reason' => 'Cannot load screenshot'];
        $anchors = $this->detectColorAnchors($img, imagesx($img), imagesy($img));
        imagedestroy($img);

        if (!$anchors) return ['valid' => false, 'reason' => 'No anchor blocks found in screenshot'];

        // Check bounds
        if ($anchors['tl_x'] < 0 || $anchors['tl_y'] < 0) {
            return ['valid' => false, 'reason' => "TL anchor ({$anchors['tl_x']},{$anchors['tl_y']}) out of viewport"];
        }
        if ($anchors['br_x'] > $viewW || $anchors['br_y'] > $viewH) {
            return ['valid' => false, 'reason' => "BR anchor ({$anchors['br_x']},{$anchors['br_y']}) exceeds viewport ({$viewW}x{$viewH})"];
        }

        return ['valid' => true, 'reason' => 'ok'];
    }

    /**
     * Generate diff image from already-cropped (anchor-aligned) images.
     * Red overlay on differing regions.
     */
    private function generateDiffImageFromCropped(\GdImage $cropA, \GdImage $cropB): ?string
    {
        $w = min(imagesx($cropA), imagesx($cropB));
        $h = min(imagesy($cropA), imagesy($cropB));
        $diff = imagecreatetruecolor($w, $h);

        // 以浏览器截图(cropB)为底图，引擎截图(cropA)在差异区域以 50% 混合叠加
        // 一致区域显示浏览器原图
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $cA = @imagecolorat($cropA, $x, $y);
                $cB = @imagecolorat($cropB, $x, $y);
                $rA = ($cA >> 16) & 0xFF; $gA = ($cA >> 8) & 0xFF; $bA = $cA & 0xFF;
                $rB = ($cB >> 16) & 0xFF; $gB = ($cB >> 8) & 0xFF; $bB = $cB & 0xFF;
                $diffPx = abs($rA - $rB) + abs($gA - $gB) + abs($bA - $bB);
                if ($diffPx > 25) {
                    // 差异区域：引擎截图半透明叠加到浏览器上
                    $mixed = imagecolorallocatealpha($diff,
                        (int)(($rA + $rB) / 2),
                        (int)(($gA + $gB) / 2),
                        (int)(($bA + $bB) / 2),
                        0);
                    imagesetpixel($diff, $x, $y, $mixed);
                } else {
                    $blend = imagecolorallocate($diff, $rB, $gB, $bB);
                    imagesetpixel($diff, $x, $y, $blend);
                }
            }
        }

        $ts = date('Ymd_His');
        $diffFile = "{$this->refDir}/diff_{$ts}.png";
        imagepng($diff, $diffFile);
        imagedestroy($diff);
        return $diffFile;
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
