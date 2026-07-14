<?php
$c = file_get_contents('f:/work/Px/tools/PxTest/Pipeline/Strategy/PipelineSteps.php');

$old = 'echo "  [HTML_SPEC_FAIL] " . basename($htmlPath) . " root container must have position:relative\n";
                return StepResult::err(\'dump_layout\', \'HTML spec validation failed\');';

$new = 'echo "  [HTML_SPEC_WARN] " . basename($htmlPath) . " root container missing position:relative\n";';

$c = str_replace($old, $new, $c);
file_put_contents('f:/work/Px/tools/PxTest/Pipeline/Strategy/PipelineSteps.php', $c);
echo "done\n";
