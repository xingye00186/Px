<?php
$src = file_get_contents(__DIR__ . '/SummaryReporter.php');

$missing = "    private function stepIcon(array \$stepMap, string \$name): string
    {
        if (!isset(\$stepMap[\$name])) return "\u23ed\ufe0f";
        return \$stepMap[\$name] ? "\u2705" : "\u274c";
    }

";

$src = str_replace('    private function writeReport', $missing . '    private function writeReport', $src);

file_put_contents(__DIR__ . '/SummaryReporter.php', $src);
echo 'stepIcon added' . PHP_EOL;
