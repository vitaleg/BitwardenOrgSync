<?php
require 'config.php';
require 'vendor/autoload.php';

foreach(glob(__DIR__ . "/lib/*.php") as $libFile) {
    require $libFile;
}

use BitwardenOrgSync\Helper;

$redis = new \Redis();
$redis->connect($config['REDIS']['ADDRESS'], $config['REDIS']['PORT']);
if ($config['REDIS']['USERNAME']) {
    $redis->auth([$config['REDIS']['USERNAME'], $config['REDIS']['PASSWORD']]);
} else if ($config['REDIS']['PASSWORD']) {
    $redis->auth($config['REDIS']['PASSWORD']);
}
if ($config['REDIS']['DATABASE_ID'] > 0) {
    $redis->swapdb($config['REDIS']['DATABASE_ID']);
}
$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

//Init API Servers
$bwmanager = new \BitwardenOrgSync\BWSync($config);

$bwmanager->serveBackground('BW_LOCAL');
$bwmanager->serveBackground('BW_REMOTE');

//Init API Clients
$local = new \BitwardenOrgSync\ApiClient('http://[::1]:' . $config['BW_LOCAL']['SERVE_PORT']);
$remote = new \BitwardenOrgSync\ApiClient('http://[::1]:' . $config['BW_REMOTE']['SERVE_PORT']);


while (true) {

    while (true) {
        $pid = pcntl_waitpid(-1, $status, WNOHANG);
        if ($pid > 0) {
            echo "The process with PID $pid is stopped.\n";
            $bwmanager->exit("Error: The process with PID $pid is stopped.");
        } elseif ($pid == 0) {
            echo "No completed stopped...\n";
            break;
        }
    }

    $localCounter = 0;
    $remoteCounter = 0;

    while (($local->status() === false)) {
        if ($localCounter >= $config['API_SERVER_WAIT_TIMEOUT']) {
            $bwmanager->exit("Error: BW_LOCAL API Server is not ready. Script stopped.");
        }
        $localCounter++;
        echo "Waiting for BW_LOCAL API...\n";
        sleep(1);
    }

    while (($remote->status() === false)) {
        if ($remoteCounter >= $config['API_SERVER_WAIT_TIMEOUT']) {
            $bwmanager->exit("Error: BW_REMOTE API Server is not ready. Script stopped.");
        }
        $remoteCounter++;
        echo "Waiting for BW_REMOTE API...\n";
        sleep(1);
    }

    echo "Local sync...\n";
    $local->sync();
    echo "Remote sync...\n";
    $remote->sync();

    //Sync Collections
    echo "Sync Collections...\n";
    foreach ($local->getOrgItems() as $lc) {
        if (!$redis->get($lc['id'])) {
            if ($rcc = $remote->createOrgCollection($config['BW_REMOTE']['ORGANIZATION_ID'], $lc['name'])) {
                $redis->set($lc['id'], $rcc['id']);
                $redis->set($rcc['id'], $lc['id']);
            }
        }
    }

    foreach ($remote->getOrgItems() as $rc) {
        if (!$redis->get($rc['id'])) {
            if ($lcc = $local->createOrgCollection($config['BW_LOCAL']['ORGANIZATION_ID'], $rc['name'])) {
                $redis->set($rc['id'], $lcc['id']);
                $redis->set($lcc['id'], $rc['id']);
            }
        }
    }


    //Sync Items
    echo "Sync Items...\n";
    foreach ($local->getItems() as $litem) {

        if (!strlen($litem['name'])) {
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
                $fields
            );
            if ($remoteItemCreate) {
                $redis->set($litem['id'], ['linked_uuid' => $remoteItemCreate['id'], 'revisionDate' => $litem['revisionDate']]);
                $redis->set($remoteItemCreate['id'], ['linked_uuid' => $litem['id'], 'revisionDate' => $remoteItemCreate['revisionDate']]);
            }
            continue;
        }

        $localStoredItem = $redis->get($litem['id']);

        if (strpos($litem['name'], '[DELETE]') !== false) {
            if ($local->deleteItem($litem['id'])) {
                $redis->del($litem['id']);
                if ($remote->deleteItem($localStoredItem['linked_uuid'])) {
                    $redis->del($localStoredItem['linked_uuid']);
                }
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
                $fields
            );

            if ($remoteItemUpdate) {
                $redis->set($litem['id'], ['linked_uuid' => $remoteItemUpdate['id'], 'revisionDate' => $litem['revisionDate']]);
                $redis->set($remoteItemUpdate['id'], ['linked_uuid' => $litem['id'], 'revisionDate' => $remoteItemUpdate['revisionDate']]);
            }
        }

    }

    foreach ($remote->getItems() as $ritem) {

        if (!strlen($ritem['name'])) {
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
                $fields
            );
            if ($localItemCreate) {
                $redis->set($ritem['id'], ['linked_uuid' => $localItemCreate['id'], 'revisionDate' => $ritem['revisionDate']]);
                $redis->set($localItemCreate['id'], ['linked_uuid' => $ritem['id'], 'revisionDate' => $localItemCreate['revisionDate']]);
            }
            continue;
        }

        $remoteStoredItem = $redis->get($ritem['id']);

        if (strpos($ritem['name'], '[DELETE]') !== false) {
            if ($remote->deleteItem($ritem['id'])) {
                $redis->del($ritem['id']);
                if ($local->deleteItem($remoteStoredItem['linked_uuid'])) {
                    $redis->del($remoteStoredItem['linked_uuid']);
                }
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
                $fields
            );

            if ($localItemUpdate) {
                $redis->set($ritem['id'], ['linked_uuid' => $localItemUpdate['id'], 'revisionDate' => $ritem['revisionDate']]);
                $redis->set($localItemUpdate['id'], ['linked_uuid' => $ritem['id'], 'revisionDate' => $localItemUpdate['revisionDate']]);
            }
        }

    }

    echo 'Sleep for ' . $config['SYNC_INTERVAL'] . " sec.\n";
    sleep($config['SYNC_INTERVAL']);
}
