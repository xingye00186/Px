<?php
/**
 * Quick verification of generated layout output.
 * Run: php tests/verify-layout.php
 */
require_once __DIR__ . '/../apps/calculator/gen/AppLayout_gen.php';

$l = getLayout();
$errors = 0;

function check(string $label, bool $condition, string $msg): void
{
    global $errors;
    if ($condition) {
        echo "OK: $label\n";
    } else {
        echo "FAIL: $label — $msg\n";
        $errors++;
    }
}

echo "Window: " . WINDOW_WIDTH . "x" . WINDOW_HEIGHT . "\n";
echo "Elements: " . count($l['elements']) . " (expect 10)\n";
echo "Buttons:  " . count($l['buttons']) . " (expect 20)\n\n";

// --- Main Calculator Elements ---

// Check first rect (app-bg)
$r0 = $l['elements'][0];
check('First rect type', $r0['type'] === 'rect', "Expected rect, got {$r0['type']}");
check('First rect at (0,0)', $r0['x'] === 0 && $r0['y'] === 0, "Got ({$r0['x']},{$r0['y']})");
check("First rect = {$r0['w']}x{$r0['h']}", $r0['w'] === 328 && $r0['h'] === 420, "Expected 328x420");

// Check display-bg rect (from DisplayPanel, offset by +4,+4)
$r1 = $l['elements'][1];
check('display-bg rect type', $r1['type'] === 'rect', "Expected rect");
check('display-bg at (4,4)', $r1['x'] === 4 && $r1['y'] === 4, "Got ({$r1['x']},{$r1['y']})");
check('display-bg size 320x72', $r1['w'] === 320 && $r1['h'] === 72, "Got {$r1['w']}x{$r1['h']}");

// Check display text (from DisplayPanel, offset +4,+4, :value="display" bound)
$t0 = $l['elements'][2];
check('Display text type', $t0['type'] === 'text', "Expected text, got {$t0['type']}");
check("Display text bind='display'", $t0['bind'] === 'display', "Expected 'display', got '{$t0['bind']}'");
check('Display text align=right', $t0['align'] === 'right', "Expected 'right', got '{$t0['align']}'");
check('Display text fontSize=32', $t0['fontSize'] === 32, "Expected 32, got {$t0['fontSize']}");
check('Display text bold=1', ($t0['bold'] ?? 0) === 1, "Expected 1, got " . ($t0['bold'] ?? 0));
check("Display text at (4,36)", $t0['x'] === 4 && $t0['y'] === 36, "Got ({$t0['x']},{$t0['y']})");

// Check expression text (v-model="expression", v-if)
$t1 = $l['elements'][3];
check('Expression text type', $t1['type'] === 'text', "Expected text");
check("Expression text bind='expression'", $t1['bind'] === 'expression', "Expected 'expression', got '{$t1['bind']}'");
check('Expression text align=left', $t1['align'] === 'left', "Expected 'left', got '{$t1['align']}'");
check('Expression text fontSize=16', $t1['fontSize'] === 16, "Expected 16, got {$t1['fontSize']}");
check("Expression text at (10,10)", $t1['x'] === 10 && $t1['y'] === 10, "Got ({$t1['x']},{$t1['y']})");
check('Expression text has v-if condition', isset($t1['condition']), "Missing condition array");

// --- AboutDialog Elements (indices 4-9) ---

// Check dialog overlay rect
$d0 = $l['elements'][4];
check('Dialog overlay rect type', $d0['type'] === 'rect', "Expected rect");
check('Dialog overlay at (0,0) 328x420', $d0['x'] === 0 && $d0['y'] === 0 && $d0['w'] === 328 && $d0['h'] === 420, "Got ({$d0['x']},{$d0['y']},{$d0['w']},{$d0['h']})");
check('Dialog overlay has v-if=showDialog', isset($d0['condition']) && $d0['condition']['prop'] === 'showDialog', "Missing or wrong condition");

// Check dialog box rect
$d1 = $l['elements'][5];
check('Dialog box rect type', $d1['type'] === 'rect', "Expected rect");
check('Dialog box has v-if=showDialog', isset($d1['condition']) && $d1['condition']['prop'] === 'showDialog', "Missing condition");

// Check dialog title text
$d2 = $l['elements'][6];
check('Dialog title bind=dialogTitle', $d2['bind'] === 'dialogTitle', "Expected 'dialogTitle', got '{$d2['bind']}'");

// Check dialog content text
$d4 = $l['elements'][8];
check('Dialog content bind=dialogContent', $d4['bind'] === 'dialogContent', "Expected 'dialogContent', got '{$d4['bind']}'");

// Check dialog version text
$d5 = $l['elements'][9];
check('Dialog version bind=dialogVersion', $d5['bind'] === 'dialogVersion', "Expected 'dialogVersion', got '{$d5['bind']}'");

// --- Buttons ---

// Check button count
check('Button count = 20', count($l['buttons']) === 20, "Got " . count($l['buttons']));

// Check first button (C/reset) — has v-if falsy (hidden when dialog shown)
$b0 = $l['buttons'][0];
check("First btn label='C'", $b0['label'] === 'C', "Got '{$b0['label']}'");
check("First btn handler='reset'", $b0['handler'] === 'reset', "Got '{$b0['handler']}'");
check('First btn no arg', $b0['arg'] === NULL, "Got '{$b0['arg']}'");
check('First btn has v-if falsy condition', isset($b0['condition']) && $b0['condition']['op'] === 'falsy' && $b0['condition']['prop'] === 'showDialog', "Missing or wrong v-if condition on num-pad button");

// Check last numpad button (.) at index 17
$b17 = $l['buttons'][17];
check("Numpad last btn label='.'", $b17['label'] === '.', "Got '{$b17['label']}'");
check("Numpad last btn handler='handleButton'", $b17['handler'] === 'handleButton', "Got '{$b17['handler']}'");
check('Numpad last btn has v-if falsy condition', isset($b17['condition']) && $b17['condition']['op'] === 'falsy' && $b17['condition']['prop'] === 'showDialog', "Missing or wrong v-if condition on num-pad button");

// Check ? button (index 18) — repositioned, now has v-if falsy condition
$bAbout = $l['buttons'][18];
check("About btn label='?'", $bAbout['label'] === '?', "Got '{$bAbout['label']}'");
check("About btn handler='toggleAboutDialog'", $bAbout['handler'] === 'toggleAboutDialog', "Got '{$bAbout['handler']}'");
check("About btn pos (290,38)", $bAbout['x'] === 290 && $bAbout['y'] === 38, "Got ({$bAbout['x']},{$bAbout['y']})");
check("About btn size 30x28", $bAbout['w'] === 30 && $bAbout['h'] === 28, "Got {$bAbout['w']}x{$bAbout['h']}");
check('About btn has v-if falsy condition', isset($bAbout['condition']) && $bAbout['condition']['op'] === 'falsy' && $bAbout['condition']['prop'] === 'showDialog', "Missing or wrong v-if condition");

// Check Close button (index 19) — inside dialog, has v-if truthy condition
$bClose = $l['buttons'][19];
check("Close btn label='Close'", $bClose['label'] === 'Close', "Got '{$bClose['label']}'");
check("Close btn handler='toggleAboutDialog'", $bClose['handler'] === 'toggleAboutDialog', "Got '{$bClose['handler']}'");
check("Close btn pos (134,275)", $bClose['x'] === 134 && $bClose['y'] === 275, "Got ({$bClose['x']},{$bClose['y']})");
check("Close btn size 60x28", $bClose['w'] === 60 && $bClose['h'] === 28, "Got {$bClose['w']}x{$bClose['h']}");
check('Close btn has v-if truthy condition', isset($bClose['condition']) && $bClose['condition']['op'] === 'truthy' && $bClose['condition']['prop'] === 'showDialog', "Missing or wrong v-if condition");

// Check = button (index 15)
$b15 = $l['buttons'][15];
check("Equals btn label='='", $b15['label'] === '=', "Got '{$b15['label']}'");
check("Equals btn handler='calculate'", $b15['handler'] === 'calculate', "Got '{$b15['handler']}'");

// Check grid coordinates for button 7 (row=1, col=0)
$b4 = $l['buttons'][4];
check("Btn 7 label='7'", $b4['label'] === '7', "Got '{$b4['label']}'");
check("Btn 7 at (2,142)", $b4['x'] === 2 && $b4['y'] === 142, "Got ({$b4['x']},{$b4['y']})");

echo "\n=== " . ($errors === 0 ? "ALL CHECKS PASSED" : "$errors CHECKS FAILED") . " ===\n";
exit($errors > 0 ? 1 : 0);
