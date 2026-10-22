<?php
require 'config.php';
require 'vendor/autoload.php';

require_once __DIR__ . '/lib/autoload.php';

use BitwardenOrgSync\Helper;
use BitwardenOrgSync\Logger;

$options = getopt('', ['once', 'dry-run', 'rebuild-mapping', 'verbose', 'help']);
if (isset($options['help'])) {
    echo "Usage: php run.php [options]\n"
        . "  --once             run one synchronization cycle and exit\n"
        . "  --dry-run          run one cycle and only report what would be changed\n"
        . "  --rebuild-mapping  link the items that already exist on both servers (e.g. after a loss of Redis) and exit\n"
        . "  --verbose          log everything (LOG_LEVEL debug)\n";
    exit(0);
}
$dryRun = isset($options['dry-run']);
$rebuild = isset($options['rebuild-mapping']);
$once = isset($options['once']) || $dryRun || $rebuild;

Logger::setLevel(isset($options['verbose']) ? 'debug' : ($config['LOG_LEVEL'] ?? 'info'));

$notification = new \BitwardenOrgSync\Notification($config['NOTIFICAION'] ?? []);

set_exception_handler(function (\Throwable $e) use ($notification) {
    $message = 'Fatal error: ' . Helper::describeException($e);
    Logger::error($message);
    $notification->send($message);
    exit(1);
});

register_shutdown_function(function () use ($notification) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $message = 'Fatal error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'];
        Logger::error($message);
        $notification->send($message);
    }
});

$redis = Helper::redis($config);

//Init API Servers
$bwmanager = new \BitwardenOrgSync\BWSync($config);

//whatever way the script ends, the bw serve processes must not stay behind
register_shutdown_function(function () use ($bwmanager) {
    $bwmanager->stopServers();
});

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function (int $signal) {
        Logger::info("Signal $signal received, stopping");
        exit(0);
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

foreach (['BW_LOCAL', 'BW_REMOTE'] as $server) {
    if (empty($config[$server]['ORGANIZATION_ID'])) {
        $bwmanager->exit("Error: $server ORGANIZATION_ID is not set.");
    }
}

if (!$dryRun) {
    $redis->del(['BW_LOCAL_SESSION', 'BW_REMOTE_SESSION']); //older versions kept the bw sessions in Redis in plain text
}

$bwVersion = $bwmanager->version();
Logger::info('BitwardenOrgSync started, bw ' . ($bwVersion ?: 'version unknown') . ', sync interval ' . $config['SYNC_INTERVAL'] . ' sec.');
Logger::info('BW_LOCAL: ' . $config['BW_LOCAL']['URL'] . ', organization ' . $config['BW_LOCAL']['ORGANIZATION_ID']);
Logger::info('BW_REMOTE: ' . $config['BW_REMOTE']['URL'] . ', organization ' . $config['BW_REMOTE']['ORGANIZATION_ID']);
if ($bwVersion && version_compare($bwVersion, '2024.6.0', '<')) {
    Logger::warning("bw $bwVersion is older than the required 2024.6.0");
}
if ($dryRun) {
    Logger::info('Dry run: one cycle, nothing is changed');
} elseif ($rebuild) {
    Logger::info('Rebuild mode: the items that exist on both servers are linked, nothing is created');
}

$bwmanager->serveBackground('BW_LOCAL');
$bwmanager->serveBackground('BW_REMOTE');

//Init API Clients
$httpTimeout = (int) ($config['HTTP_TIMEOUT'] ?? 120);
$local = new \BitwardenOrgSync\ApiClient('http://[::1]:' . $config['BW_LOCAL']['SERVE_PORT'], 'BW_LOCAL', $httpTimeout);
$remote = new \BitwardenOrgSync\ApiClient('http://[::1]:' . $config['BW_REMOTE']['SERVE_PORT'], 'BW_REMOTE', $httpTimeout);

$syncer = new \BitwardenOrgSync\Syncer($redis, $local, $remote, $config, $dryRun);

$healthFile = $config['HEALTH_FILE'] ?? sys_get_temp_dir() . '/bwsync-health';
$cycle = 0;
$failedCycles = 0;
$lockedCycles = 0;
$alerted = false;

while (true) {

    $cycle++;
    $started = microtime(true);
    $stats = Helper::newStats();
    $local->resetErrors();
    $remote->resetErrors();
    $locked = [];
    $exception = '';

    try {

        $bwmanager->checkServers();

        $localCounter = 0;
        $remoteCounter = 0;

        while (($local->status() === false)) {
            if ($localCounter >= $config['API_SERVER_WAIT_TIMEOUT']) {
                $bwmanager->exit("Error: BW_LOCAL API Server is not ready. Script stopped.");
            }
            if ($localCounter % 5 == 0) {
                Logger::info('Waiting for BW_LOCAL API...');
            }
            $localCounter++;
            sleep(1);
            $bwmanager->checkServers();
        }

        while (($remote->status() === false)) {
            if ($remoteCounter >= $config['API_SERVER_WAIT_TIMEOUT']) {
                $bwmanager->exit("Error: BW_REMOTE API Server is not ready. Script stopped.");
            }
            if ($remoteCounter % 5 == 0) {
                Logger::info('Waiting for BW_REMOTE API...');
            }
            $remoteCounter++;
            sleep(1);
            $bwmanager->checkServers();
        }

        foreach (['BW_LOCAL' => $local, 'BW_REMOTE' => $remote] as $name => $api) {
            $vault = $api->vaultStatus();
            if ($vault !== false && $vault !== 'unlocked') {
                $locked[] = $name;
                Logger::error("$name vault is not unlocked (status: $vault)");
            }
        }

        if (!count($locked)) {
            Logger::debug('Local sync...');
            $localSynced = $local->sync();
            Logger::debug('Remote sync...');
            $remoteSynced = $remote->sync();
            if ($localSynced && $remoteSynced) {
                $stats = $rebuild ? $syncer->rebuildMapping() : $syncer->run();
            } else {
                $failed = array_keys(array_filter(['BW_LOCAL' => !$localSynced, 'BW_REMOTE' => !$remoteSynced]));
                Logger::error('Sync failed on ' . implode(' and ', $failed) . ', the cycle is skipped to avoid working with stale data');
            }
        }

    } catch (\Throwable $e) {
        $exception = Helper::describeException($e);
        Logger::error('Synchronization cycle failed: ' . $exception);
        Logger::debug($e->getTraceAsString());
    }

    $errors = $local->getErrorCount() + $remote->getErrorCount() + $stats['errors'] + count($locked) + (strlen($exception) ? 1 : 0);
    if (!$rebuild) {
        $summary = Helper::summary($cycle, $stats, microtime(true) - $started, $errors);
        if ($errors) {
            Logger::warning($summary);
        } else {
            Logger::info($summary);
        }
    }

    if ($once) {
        if ($dryRun) {
            Logger::info('Dry run finished, nothing was changed');
        }
        exit($errors ? 1 : 0);
    }

    $failedCycles = $errors ? $failedCycles + 1 : 0;
    $lockedCycles = count($locked) ? $lockedCycles + 1 : 0;

    $exitAfter = (int) ($config['LOCKED_EXIT_CYCLES'] ?? 3);
    if ($exitAfter > 0 && $lockedCycles >= $exitAfter) {
        $bwmanager->exit('Error: ' . implode(' and ', $locked) . " vault has been locked for $lockedCycles cycles in a row. Script stopped, restart it to log in again.");
    }

    $alertAfter = (int) ($config['FAIL_ALERT_CYCLES'] ?? 3);
    if ($alertAfter > 0 && $failedCycles >= $alertAfter && !$alerted) {
        $lastError = current(array_filter([$exception, $local->getLastError(), $remote->getLastError(), $stats['aborted'], count($locked) ? implode(' and ', $locked) . ' vault is not unlocked' : '']));
        //not marked as sent when the notification failed, so it is tried again in the next cycle
        $alerted = $notification->send("Synchronization has failed in $failedCycles cycles in a row. Last error: $lastError");
    } elseif (!$errors && $alerted) {
        $notification->send('Synchronization has recovered.');
        $alerted = false;
    }

    if (!$errors) {
        @file_put_contents($healthFile, time() . ' ' . (3 * (int) $config['SYNC_INTERVAL'] + 120)); //read by the docker healthcheck
    }

    Logger::debug('Sleep for ' . $config['SYNC_INTERVAL'] . ' sec.');
    for ($i = 0; $i < (int) $config['SYNC_INTERVAL']; $i++) {
        sleep(1);
        $bwmanager->checkServers();
    }
}
