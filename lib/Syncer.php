<?php
namespace BitwardenOrgSync;

/**
 * One synchronization cycle between two organizations.
 *
 * Every item is processed together with its counterpart (a pair), so a change made on both servers is noticed as a conflict
 * instead of being overwritten silently. Redis keeps the links: UUID => ['linked_uuid', 'revisionDate', 'collections'].
 */
class Syncer {

    private const OTHER = ['local' => 'remote', 'remote' => 'local'];
    private const MAX_CONSECUTIVE_FAILURES = 20;
    private const CONFLICT_COPY_TTL = 2592000; //30 days

    private $redis;
    private $sides;
    private $policy;
    private $syncMembership;
    private $forceInitial;
    private $dryRun;
    private $stats;
    private $colIds = ['local' => [], 'remote' => []]; //collection ids that exist on each server
    private $colMap = []; //collection id => id of the linked collection on the other server
    private $virtualLinks = []; //collections that a dry run pretends to have created or linked
    private $missing = ['local' => [], 'remote' => []]; //linked items whose counterpart is not there
    private $reportedMissing = ['local' => '', 'remote' => ''];
    private $warned = [];
    private $failures = 0;

    public function __construct($redis, ApiInterface $local, ApiInterface $remote, array $config, bool $dryRun = false) {
        $this->redis = $redis;
        $this->sides = [
            'local' => ['name' => 'BW_LOCAL', 'api' => $local, 'org' => $config['BW_LOCAL']['ORGANIZATION_ID']],
            'remote' => ['name' => 'BW_REMOTE', 'api' => $remote, 'org' => $config['BW_REMOTE']['ORGANIZATION_ID']]
        ];
        $policy = strtolower((string) ($config['CONFLICT_POLICY'] ?? 'newest'));
        if (!in_array($policy, ['newest', 'local', 'remote', 'skip'], true)) {
            Logger::warning("Unknown CONFLICT_POLICY '$policy', 'newest' is used");
            $policy = 'newest';
        }
        $this->policy = $policy;
        $this->syncMembership = (bool) ($config['SYNC_COLLECTION_MEMBERSHIP'] ?? true);
        $this->forceInitial = (bool) ($config['FORCE_INITIAL_SYNC'] ?? false);
        $this->dryRun = $dryRun;
        $this->stats = Helper::newStats();
    }

    public function run() {
        $this->stats = Helper::newStats();
        $this->virtualLinks = [];
        $data = $this->load();
        if ($data === null) {
            return $this->stats;
        }
        [$collections, $items] = $data;
        if (!$this->initialSyncAllowed($collections, $items)) {
            return $this->stats;
        }
        $this->syncCollections($collections);
        $this->indexCollections($collections);
        $this->syncItems($items);
        return $this->stats;
    }

    /**
     * Links the collections and items that already exist on both servers (same name, for items also the same user name)
     * and creates nothing. Used after a loss of the Redis database or when both organizations already contain data.
     */
    public function rebuildMapping() {
        $this->stats = Helper::newStats();
        $this->virtualLinks = [];
        $data = $this->load();
        if ($data === null) {
            return $this->stats;
        }
        [$collections, $items] = $data;
        $report = ['collections' => 0, 'items' => 0, 'differ' => [], 'ambiguous' => 0];

        $free = [];
        foreach ($collections['remote'] as $collection) {
            if ($this->redis->get($collection['id']) === false) {
                $free[$collection['name']][] = $collection;
            }
        }
        foreach ($collections['local'] as $collection) {
            if ($this->redis->get($collection['id']) !== false || empty($free[$collection['name']])) {
                continue;
            }
            $match = array_shift($free[$collection['name']]);
            $this->linkCollections($collection['id'], $match['id']);
            $report['collections']++;
        }
        $this->indexCollections($collections);

        $free = [];
        foreach ($items['remote'] as $item) {
            if (Helper::isSyncable($item) && strlen((string) $item['name']) && $this->redis->get($item['id']) === false) {
                $free[Helper::identityKey($item)][] = $item;
            }
        }
        $localOnly = 0;
        foreach ($items['local'] as $item) {
            if (!Helper::isSyncable($item) || !strlen((string) $item['name']) || $this->redis->get($item['id']) !== false) {
                continue;
            }
            $key = Helper::identityKey($item);
            $candidates = $free[$key] ?? [];
            if (count($candidates) > 1) {
                $same = array_values(array_filter($candidates, function ($candidate) use ($item) {
                    return Helper::fingerprint($candidate) === Helper::fingerprint($item);
                }));
                $candidates = (count($same) == 1) ? $same : $candidates;
            }
            if (count($candidates) == 0) {
                $localOnly++;
                continue;
            }
            if (count($candidates) > 1) {
                $report['ambiguous']++;
                Logger::warning("{$this->sides['local']['name']}: item {$item['id']} matches " . count($candidates) . " items of {$this->sides['remote']['name']}, it is not linked");
                continue;
            }
            $match = $candidates[0];
            $free[$key] = array_values(array_filter($free[$key], function ($candidate) use ($match) {
                return $candidate['id'] !== $match['id'];
            }));
            $baseline = $this->membershipsEqual($item, $match) ? $this->localView($item['collectionIds'] ?? [], 'local') : null;
            $this->link($item, $match, $baseline);
            $report['items']++;
            if (Helper::fingerprint($item) !== Helper::fingerprint($match)) {
                $report['differ'][] = $item['id'] . ' / ' . $match['id'];
            }
        }
        $remoteOnly = 0;
        foreach ($free as $rest) {
            $remoteOnly += count($rest);
        }

        $prefix = $this->dryRun ? '[dry-run] would link' : 'Linked';
        Logger::info("$prefix {$report['collections']} collection(s) and {$report['items']} item(s) that exist on both servers");
        if (count($report['differ'])) {
            Logger::warning(count($report['differ']) . ' linked item(s) differ in content (BW_LOCAL id / BW_REMOTE id), they are synchronized after the next change of either of them: ' . implode(', ', array_slice($report['differ'], 0, 20)) . (count($report['differ']) > 20 ? ', ...' : ''));
        }
        Logger::info("$localOnly item(s) of {$this->sides['local']['name']} and $remoteOnly item(s) of {$this->sides['remote']['name']} have no counterpart, the regular synchronization creates them");
        $this->stats['rebuild'] = $report;
        return $this->stats;
    }

    private function abort(string $reason) {
        $this->stats['aborted'] = $reason;
        Logger::error("$reason, the cycle is skipped");
        return null;
    }

    private function load() {
        $collections = [];
        $items = [];
        foreach ($this->sides as $key => $side) {
            $collections[$key] = $side['api']->getOrgItems($side['org']);
            if ($collections[$key] === false) {
                return $this->abort("{$side['name']}: the collections could not be read");
            }
        }
        foreach ($this->sides as $key => $side) {
            $items[$key] = $side['api']->getItems($side['org']);
            if ($items[$key] === false) {
                return $this->abort("{$side['name']}: the items could not be read");
            }
        }
        return [$collections, $items];
    }

    //Both organizations contain data, but there is not a single link: Redis was lost (or this is a first run with a non-empty destination)
    private function initialSyncAllowed(array $collections, array $items) {
        if ($this->forceInitial) {
            return true;
        }
        $counts = [];
        foreach ($this->sides as $key => $side) {
            $counts[$key] = [count($collections[$key]), count(array_filter($items[$key], [Helper::class, 'isSyncable']))];
        }
        if (array_sum($counts['local']) == 0 || array_sum($counts['remote']) == 0) {
            return true;
        }
        foreach ($this->sides as $key => $side) {
            foreach ($collections[$key] as $collection) {
                if ($this->redis->get($collection['id']) !== false) {
                    return true;
                }
            }
            foreach ($items[$key] as $item) {
                if (Helper::isSyncable($item) && $this->redis->get($item['id']) !== false) {
                    return true;
                }
            }
        }
        $this->stats['errors']++;
        $this->stats['aborted'] = 'no links in Redis';
        Logger::error(
            'Redis contains no links between the servers, but both organizations already have data ('
            . $this->sides['local']['name'] . ': ' . $counts['local'][0] . ' collection(s), ' . $counts['local'][1] . ' item(s); '
            . $this->sides['remote']['name'] . ': ' . $counts['remote'][0] . ' collection(s), ' . $counts['remote'][1] . ' item(s)). '
            . 'Creating counterparts now would duplicate everything. If Redis was lost or the servers already contain the same data, run "php run.php --rebuild-mapping" to link the existing items. '
            . 'To start from scratch anyway set $config[\'FORCE_INITIAL_SYNC\'] = true. The cycle is skipped'
        );
        return false;
    }

    private function syncCollections(array &$collections) {
        foreach (self::OTHER as $from => $to) {
            foreach ($collections[$from] as $collection) {
                if ($this->redis->get($collection['id']) !== false || isset($this->virtualLinks[$collection['id']])) {
                    continue;
                }
                $created = $this->apiCreateCollection($to, $collection['name']);
                if (!$created) {
                    continue;
                }
                $this->linkCollections($collection['id'], $created['id']);
                $collections[$to][] = $created;
                $this->stats['collections']['to_' . $to]++;
            }
        }
    }

    private function linkCollections(string $idA, string $idB) {
        if ($this->dryRun) {
            $this->virtualLinks[$idA] = $idB;
            $this->virtualLinks[$idB] = $idA;
            return;
        }
        Helper::link($this->redis, $idA, $idB, $idB, $idA);
    }

    private function indexCollections(array $collections) {
        $this->colIds = ['local' => [], 'remote' => []];
        $this->colMap = [];
        foreach ($this->sides as $key => $side) {
            foreach ($collections[$key] as $collection) {
                $this->colIds[$key][$collection['id']] = true;
                $linked = $this->redis->get($collection['id']);
                if ($linked === false) {
                    $linked = $this->virtualLinks[$collection['id']] ?? null;
                }
                $this->colMap[$collection['id']] = is_string($linked) ? $linked : null;
            }
        }
    }

    //Collection ids of one server translated to the ids of the other one (only collections that are linked and still exist)
    private function translate(array $ids, string $to) {
        $result = [];
        foreach ($ids as $id) {
            $linked = $this->colMap[$id] ?? null;
            if ($linked === null) {
                continue;
            }
            if (isset($this->colIds[$to][$linked])) {
                $result[] = $linked;
            } else {
                $this->warnOnce('stale:' . $id, "Collection $id is linked to $linked, which does not exist on {$this->sides[$to]['name']} any more, it is ignored");
            }
        }
        return $result;
    }

    //Collections of an item as a sorted list of the ids of the LOCAL server, so that both servers can be compared
    private function localView(array $ids, string $key) {
        $view = [];
        foreach ($ids as $id) {
            $linked = $this->colMap[$id] ?? null;
            if ($linked === null) {
                continue;
            }
            if ($key === 'local' && isset($this->colIds['remote'][$linked])) {
                $view[] = $id;
            } elseif ($key === 'remote' && isset($this->colIds['local'][$linked])) {
                $view[] = $linked;
            }
        }
        $view = array_values(array_unique($view));
        sort($view);
        return $view;
    }

    private function membershipsEqual(array $local, array $remote) {
        return $this->localView($local['collectionIds'] ?? [], 'local') === $this->localView($remote['collectionIds'] ?? [], 'remote');
    }

    private function syncItems(array $items) {
        $byId = ['local' => [], 'remote' => []];
        foreach ($items as $key => $list) {
            foreach ($list as $item) {
                $byId[$key][$item['id']] = $item;
            }
        }
        $visited = [];
        $this->missing = ['local' => [], 'remote' => []];
        foreach (self::OTHER as $key => $other) {
            $skipped = [];
            foreach ($items[$key] as $item) {
                if (!strlen((string) $item['name'])) {
                    continue;
                }
                if (!Helper::isSyncable($item)) {
                    Helper::countSkipped($skipped, $item);
                    continue;
                }
                if (isset($visited[$item['id']])) {
                    continue;
                }
                try {
                    $this->processItem($key, $item, $byId[$other], $visited);
                    $this->failures = 0;
                } catch (\Throwable $e) {
                    $this->itemFailed($key, $item, $e);
                }
            }
            $this->stats['to_' . $other]['skipped'] = array_sum($skipped);
            Helper::reportSkipped($this->sides[$key]['name'], $skipped);
            $this->reportMissing($key);
        }
    }

    //One warning for all linked items whose counterpart is gone, repeated (as a warning) only when the set changes
    private function reportMissing(string $key) {
        $list = $this->missing[$key];
        $fingerprint = implode(',', $list);
        $changed = ($this->reportedMissing[$key] !== $fingerprint);
        $this->reportedMissing[$key] = $fingerprint;
        if (!count($list)) {
            return;
        }
        $other = self::OTHER[$key];
        $message = count($list) . " item(s) of {$this->sides[$key]['name']} are linked to items that are not on {$this->sides[$other]['name']} (deleted, in the trash or not visible), their changes are not synchronized. "
            . 'Restore the counterparts from the trash or mark the items with [DELETE] (this item -> its counterpart): ' . implode(', ', array_slice($list, 0, 10)) . (count($list) > 10 ? ', ...' : '');
        if ($changed) {
            Logger::warning($message);
        } else {
            Logger::debug($message);
        }
    }

    private function itemFailed(string $key, array $item, \Throwable $e) {
        $this->stats['to_' . self::OTHER[$key]]['failed']++;
        $this->stats['errors']++;
        Logger::error("{$this->sides[$key]['name']}: item {$item['id']} could not be processed: " . Helper::describeException($e));
        if (++$this->failures >= self::MAX_CONSECUTIVE_FAILURES) {
            throw new \RuntimeException('Too many items in a row could not be processed, the cycle is aborted. The last error: ' . $e->getMessage(), 0, $e);
        }
    }

    private function processItem(string $key, array $item, array $otherItems, array &$visited) {
        $visited[$item['id']] = true;
        $link = $this->redis->get($item['id']);
        if (!is_array($link) || !isset($link['linked_uuid'])) {
            $this->processUnlinked($key, $item);
            return;
        }
        $otherId = $link['linked_uuid'];
        $visited[$otherId] = true;
        $counterpart = $otherItems[$otherId] ?? null;
        if ($counterpart === null || !Helper::isSyncable($counterpart)) {
            $this->processMissing($key, $item, $otherId);
            return;
        }
        if ($key === 'local') {
            $this->processPair($item, $counterpart);
        } else {
            $this->processPair($counterpart, $item);
        }
    }

    //An item that was never synchronized
    private function processUnlinked(string $key, array $item) {
        $other = self::OTHER[$key];
        if (Helper::hasDeleteMarker($item['name'])) {
            //there is no counterpart to delete
            $this->stats['to_' . $other][$this->apiDelete($key, $item) ? 'deleted' : 'failed']++;
            return;
        }
        $collectionIds = $this->translate($item['collectionIds'] ?? [], $other);
        if (!count($collectionIds)) {
            $this->warnOnce('nocollection:' . $item['id'], "Item {$item['id']} of {$this->sides[$key]['name']} is not created on {$this->sides[$other]['name']}: none of its collections exists there");
            $this->stats['to_' . $other]['failed']++;
            return;
        }
        $created = $this->apiCreate($other, $item, $collectionIds);
        if (!$created) {
            $this->stats['to_' . $other]['failed']++;
            return;
        }
        $baseline = $this->syncMembership ? $this->localView($item['collectionIds'] ?? [], $key) : null;
        if ($key === 'local') {
            $this->link($item, $created, $baseline);
        } else {
            $this->link($created, $item, $baseline);
        }
        $this->stats['to_' . $other]['created']++;
    }

    //The counterpart of a linked item is not there: it was deleted or moved to the trash, or the API user cannot see it
    private function processMissing(string $key, array $item, string $otherId) {
        $other = self::OTHER[$key];
        if (Helper::hasDeleteMarker($item['name'])) {
            if ($this->apiDelete($key, $item)) {
                if (!$this->dryRun) {
                    $this->redis->del([$item['id'], $otherId]);
                }
                $this->stats['to_' . $other]['deleted']++;
            } else {
                $this->stats['to_' . $other]['failed']++;
            }
            return;
        }
        $this->missing[$key][] = $item['id'] . ' -> ' . $otherId;
    }

    private function processPair(array $l, array $r) {
        $linkL = $this->redis->get($l['id']);
        $linkR = $this->redis->get($r['id']);
        if (is_array($linkR) && ($linkR['linked_uuid'] ?? null) !== $l['id']) {
            $this->warnOnce('inconsistent:' . $l['id'], "Items {$l['id']} and {$r['id']} are linked inconsistently in Redis, they are skipped");
            return;
        }
        if (Helper::hasDeleteMarker($l['name']) || Helper::hasDeleteMarker($r['name'])) {
            $this->deletePair($l, $r);
            return;
        }

        $s = ['l' => $l, 'r' => $r, 'baseline' => $linkL['collections'] ?? ($linkR['collections'] ?? null)];
        $changedL = $l['revisionDate'] != ($linkL['revisionDate'] ?? null);
        $changedR = $r['revisionDate'] != (is_array($linkR) ? ($linkR['revisionDate'] ?? null) : $r['revisionDate']);

        if ($changedL || $changedR) {
            if (!$this->reconcileContent($s, $changedL, $changedR)) {
                return;
            }
        } elseif (!is_array($linkR)) {
            $this->link($s['l'], $s['r'], $s['baseline']); //restores the missing reverse link
        }

        if ($this->syncMembership) {
            $this->reconcileMembership($s);
        }
    }

    private function reconcileContent(array &$s, bool $changedL, bool $changedR) {
        if (Helper::fingerprint($s['l']) === Helper::fingerprint($s['r'])) {
            //only the revision date differs: nothing to copy, just remember the new revisions
            $this->link($s['l'], $s['r'], $s['baseline']);
            return true;
        }
        if ($changedL && !$changedR) {
            return $this->push($s, 'local');
        }
        if ($changedR && !$changedL) {
            return $this->push($s, 'remote');
        }
        return $this->resolveConflict($s);
    }

    //Copies the content of the item of server $from to its counterpart
    private function push(array &$s, string $from) {
        $to = self::OTHER[$from];
        $src = ($from === 'local') ? $s['l'] : $s['r'];
        $dst = ($from === 'local') ? $s['r'] : $s['l'];
        $updated = $this->apiUpdate($to, $dst, $src, $this->translate($src['collectionIds'] ?? [], $to));
        if (!$updated) {
            $this->stats['to_' . $to]['failed']++;
            return false;
        }
        $s[($to === 'local') ? 'l' : 'r']['revisionDate'] = $updated['revisionDate'];
        $this->link($s['l'], $s['r'], $s['baseline']);
        $this->stats['to_' . $to]['updated']++;
        return true;
    }

    private function resolveConflict(array &$s) {
        $l = $s['l'];
        $r = $s['r'];
        $this->stats['conflicts']++;
        $label = "{$l['id']} ({$this->sides['local']['name']}) / {$r['id']} ({$this->sides['remote']['name']})";
        if ($this->policy === 'skip') {
            Logger::warning("Conflict: item $label was changed on both servers and is skipped (CONFLICT_POLICY is skip)");
            $this->stats['errors']++;
            return false;
        }
        if ($this->policy === 'newest') {
            $winner = Helper::isNewer($r['revisionDate'], $l['revisionDate']) ? 'remote' : 'local';
        } else {
            $winner = $this->policy;
        }
        $loser = self::OTHER[$winner];
        Logger::warning("Conflict: item $label was changed on both servers, the version of {$this->sides[$winner]['name']} wins"
            . ($this->policy === 'newest' ? ' (the newer one), the other version is kept as a copy' : ''));
        if ($this->policy === 'newest' && !$this->keepCopy($loser, ($loser === 'local') ? $l : $r)) {
            return false; //never overwrite a version that could not be saved
        }
        return $this->push($s, $winner);
    }

    //Saves the version that is about to be overwritten as a new item of the same server (it is then synchronized like any new item)
    private function keepCopy(string $key, array $item) {
        $marker = 'conflict:' . $item['id'] . ':' . $item['revisionDate'];
        if ($this->redis->get($marker) !== false) {
            return true; //saved by an earlier attempt that failed later
        }
        $name = $item['name'] . ' (conflict copy ' . gmdate('Y-m-d H:i') . ')';
        $copy = $this->apiCreate($key, $item, $item['collectionIds'] ?? [], $name);
        if (!$copy) {
            $this->stats['to_' . self::OTHER[$key]]['failed']++;
            return false;
        }
        if (!$this->dryRun) {
            $this->redis->set($marker, $copy['id'], ['ex' => self::CONFLICT_COPY_TTL]);
        }
        Logger::warning("The overwritten version of item {$item['id']} is kept on {$this->sides[$key]['name']} as item {$copy['id']}");
        return true;
    }

    //Deletes both items. The counterpart of the marked item goes first: if the second deletion fails, the marker is still there and the next cycle repeats it.
    private function deletePair(array $l, array $r) {
        $markedKey = Helper::hasDeleteMarker($l['name']) ? 'local' : 'remote';
        $order = ($markedKey === 'local') ? ['remote' => $r, 'local' => $l] : ['local' => $l, 'remote' => $r];
        foreach ($order as $key => $item) {
            if (!$this->apiDelete($key, $item)) {
                $this->stats['to_' . self::OTHER[$markedKey]]['failed']++;
                return;
            }
        }
        if (!$this->dryRun) {
            $this->redis->del([$l['id'], $r['id']]);
        }
        $this->stats['to_' . self::OTHER[$markedKey]]['deleted']++;
    }

    //Collections of an item are compared with the state remembered after the last synchronization to find out which server changed them
    private function reconcileMembership(array &$s) {
        $cl = $this->localView($s['l']['collectionIds'] ?? [], 'local');
        $cr = $this->localView($s['r']['collectionIds'] ?? [], 'remote');
        $base = $s['baseline'];
        if ($cl === $cr) {
            if ($base !== $cl) {
                $s['baseline'] = $cl;
                $this->link($s['l'], $s['r'], $cl);
            }
            return;
        }
        if ($base === null) {
            $this->warnOnce('nobase:' . $s['l']['id'], "Item {$s['l']['id']}: the collections differ between the servers and the previous state is unknown, align them manually, later changes are synchronized");
            return;
        }
        $changedL = ($cl !== $base);
        $changedR = ($cr !== $base);
        if ($changedL && $changedR) {
            if ($this->policy === 'skip') {
                $this->warnOnce('memconflict:' . $s['l']['id'], "Item {$s['l']['id']}: the collections were changed on both servers, skipped (CONFLICT_POLICY is skip)");
                $this->stats['errors']++;
                return;
            }
            $this->stats['conflicts']++;
            if ($this->policy === 'newest') {
                $from = Helper::isNewer($s['r']['revisionDate'], $s['l']['revisionDate']) ? 'remote' : 'local';
            } else {
                $from = $this->policy;
            }
        } else {
            $from = $changedL ? 'local' : 'remote';
        }
        $this->applyMembership($s, $from, ($from === 'local') ? $cl : $cr);
    }

    private function applyMembership(array &$s, string $from, array $localView) {
        $to = self::OTHER[$from];
        if (!count($localView)) {
            $this->warnOnce('emptymem:' . $s['l']['id'], "Item {$s['l']['id']}: an item cannot be left without collections, the change is not synchronized");
            return;
        }
        $ids = ($to === 'remote') ? array_map(function ($id) {
            return $this->colMap[$id];
        }, $localView) : $localView;
        $dst = ($to === 'remote') ? $s['r'] : $s['l'];
        $result = $this->apiSetCollections($to, $dst, $ids);
        if (!$result) {
            $this->stats['to_' . $to]['failed']++;
            return;
        }
        if (is_array($result) && isset($result['revisionDate'])) {
            $s[($to === 'remote') ? 'r' : 'l']['revisionDate'] = $result['revisionDate'];
        }
        $s['baseline'] = $localView;
        $this->link($s['l'], $s['r'], $localView);
        $this->stats['memberships']++;
    }

    private function link(array $l, array $r, $collections = null) {
        if ($this->dryRun) {
            return;
        }
        $valueL = ['linked_uuid' => $r['id'], 'revisionDate' => $l['revisionDate']];
        $valueR = ['linked_uuid' => $l['id'], 'revisionDate' => $r['revisionDate']];
        if ($collections !== null) {
            $valueL['collections'] = $collections;
            $valueR['collections'] = $collections;
        }
        Helper::link($this->redis, $l['id'], $valueL, $r['id'], $valueR);
    }

    private function warnOnce(string $key, string $message) {
        if (isset($this->warned[$key])) {
            Logger::debug($message);
            return;
        }
        $this->warned[$key] = true;
        Logger::warning($message);
    }

    //Every change of a server goes through the following methods, so that --dry-run can leave the servers untouched

    private function apiCreateCollection(string $to, string $name) {
        $side = $this->sides[$to];
        if ($this->dryRun) {
            Logger::info("[dry-run] would create collection '$name' in {$side['name']}");
            return ['id' => "dry-run:$to:$name", 'name' => $name];
        }
        return $side['api']->createOrgCollection($side['org'], $name);
    }

    private function apiCreate(string $to, array $item, array $collectionIds, ?string $name = null) {
        $side = $this->sides[$to];
        $name = $name ?? $item['name'];
        if ($this->dryRun) {
            Logger::info("[dry-run] would create item '$name' in {$side['name']}");
            return ['id' => 'dry-run', 'revisionDate' => ''];
        }
        return $side['api']->createItem($side['org'], $name, $collectionIds, $item['type'], $item['login'], $item['notes'] ?? null, $item['fields'] ?? [], $item['reprompt'] ?? 0);
    }

    private function apiUpdate(string $to, array $dst, array $src, array $collectionIds) {
        $side = $this->sides[$to];
        if ($this->dryRun) {
            Logger::info("[dry-run] would update item {$dst['id']} ('{$src['name']}') in {$side['name']}");
            return ['id' => $dst['id'], 'revisionDate' => $dst['revisionDate']];
        }
        return $side['api']->updateItem($dst['id'], $side['org'], $src['name'], $collectionIds, $src['type'], $src['login'], $src['notes'] ?? null, $src['fields'] ?? [], $src['reprompt'] ?? 0);
    }

    private function apiDelete(string $key, array $item) {
        $side = $this->sides[$key];
        if ($this->dryRun) {
            Logger::info("[dry-run] would delete item {$item['id']} ('{$item['name']}') in {$side['name']}");
            return true;
        }
        return $side['api']->deleteItem($item['id']);
    }

    private function apiSetCollections(string $to, array $dst, array $collectionIds) {
        $side = $this->sides[$to];
        if ($this->dryRun) {
            Logger::info("[dry-run] would set the collections of item {$dst['id']} in {$side['name']} to " . implode(', ', $collectionIds));
            return ['revisionDate' => $dst['revisionDate']];
        }
        return $side['api']->setItemCollections($dst['id'], $side['org'], $collectionIds);
    }

}
