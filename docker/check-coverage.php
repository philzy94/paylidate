<?php

// Fails the build when line coverage in a Clover report is below a threshold.
// Usage: php docker/check-coverage.php coverage.xml 80

[$script, $file, $minimum] = $argv + [null, 'coverage.xml', '80'];

if (!is_file($file)) {
    fwrite(STDERR, "Coverage report not found: {$file}\n");
    exit(1);
}

$metrics = simplexml_load_file($file)->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $statements > 0 ? round($covered / $statements * 100, 2) : 0.0;

echo "Line coverage: {$percent}% ({$covered}/{$statements}), minimum {$minimum}%\n";

exit($percent >= (float) $minimum ? 0 : 1);
