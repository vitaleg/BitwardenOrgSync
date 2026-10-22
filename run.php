<?php
require 'config.php';
require 'vendor/autoload.php';

foreach(glob(__DIR__ . "/lib/*.php") as $libFile) {
    require $libFile;
}

use BitwardenOrgSync\Helper;
use BitwardenOrgSync\Logger;

Logger::setLevel($config['LOG_LEVEL'] ?? 'info');

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

foreach (['BW_LOCAL', 'BW_REMOTE'] as $server) {
    if (empty($config[$server]['ORGANIZATION_ID'])) {
        $bwmanager->exit("Error: $server ORGANIZATION_ID is not set.");
    }
}

$bwVersion = $bwmanager->version();
Logger::info('BitwardenOrgSync started, bw ' . ($bwVersion ?: 'version unknown') . ', sync interval ' . $config['SYNC_INTERVAL'] . ' sec.');
Logger::info('BW_LOCAL: ' . $config['BW_LOCAL']['URL'] . ', organization ' . $config['BW_LOCAL']['ORGANIZATION_ID']);
Logger::info('BW_REMOTE: ' . $config['BW_REMOTE']['URL'] . ', organization ' . $config['BW_REMOTE']['ORGANIZATION_ID']);
if ($bwVersion && version_compare($bwVersion, '2024.6.0', '<')) {
    Logger::warning("bw $bwVersion is older than the required 2024.6.0");
}

$bwmanager->serveBackground('BW_LOCAL');
$bwmanager->serveBackground('BW_REMOTE');

//Init API Clients
$httpTimeout = (int) ($config['HTTP_TIMEOUT'] ?? 120);
$local = new \BitwardenOrgSync\ApiClient('http://[::1]:' . $config['BW_LOCAL']['SERVE_PORT'], 'BW_LOCAL', $httpTimeout);
$remote = new \BitwardenOrgSync\ApiClient('http://[::1]:' . $config['BW_REMOTE']['SERVE_PORT'], 'BW_REMOTE', $httpTimeout);

$stats = Helper::newStats();

//Stops the script when one of the bw serve processes has died
$checkChildren = function () use ($bwmanager) {
    static $warned = false;
    while (true) {
        $pid = pcntl_waitpid(-1, $status, WNOHANG);
        if ($pid > 0) {
            $server = $bwmanager->serverByPid($pid);
            //a child that exited with code 1 has already sent its own notification with the reason
            $notify = !(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 1);
            $bwmanager->exit('Error: ' . ($server ? "$server API server process" : 'The process') . " with PID $pid is stopped (" . Helper::describeExit($status) . ').', $notify);
        } elseif ($pid == 0) {
            break;
        } else {
            if (!$warned) {
                Logger::warning('pcntl_waitpid() failed, API server processes are not monitored.');
                $warned = true;
            }
            break;
        }
    }
};

//One synchronization cycle: collections, then items in both directions
$syncCycle = function () use ($config, $redis, $local, $remote, &$stats) {

    Logger::debug('Local sync...');
    $localSynced = $local->sync();
    Logger::debug('Remote sync...');
    $remoteSynced = $remote->sync();
    if (!$localSynced || !$remoteSynced) {
        $failed = array_keys(array_filter(['BW_LOCAL' => !$localSynced, 'BW_REMOTE' => !$remoteSynced]));
        Logger::error('Sync failed on ' . implode(' and ', $failed) . ', the cycle is skipped to avoid working with stale data');
        return;
    }

    //Sync Collections
    Logger::debug('Sync Collections...');
    foreach ($local->getOrgItems($config['BW_LOCAL']['ORGANIZATION_ID']) ?: [] as $lc) {
        if (!$redis->get($lc['id'])) {
            if ($rcc = $remote->createOrgCollection($config['BW_REMOTE']['ORGANIZATION_ID'], $lc['name'])) {
                Helper::link($redis, $lc['id'], $rcc['id'], $rcc['id'], $lc['id']);
                $stats['collections']['to_remote']++;
            }
        }
    }

    foreach ($remote->getOrgItems($config['BW_REMOTE']['ORGANIZATION_ID']) ?: [] as $rc) {
        if (!$redis->get($rc['id'])) {
            if ($lcc = $local->createOrgCollection($config['BW_LOCAL']['ORGANIZATION_ID'], $rc['name'])) {
                Helper::link($redis, $rc['id'], $lcc['id'], $lcc['id'], $rc['id']);
                $stats['collections']['to_local']++;
            }
        }
    }


    //Sync Items
    Logger::debug('Sync Items...');
    $localSkipped = [];
    foreach ($local->getItems($config['BW_LOCAL']['ORGANIZATION_ID']) ?: [] as $litem) {

        if (!strlen($litem['name'])) {
            continue;
        }

        if (!Helper::isSyncable($litem)) {
            Helper::countSkipped($localSkipped, $litem);
            continue;
        }

        if (!$remoteItem = $redis->get($litem['id'])) {
            $collectionIds = [];
            foreach ($litem['collectionIds'] as $lcId) {
                if ($rcId = $redis->get($lcId)) {
                    $collectionIds[] = $rcId;
                }
            }
            $fields = (isset($litem['fields'])) ? $litem['fields'] : [];
            $remoteItemCreate = $remote->createItem(
                $config['BW_REMOTE']['ORGANIZATION_ID'],
                $litem['name'],
                $collectionIds,
                $litem['type'],
                $litem['login'],
                $litem['notes'],
                $fields,
                $litem['reprompt'] ?? 0
            );
            if ($remoteItemCreate) {
                Helper::link(
                    $redis,
                    $litem['id'], ['linked_uuid' => $remoteItemCreate['id'], 'revisionDate' => $litem['revisionDate']],
                    $remoteItemCreate['id'], ['linked_uuid' => $litem['id'], 'revisionDate' => $remoteItemCreate['revisionDate']]
                );
                $stats['to_remote']['created']++;
            } else {
                $stats['to_remote']['failed']++;
            }
            continue;
        }

        $localStoredItem = $redis->get($litem['id']);

        if (strpos($litem['name'], '[DELETE]') !== false) {
            if ($local->deleteItem($litem['id'])) {
                $redis->del($litem['id']);
                if ($remote->deleteItem($localStoredItem['linked_uuid'])) {
                    $redis->del($localStoredItem['linked_uuid']);
                    $stats['to_remote']['deleted']++;
                } else {
                    $stats['to_remote']['failed']++;
                }
            } else {
                $stats['to_remote']['failed']++;
            }
            continue;
        }

        if ($litem['revisionDate'] != $localStoredItem['revisionDate']) {

            $collectionIds = [];
            foreach ($litem['collectionIds'] as $lcId) {
                if ($rcId = $redis->get($lcId)) {
                    $collectionIds[] = $redis->get($lcId);
                }
            }
            $fields = (isset($litem['fields'])) ? $litem['fields'] : [];

            $remoteItemUpdate = $remote->updateItem(
                $localStoredItem['linked_uuid'],
                $config['BW_REMOTE']['ORGANIZATION_ID'],
                $litem['name'],
                $collectionIds,
                $litem['type'],
                $litem['login'],
                $litem['notes'],
                $fields,
                $litem['reprompt'] ?? 0
            );

            if ($remoteItemUpdate) {
                Helper::link(
                    $redis,
                    $litem['id'], ['linked_uuid' => $remoteItemUpdate['id'], 'revisionDate' => $litem['revisionDate']],
                    $remoteItemUpdate['id'], ['linked_uuid' => $litem['id'], 'revisionDate' => $remoteItemUpdate['revisionDate']]
                );
                $stats['to_remote']['updated']++;
            } else {
                $stats['to_remote']['failed']++;
            }
        }

    }

    $stats['to_remote']['skipped'] = array_sum($localSkipped);
    Helper::reportSkipped('BW_LOCAL', $localSkipped);

    $remoteSkipped = [];
    foreach ($remote->getItems($config['BW_REMOTE']['ORGANIZATION_ID']) ?: [] as $ritem) {

        if (!strlen($ritem['name'])) {
            continue;
        }

        if (!Helper::isSyncable($ritem)) {
            Helper::countSkipped($remoteSkipped, $ritem);
            continue;
        }

        if (!$localItem = $redis->get($ritem['id'])) {
            $collectionIds = [];
            foreach ($ritem['collectionIds'] as $rcId) {
                if ($lcId = $redis->get($rcId)) {
                    $collectionIds[] = $lcId;
                }
            }
            $fields = (isset($ritem['fields'])) ? $ritem['fields'] : [];

            $localItemCreate = $local->createItem(
                $config['BW_LOCAL']['ORGANIZATION_ID'],
                $ritem['name'],
                $collectionIds,
                $ritem['type'],
                $ritem['login'],
                $ritem['notes'],
                $fields,
                $ritem['reprompt'] ?? 0
            );
            if ($localItemCreate) {
                Helper::link(
                    $redis,
                    $ritem['id'], ['linked_uuid' => $localItemCreate['id'], 'revisionDate' => $ritem['revisionDate']],
                    $localItemCreate['id'], ['linked_uuid' => $ritem['id'], 'revisionDate' => $localItemCreate['revisionDate']]
                );
                $stats['to_local']['created']++;
            } else {
                $stats['to_local']['failed']++;
            }
            continue;
        }

        $remoteStoredItem = $redis->get($ritem['id']);

        if (strpos($ritem['name'], '[DELETE]') !== false) {
            if ($remote->deleteItem($ritem['id'])) {
                $redis->del($ritem['id']);
                if ($local->deleteItem($remoteStoredItem['linked_uuid'])) {
                    $redis->del($remoteStoredItem['linked_uuid']);
                    $stats['to_local']['deleted']++;
                } else {
                    $stats['to_local']['failed']++;
                }
            } else {
                $stats['to_local']['failed']++;
            }
            continue;
        }


        if ($ritem['revisionDate'] != $remoteStoredItem['revisionDate']) {

            $collectionIds = [];
            foreach ($ritem['collectionIds'] as $rcId) {
                if ($lcId = $redis->get($rcId)) {
                    $collectionIds[] = $lcId;
                }
            }

            $fields = (isset($ritem['fields'])) ? $ritem['fields'] : [];

            $localItemUpdate = $local->updateItem(
                $remoteStoredItem['linked_uuid'],
                $config['BW_LOCAL']['ORGANIZATION_ID'],
                $ritem['name'],
                $collectionIds,
                $ritem['type'],
                $ritem['login'],
                $ritem['notes'],
                $fields,
                $ritem['reprompt'] ?? 0
            );

            if ($localItemUpdate) {
                Helper::link(
                    $redis,
                    $ritem['id'], ['linked_uuid' => $localItemUpdate['id'], 'revisionDate' => $ritem['revisionDate']],
                    $localItemUpdate['id'], ['linked_uuid' => $ritem['id'], 'revisionDate' => $localItemUpdate['revisionDate']]
                );
                $stats['to_local']['updated']++;
            } else {
                $stats['to_local']['failed']++;
            }
        }

    }

    $stats['to_local']['skipped'] = array_sum($remoteSkipped);
    Helper::reportSkipped('BW_REMOTE', $remoteSkipped);
};

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

        $checkChildren();

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
            $checkChildren();
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
            $checkChildren();
        }

        foreach (['BW_LOCAL' => $local, 'BW_REMOTE' => $remote] as $name => $api) {
            $vault = $api->vaultStatus();
            if ($vault !== false && $vault !== 'unlocked') {
                $locked[] = $name;
                Logger::error("$name vault is not unlocked (status: $vault)");
            }
        }

        if (!count($locked)) {
            $syncCycle();
        }

    } catch (\Throwable $e) {
        $exception = Helper::describeException($e);
        Logger::error('Synchronization cycle failed: ' . $exception);
        Logger::debug($e->getTraceAsString());
    }

    $errors = $local->getErrorCount() + $remote->getErrorCount() + count($locked) + (strlen($exception) ? 1 : 0);
    $summary = Helper::summary($cycle, $stats, microtime(true) - $started, $errors);
    if ($errors) {
        Logger::warning($summary);
    } else {
        Logger::info($summary);
    }

    $failedCycles = $errors ? $failedCycles + 1 : 0;
    $lockedCycles = count($locked) ? $lockedCycles + 1 : 0;

    $exitAfter = (int) ($config['LOCKED_EXIT_CYCLES'] ?? 3);
    if ($exitAfter > 0 && $lockedCycles >= $exitAfter) {
        $bwmanager->exit('Error: ' . implode(' and ', $locked) . " vault has been locked for $lockedCycles cycles in a row. Script stopped, restart it to log in again.");
    }

    $alertAfter = (int) ($config['FAIL_ALERT_CYCLES'] ?? 3);
    if ($alertAfter > 0 && $failedCycles >= $alertAfter && !$alerted) {
        $lastError = current(array_filter([$exception, $local->getLastError(), $remote->getLastError(), count($locked) ? implode(' and ', $locked) . ' vault is not unlocked' : '']));
        //not marked as sent when the notification failed, so it is tried again in the next cycle
        $alerted = $notification->send("Synchronization has failed in $failedCycles cycles in a row. Last error: $lastError");
    } elseif (!$errors && $alerted) {
        $notification->send('Synchronization has recovered.');
        $alerted = false;
    }

    Logger::debug('Sleep for ' . $config['SYNC_INTERVAL'] . ' sec.');
    sleep($config['SYNC_INTERVAL']);
}
