<?php
use Px\Core\Application;
use Px\Rendering\RenderNode;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1600;
const WINDOW_HEIGHT = 800;
const WINDOW_TITLE  = 'CSS Test Sandbox';

function logChildren(string $tag, RenderNode $parent, int $scrollTop, int $contentH, int $containerH): void {
    $count = count($parent->children);
    $info = "[$tag] scrollTop=$scrollTop h=$containerH contentH=$contentH maxScroll=" . max($contentH - $containerH, 0) . " children=$count";
    for ($i = 0; $i < min($count, 5); $i++) {
        $c = $parent->children[$i];
        $info .= " | child$i y={$c->y} h={$c->visualH} renderOffY={$c->renderOffsetY} key=" . ($c->key ?? 'null') . " dirty=" . ($c->layoutDirty ? '1' : '0');
    }
    if ($count > 5) {
        $last = $parent->children[$count - 1];
        $info .= " | ... last child$count y={$last->y} h={$last->visualH} renderOffY={$last->renderOffsetY}";
    }
    error_log($info);
}

function runMultiStepTest(Application $app, RenderNode $caseList, int $steps, int $maxScroll, bool $useFullRender): void {
    $mode = $useFullRender ? 'render' : 'directRender';
    error_log("[MULTI_STEP_START] mode=$mode steps=$steps maxScroll=$maxScroll");
    for ($step = 1; $step <= $steps; $step++) {
        $st = (int)($maxScroll * $step / $steps);
        $oldST = $caseList->scrollTop;
        $oldContentH = $caseList->contentHeight;
        $oldH = $caseList->h;
        $caseList->scrollTop = $st;
        
        if ($useFullRender) {
            $app->render();
        } else {
            $app->directRender();
        }
        
        $changed = '';
        if ($caseList->contentHeight !== $oldContentH) $changed .= ' contentH:' . $oldContentH . '->' . $caseList->contentHeight;
        if ($caseList->h !== $oldH) $changed .= ' h:' . $oldH . '->' . $caseList->h;
        error_log("[MULTI_STEP] step=$step/$steps scrollTop=$st (prev=$oldST) children=" . count($caseList->children) . " contentH={$caseList->contentHeight} h={$caseList->h} mode=$mode$changed");
        
        $n = count($caseList->children);
        for ($i = 0; $i < min(3, $n); $i++) {
            $c = $caseList->children[$i];
            error_log("  child[$i] y={$c->y} h={$c->visualH} renderOffY={$c->renderOffsetY} key=" . ($c->key ?? 'null'));
        }
        if ($n > 6) {
            for ($i = $n-3; $i < $n; $i++) {
                $c = $caseList->children[$i];
                error_log("  child[$i] y={$c->y} h={$c->visualH} renderOffY={$c->renderOffsetY} key=" . ($c->key ?? 'null'));
            }
        }
    }
    error_log("[MULTI_STEP_DONE] mode=$mode final scrollTop={$caseList->scrollTop} contentH={$caseList->contentHeight} h={$caseList->h} children=" . count($caseList->children));
}

function main(): int
{
    global $argv;

    // --headless: 不显示窗口（用于 CI/自动化 dump-layout）
    if (in_array('--headless', $argv)) {
        define('APP_HEADLESS', true);
    }

    $root = ComponentFactory::create(AppComponent::class);
    $appDir = __DIR__;
    $app = Application::create()->mount($root, $appDir);

    global $argv;
    if (Application::handleDumpArgs($app, $appDir, $argv)) {
        return 0;
    }

    // --screenshot=path: 渲染后直接保存截图到文件（无需窗口可见）
    $screenshotPath = '';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--screenshot=')) {
            $screenshotPath = substr($arg, strlen('--screenshot='));
        }
    }
    if ($screenshotPath !== '') {
        $app->render();
        $app->saveScreenshot($screenshotPath);
        return 0;
    }

    $autoScroll = 0;
    $useFullRender = false;
    $multiStep = 0;
    $staleRefTest = false;
    $alternatingCycles = 0;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--auto-scroll=')) {
            $autoScroll = (int)substr($arg, 14);
        }
        if ($arg === '--use-render') {
            $useFullRender = true;
        }
        if (str_starts_with($arg, '--multi-step=')) {
            $multiStep = (int)substr($arg, 13);
        }
        if ($arg === '--stale-ref-test') {
            $staleRefTest = true;
        }
        if (str_starts_with($arg, '--alternating-test=')) {
            $alternatingCycles = (int)substr($arg, 19);
        }
    }

    $hasDiagnosticMode = $autoScroll > 0 || $alternatingCycles > 0 || $staleRefTest || $multiStep > 0;
    if ($hasDiagnosticMode) {
        $app->render();
        $app->dumpLayoutToFile($appDir . '/diag_before.json');
        error_log('[DIAG] Initial render + layout dumped');

        $findSC = function(RenderNode $n, int $x, int $y) use (&$findSC): ?RenderNode {
            if ($n->isScrollContainer && $n->x === $x && $n->y === $y) return $n;
            foreach ($n->children as $c) { $r = $findSC($c, $x, $y); if ($r) return $r; }
            return null;
        };
        $rtmProp = new ReflectionProperty($app, 'renderTreeManager');
        $rtmProp->setAccessible(true);
        $rtm = $rtmProp->getValue($app);
        $rootProp = new ReflectionProperty($rtm, 'rootRenderNode');
        $rootProp->setAccessible(true);
        $rootNode = $rootProp->getValue($rtm);

        $caseList = $findSC($rootNode, 0, 77);
        if ($caseList !== null) {
            error_log('[DIAG] Found case-list scrollContainer: scrollTop=' . $caseList->scrollTop . ' contentH=' . $caseList->contentHeight . ' h=' . $caseList->h);
            
            if ($staleRefTest) {
                $maxScroll = max($caseList->contentHeight - $caseList->h, 0);
                error_log('[STALE_REF] Starting stale reference test maxScroll=' . $maxScroll);

                for ($i = 1; $i <= 3; $i++) {
                    $st = (int)($maxScroll * $i / 10);
                    $caseList->scrollTop = $st;
                    $app->directRender();
                    error_log('[STALE_REF] drag step=' . $i . ' scrollTop=' . $st . ' ON OLD caseList scrollTop=' . $caseList->scrollTop);
                }

                error_log('[STALE_REF] SIMULATING WM_PAINT: calling $app->render()...');
                $app->render();

                $newRoot = $rtm->getRootRenderNode();
                $newCaseList = $findSC($newRoot, 0, 77);
                if ($newCaseList !== null) {
                    error_log('[STALE_REF] After full render: OLD caseList scrollTop=' . $caseList->scrollTop . ' NEW caseList scrollTop=' . $newCaseList->scrollTop . ' contentH=' . $newCaseList->contentHeight . ' h=' . $newCaseList->h);

                    for ($i = 4; $i <= 7; $i++) {
                        $st = (int)($maxScroll * $i / 10);
                        $caseList->scrollTop = $st;
                        $app->directRender();
                        error_log('[STALE_REF] drag step=' . $i . ' OLD.scrollTop=' . $caseList->scrollTop . ' NEW.scrollTop=' . $newCaseList->scrollTop . ' (SHOULD BE SAME=' . ($caseList->scrollTop === $newCaseList->scrollTop ? 'YES' : 'NO') . ')');
                    }
                }
                return 0;
            }

            if ($alternatingCycles > 0) {
                $maxScroll = max($caseList->contentHeight - $caseList->h, 0);
                error_log('[ALTERNATING] Starting: cycles=' . $alternatingCycles . ' maxScroll=' . $maxScroll . ' contentH=' . $caseList->contentHeight . ' h=' . $caseList->h);

                for ($cycle = 1; $cycle <= $alternatingCycles; $cycle++) {
                    $intended = (int)($maxScroll * $cycle / $alternatingCycles);
                    $caseList->scrollTop = $intended;
                    $app->directRender();
                    $actualA = $caseList->scrollTop;
                    $driftA = $actualA - $intended;

                    $app->render();
                    $newRoot = $rtm->getRootRenderNode();
                    $newCaseList = $findSC($newRoot, 0, 77);
                    $actualB = $newCaseList !== null ? $newCaseList->scrollTop : -1;
                    $driftB = $actualB - $intended;
                    $sameObj = $caseList === $newCaseList ? 'YES' : 'NO';

                    $log = "[ALTERNATING] cycle=$cycle/$alternatingCycles" .
                        " intended=$intended" .
                        " A(scrollTop=$actualA drift=$driftA" .
                        " contentH={$caseList->contentHeight} h={$caseList->h})" .
                        " B(scrollTop=$actualB drift=$driftB" .
                        " contentH=" . ($newCaseList?->contentHeight ?? '?') .
                        " h=" . ($newCaseList?->h ?? '?') .
                        " sameObj=$sameObj)";
                    error_log($log);

                    if ($driftB !== 0 && $newCaseList !== null) {
                        error_log("[ALTERNATING] *** DRIFT DETECTED at cycle $cycle! drift=$driftB ***");
                        $n = count($newCaseList->children);
                        for ($i = 0; $i < min(5, $n); $i++) {
                            $c = $newCaseList->children[$i];
                            error_log("  child[$i] y={$c->y} h={$c->visualH} renderOffY={$c->renderOffsetY} key=" . ($c->key ?? 'null') . " dirty=" . ($c->layoutDirty ? '1' : '0'));
                        }
                        if ($n > 5) {
                            $last = $newCaseList->children[$n - 1];
                            error_log("  child[$n-1] y={$last->y} h={$last->visualH} renderOffY={$last->renderOffsetY} key=" . ($last->key ?? 'null'));
                        }
                    }
                }
                return 0;
            }

            if ($multiStep > 0) {
                $maxScroll = max($caseList->contentHeight - $caseList->h, 0);
                error_log('[MULTI_STEP] Starting multi-step test: steps=' . $multiStep . ' maxScroll=' . $maxScroll . ' mode=' . ($useFullRender ? 'render' : 'directRender'));
                runMultiStepTest($app, $caseList, $multiStep, $maxScroll, $useFullRender);
                logChildren('FINAL', $caseList, $caseList->scrollTop, $caseList->contentHeight, $caseList->h);
            } else {
                $oldST = $caseList->scrollTop;
                $caseList->scrollTop = $autoScroll;
                error_log('[AUTO_SCROLL] scrollTop: ' . $oldST . ' → ' . $autoScroll . ' (h=' . $caseList->h . ' contentH=' . $caseList->contentHeight . ' maxScroll=' . max($caseList->contentHeight - $caseList->h, 0) . ')');

                if ($useFullRender) {
                    error_log('[AUTO_SCROLL] Using FULL render() path (with VNode rebuild)');
                    $app->render();
                } else {
                    error_log('[AUTO_SCROLL] Using directRender() path (no VNode rebuild)');
                    $app->directRender();
                }

                $rootAfter = $rtm->getRootRenderNode();
                $caseListAfter = $findSC($rootAfter, 0, 77);
                if ($caseListAfter !== null) {
                    logChildren('AFTER', $caseListAfter, $caseListAfter->scrollTop, $caseListAfter->contentHeight, $caseListAfter->h);
                }

                $app->dumpLayoutToFile($appDir . '/diag_after.json');
                error_log('[AUTO_SCROLL] After-scroll layout dumped (scrollTop=' . $autoScroll . ' mode=' . ($useFullRender ? 'render' : 'directRender') . ')');
            }
        } else {
            error_log('[DIAG] ERROR: Could not find case-list scrollContainer at (0,77)!');
        }
        return 0;
    }

    $app->run();
    return 0;
}
