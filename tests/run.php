<?php
// Test runner without dependencies: php tests/run.php [part of a test name]
require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];
foreach ($GLOBALS['__tests'] as $name => $body) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $body();
        $passed++;
        echo '.';
    } catch (\Throwable $e) {
        $failed[$name] = $e;
        echo 'F';
    }
}
echo "\n\n";
foreach ($failed as $name => $e) {
    echo "FAILED: $name\n  " . get_class($e) . ': ' . $e->getMessage() . "\n  at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
echo $passed . ' passed, ' . count($failed) . " failed\n";
exit(count($failed) ? 1 : 0);
