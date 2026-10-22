<?php
// Loads the library and the test helpers. No Redis, no bw and no network are needed.
error_reporting(E_ALL);

//A warning (for example an undefined array key) is a bug: it must fail the test and not only print a line
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

if (!class_exists('RedisException')) {
    class RedisException extends \Exception {}
}

require_once __DIR__ . '/../lib/autoload.php';
\BitwardenOrgSync\Logger::setLevel('off');

require_once __DIR__ . '/Fakes.php';

$GLOBALS['__tests'] = [];

function test(string $name, callable $body) {
    $GLOBALS['__tests'][$name] = $body;
}

function fail(string $message) {
    throw new \AssertionError($message);
}

function assertTrue($condition, string $message = 'the condition is not true') {
    if (!$condition) {
        fail($message);
    }
}

function assertSame($expected, $actual, string $message = '') {
    if ($expected !== $actual) {
        fail(($message !== '' ? "$message: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

//Syncer with fake servers; returns [$syncer, $redis, $local, $remote]
function env(array $config = [], bool $dryRun = false) {
    FakeApi::$clock = 0;
    $redis = new FakeRedis;
    $local = new FakeApi('L');
    $remote = new FakeApi('R');
    $config = array_replace_recursive(['BW_LOCAL' => ['ORGANIZATION_ID' => 'org-l'], 'BW_REMOTE' => ['ORGANIZATION_ID' => 'org-r']], $config);
    return [new \BitwardenOrgSync\Syncer($redis, $local, $remote, $config, $dryRun), $redis, $local, $remote];
}
