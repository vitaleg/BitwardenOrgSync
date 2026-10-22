<?php
use BitwardenOrgSync\Syncer;

function colId(FakeApi $api, string $name) {
    foreach ($api->collections as $collection) {
        if ($collection['name'] === $name) {
            return $collection['id'];
        }
    }
    return null;
}

function itemsLike(FakeApi $api, string $org, string $prefix) {
    return array_values(array_filter($api->items, function ($item) use ($org, $prefix) {
        return $item['organizationId'] === $org && $item['deletedDate'] === null && strpos($item['name'], $prefix) === 0;
    }));
}

//The local server has the collection "Team" with the item "X", both are already copied to the remote server
function synced(array $config = []) {
    [$syncer, $redis, $local, $remote] = env($config);
    $c = $local->addCollection('org-l', 'Team');
    $x = $local->addItem('org-l', 'X', [$c]);
    $syncer->run();
    $x2 = $remote->find('org-r', 'X')['id'];
    return [$syncer, $redis, $local, $remote, $c, $x, $x2];
}

//Both servers have their own data and nothing is linked
function bothHaveData(array $config = [], bool $dryRun = false) {
    [$syncer, $redis, $local, $remote] = env($config, $dryRun);
    $local->addItem('org-l', 'X', [$local->addCollection('org-l', 'Team')]);
    $remote->addItem('org-r', 'X', [$remote->addCollection('org-r', 'Team')]);
    return [$syncer, $redis, $local, $remote];
}

// ---------------------------------------------------------------- create and update

test('creates the counterparts in both directions with collections, reprompt and custom fields', function () {
    [$syncer, $redis, $local, $remote] = env();
    $fields = [['name' => 'f', 'value' => 'v', 'type' => 0]];
    $local->addItem('org-l', 'From local', [$local->addCollection('org-l', 'Team')], ['reprompt' => 1, 'fields' => $fields]);

    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['created']);
    assertSame(1, $stats['collections']['to_remote']);
    $copy = $remote->find('org-r', 'From local');
    assertTrue($copy !== null, 'the item is created on the remote server');
    assertSame(1, $copy['reprompt']);
    assertSame($fields, $copy['fields']);
    assertSame([colId($remote, 'Team')], $copy['collectionIds']);

    $remote->addItem('org-r', 'From remote', [$remote->addCollection('org-r', 'Infra')]);
    $stats = $syncer->run();
    assertSame(1, $stats['to_local']['created']);
    assertSame(1, $stats['collections']['to_local']);
    assertSame([colId($local, 'Infra')], $local->find('org-l', 'From remote')['collectionIds']);
});

test('copies a change in either direction and then stays quiet', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['login' => ['password' => 'from-local'], 'reprompt' => 1]);
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['updated']);
    assertSame('from-local', $remote->item($x2)['login']['password']);
    assertSame(1, $remote->item($x2)['reprompt']);

    $remote->edit($x2, ['notes' => 'from-remote']);
    $stats = $syncer->run();
    assertSame(1, $stats['to_local']['updated']);
    assertSame('from-remote', $local->item($x)['notes']);

    $writes = [$local->writes, $remote->writes, $redis->writes];
    $stats = $syncer->run();
    assertSame($writes, [$local->writes, $remote->writes, $redis->writes], 'nothing is written when nothing changed');
    assertSame(0, $stats['to_remote']['updated'] + $stats['to_local']['updated']);
});

test('items without the optional properties are copied in both directions (bw leaves out what is empty)', function () {
    [$syncer, $redis, $local, $remote] = env();
    $c = $local->addCollection('org-l', 'Team');
    $x = $local->addItem('org-l', 'X', [$c]);
    $local->omit($x, 'notes', 'fields', 'reprompt');

    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['created']);
    assertSame(0, $stats['errors']);
    $x2 = $remote->find('org-r', 'X')['id'];
    assertSame(null, $remote->item($x2)['notes']);

    $writes = [$local->writes, $remote->writes, $redis->writes];
    $remote->omit($x2, 'notes', 'fields', 'reprompt');
    $syncer->run();
    assertSame($writes, [$local->writes, $remote->writes, $redis->writes], 'a missing property is the same as an empty one');

    $local->edit($x, ['login' => ['password' => 'from-local']]);
    $local->omit($x, 'notes', 'fields', 'reprompt');
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['updated']);
    assertSame('from-local', $remote->item($x2)['login']['password']);

    $remote->edit($x2, ['login' => ['password' => 'from-remote']]);
    $remote->omit($x2, 'notes', 'fields', 'reprompt');
    $stats = $syncer->run();
    assertSame(1, $stats['to_local']['updated']);
    assertSame('from-remote', $local->item($x)['login']['password']);
});

test('a conflict copy of an item without the optional properties is created', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['login' => ['password' => 'LOCAL']]);
    $remote->edit($x2, ['login' => ['password' => 'REMOTE']]);
    $local->omit($x, 'notes', 'fields', 'reprompt');
    $remote->omit($x2, 'notes', 'fields', 'reprompt');

    $stats = $syncer->run();
    assertSame(1, $stats['conflicts']);
    assertSame(0, $stats['errors']);
    assertSame(1, count(itemsLike($local, 'org-l', 'X (conflict copy')));
});

test('items with unsupported types and without a name are skipped', function () {
    [$syncer, $redis, $local, $remote] = env();
    $c = $local->addCollection('org-l', 'Team');
    $local->addItem('org-l', 'A note', [$c], ['type' => 2, 'login' => null]);
    $local->addItem('org-l', '', [$c]);
    $local->addItem('org-l', 'Login', [$c]);
    $stats = $syncer->run();
    assertSame(['Login'], $remote->names('org-r'));
    assertSame(1, $stats['to_remote']['skipped']);
});

test('an item whose collections do not exist on the other server is not created', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    unset($remote->collections[colId($remote, 'Team')]); //somebody deleted the collection
    $local->addItem('org-l', 'New', [$c]);
    $writes = $remote->writes;
    $stats = $syncer->run();
    assertSame($writes, $remote->writes, 'no request with an unknown collection');
    assertSame(1, $stats['to_remote']['failed']);
    assertSame(0, $stats['errors']);
});

// ---------------------------------------------------------------- conflicts

test('conflict: the newer version wins and the older one is kept as a copy', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['login' => ['password' => 'LOCAL']]);
    $remote->edit($x2, ['login' => ['password' => 'REMOTE']]);

    $stats = $syncer->run();
    assertSame(1, $stats['conflicts']);
    assertSame(0, $stats['errors']);
    assertSame('REMOTE', $local->item($x)['login']['password']);
    $copies = itemsLike($local, 'org-l', 'X (conflict copy');
    assertSame(1, count($copies));
    assertSame('LOCAL', $copies[0]['login']['password']);

    //the copy is an ordinary new item and reaches the other server in the next cycle
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['created']);
    assertSame(0, $stats['conflicts']);
    assertSame(1, count(itemsLike($remote, 'org-r', 'X (conflict copy')));
});

test('conflict policy local and remote: the chosen server always wins, no copy', function () {
    foreach (['local' => 'LOCAL', 'remote' => 'REMOTE'] as $policy => $expected) {
        [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced(['CONFLICT_POLICY' => $policy]);
        $local->edit($x, ['login' => ['password' => 'LOCAL']]);
        $remote->edit($x2, ['login' => ['password' => 'REMOTE']]);
        $stats = $syncer->run();
        assertSame(1, $stats['conflicts'], $policy);
        assertSame($expected, $local->item($x)['login']['password'], $policy);
        assertSame($expected, $remote->item($x2)['login']['password'], $policy);
        assertSame(0, count(itemsLike($local, 'org-l', 'X (conflict')) + count(itemsLike($remote, 'org-r', 'X (conflict')), "$policy: no copy");
    }
});

test('conflict policy skip: nothing is changed and the conflict is reported as an error every time', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced(['CONFLICT_POLICY' => 'skip']);
    $local->edit($x, ['login' => ['password' => 'LOCAL']]);
    $remote->edit($x2, ['login' => ['password' => 'REMOTE']]);
    foreach ([1, 2] as $run) {
        $stats = $syncer->run();
        assertSame(1, $stats['errors'], "run $run");
        assertSame('LOCAL', $local->item($x)['login']['password']);
        assertSame('REMOTE', $remote->item($x2)['login']['password']);
    }
});

test('the same change on both servers is not a conflict', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['login' => ['password' => 'same']]);
    $remote->edit($x2, ['login' => ['password' => 'same']]);
    $writes = [$local->writes, $remote->writes];
    $stats = $syncer->run();
    assertSame(0, $stats['conflicts']);
    assertSame($writes, [$local->writes, $remote->writes]);
    $redisWrites = $redis->writes;
    $syncer->run();
    assertSame($redisWrites, $redis->writes, 'the new revisions were remembered');
});

test('a changed revision date without a changed content is not copied', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->touch($x);
    $writes = [$local->writes, $remote->writes];
    $stats = $syncer->run();
    assertSame($writes, [$local->writes, $remote->writes]);
    assertSame(0, $stats['to_remote']['updated']);
});

test('the version that is about to be overwritten is saved only once, even when the overwrite has to be repeated', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['login' => ['password' => 'LOCAL']]);
    $remote->edit($x2, ['login' => ['password' => 'REMOTE']]);
    $local->failures['updateItem'] = 1; //the winning version cannot be written once

    $stats = $syncer->run();
    assertSame(1, $stats['to_local']['failed']);
    assertSame(1, count(itemsLike($local, 'org-l', 'X (conflict copy')));
    assertSame('LOCAL', $local->item($x)['login']['password']);

    $syncer->run();
    assertSame('REMOTE', $local->item($x)['login']['password']);
    assertSame(1, count(itemsLike($local, 'org-l', 'X (conflict copy')), 'no second copy');
});

test('no copy, no overwrite when the copy cannot be saved', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['login' => ['password' => 'LOCAL']]);
    $remote->edit($x2, ['login' => ['password' => 'REMOTE']]);
    $local->failures['createItem'] = 1;
    $syncer->run();
    assertSame('LOCAL', $local->item($x)['login']['password'], 'the older version is still there');
});

// ---------------------------------------------------------------- deletion

test('[DELETE] at the start or at the end of the name deletes both items and the links', function () {
    foreach (['[DELETE] X', 'X [DELETE]'] as $name) {
        [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
        $local->edit($x, ['name' => $name]);
        $stats = $syncer->run();
        assertSame(1, $stats['to_remote']['deleted'], $name);
        assertSame([], $local->names('org-l'), $name);
        assertSame([], $remote->names('org-r'), $name);
        assertSame(false, $redis->get($x), "$name: link removed");
        assertSame(false, $redis->get($x2), "$name: link removed");
    }
});

test('the marker in the middle of a name is just a rename', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['name' => 'Docs: what the [DELETE] button does']);
    $syncer->run();
    assertSame(['Docs: what the [DELETE] button does'], $local->names('org-l'));
    assertSame(['Docs: what the [DELETE] button does'], $remote->names('org-r'));
});

test('the marker works on the remote server too', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $remote->edit($x2, ['name' => '[DELETE] X']);
    $stats = $syncer->run();
    assertSame(1, $stats['to_local']['deleted']);
    assertSame([], $local->names('org-l'));
    assertSame([], $remote->names('org-r'));
});

test('if the counterpart cannot be deleted nothing is deleted and the next cycle repeats it', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['name' => '[DELETE] X']);
    $remote->failures['deleteItem'] = 1;
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['failed']);
    assertSame(['[DELETE] X'], $local->names('org-l'), 'the marked item is still there');
    assertTrue($redis->get($x) !== false, 'and still linked');

    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['deleted']);
    assertSame([], $local->names('org-l'));
    assertSame([], $remote->names('org-r'));
});

test('if the marked item cannot be deleted after its counterpart, the next cycle finishes the job', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['name' => '[DELETE] X']);
    $local->failures['deleteItem'] = 1;
    $syncer->run();
    assertSame([], $remote->names('org-r'), 'the counterpart is already gone');
    assertSame(['[DELETE] X'], $local->names('org-l'));

    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['deleted']);
    assertSame([], $local->names('org-l'));
    assertSame(false, $redis->get($x));
});

test('an item with the marker that was never synchronized is deleted and not copied', function () {
    [$syncer, $redis, $local, $remote] = env();
    $local->addItem('org-l', '[DELETE] old', [$local->addCollection('org-l', 'Team')]);
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['deleted']);
    assertSame([], $local->names('org-l'));
    assertSame([], $remote->names('org-r'));
});

test('a counterpart that is gone (deleted or in the trash) is not an error and does not break anything', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $remote->trash($x2);
    $local->edit($x, ['login' => ['password' => 'changed']]);
    $writes = $remote->writes;
    $stats = $syncer->run();
    assertSame(0, $stats['errors']);
    assertSame($writes, $remote->writes, 'no attempt to edit a trashed item');

    //restored from the trash: the pair works again
    $remote->items[$x2]['deletedDate'] = null;
    $stats = $syncer->run();
    assertSame('changed', $remote->item($x2)['login']['password']);
});

test('the marker on an item whose counterpart is gone removes the item and the link', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $remote->trash($x2);
    $local->edit($x, ['name' => 'X [DELETE]']);
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['deleted']);
    assertSame([], $local->names('org-l'));
    assertSame(false, $redis->get($x));
});

// ---------------------------------------------------------------- collections of an item

test('a change of the collections of an item is synchronized in both directions', function () {
    [$syncer, $redis, $local, $remote] = env();
    $a = $local->addCollection('org-l', 'A');
    $b = $local->addCollection('org-l', 'B');
    $x = $local->addItem('org-l', 'X', [$a, $b]);
    $syncer->run();
    $x2 = $remote->find('org-r', 'X')['id'];
    $ra = colId($remote, 'A');
    $rb = colId($remote, 'B');
    assertSame([$ra, $rb], $remote->item($x2)['collectionIds']);

    $local->moveToCollections($x, [$a]); //the revision date of the item does not change
    $stats = $syncer->run();
    assertSame(1, $stats['memberships']);
    assertSame([$ra], $remote->item($x2)['collectionIds']);

    $remote->moveToCollections($x2, [$ra, $rb]);
    $stats = $syncer->run();
    assertSame(1, $stats['memberships']);
    assertSame([$a, $b], $local->item($x)['collectionIds']);

    assertSame(0, $syncer->run()['memberships'], 'stable afterwards');
});

test('collections changed on both servers: the conflict policy decides', function () {
    foreach (['local', 'remote', 'skip'] as $policy) {
        [$syncer, $redis, $local, $remote] = env(['CONFLICT_POLICY' => $policy]);
        $a = $local->addCollection('org-l', 'A');
        $b = $local->addCollection('org-l', 'B');
        $x = $local->addItem('org-l', 'X', [$a, $b]);
        $syncer->run();
        $x2 = $remote->find('org-r', 'X')['id'];
        $ra = colId($remote, 'A');
        $rb = colId($remote, 'B');

        $local->moveToCollections($x, [$a]);
        $remote->moveToCollections($x2, [$rb]);
        $stats = $syncer->run();
        if ($policy === 'local') {
            assertSame([$ra], $remote->item($x2)['collectionIds'], $policy);
            assertSame(1, $stats['conflicts'], $policy);
        } elseif ($policy === 'remote') {
            assertSame([$b], $local->item($x)['collectionIds'], $policy);
            assertSame(1, $stats['conflicts'], $policy);
        } else {
            assertSame([$a], $local->item($x)['collectionIds'], $policy);
            assertSame([$rb], $remote->item($x2)['collectionIds'], $policy);
            assertSame(1, $stats['errors'], $policy);
        }
    }
});

test('links written by an older version get the remembered collections, later changes are recognised', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    foreach ($redis->data as $key => $value) {
        if (is_array($value)) {
            unset($redis->data[$key]['collections']);
        }
    }
    $before = $redis->writes;
    $syncer->run();
    assertTrue($redis->writes > $before, 'the remembered state is written');
    assertSame([$c], $redis->get($x)['collections']);

    $d = $local->addCollection('org-l', 'Second');
    $local->moveToCollections($x, [$c, $d]);
    $stats = $syncer->run();
    assertSame(1, $stats['memberships']);
    assertSame([colId($remote, 'Team'), colId($remote, 'Second')], $remote->item($x2)['collectionIds']);
});

test('differing collections without a remembered state are left alone', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $d = $local->addCollection('org-l', 'Second');
    $syncer->run(); //creates the collection on the remote server
    foreach ($redis->data as $key => $value) {
        if (is_array($value)) {
            unset($redis->data[$key]['collections']);
        }
    }
    $local->moveToCollections($x, [$c, $d]);
    $stats = $syncer->run();
    assertSame(0, $stats['memberships']);
    assertSame(0, $stats['errors']);
    assertSame([colId($remote, 'Team')], $remote->item($x2)['collectionIds']);
});

test('an item is never left without collections', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->moveToCollections($x, []);
    $stats = $syncer->run();
    assertSame(0, $stats['memberships']);
    assertSame([colId($remote, 'Team')], $remote->item($x2)['collectionIds']);
});

test('the synchronization of collections can be switched off', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced(['SYNC_COLLECTION_MEMBERSHIP' => false]);
    $d = $local->addCollection('org-l', 'Second');
    $local->moveToCollections($x, [$c, $d]);
    $stats = $syncer->run();
    assertSame(0, $stats['memberships']);
    assertSame([colId($remote, 'Team')], $remote->item($x2)['collectionIds']);
});

// ---------------------------------------------------------------- Redis lost

test('refuses to create everything twice when Redis has no links but both servers have data', function () {
    [$syncer, $redis, $local, $remote] = bothHaveData();
    $stats = $syncer->run();
    assertSame('no links in Redis', $stats['aborted']);
    assertSame(1, $stats['errors']);
    assertSame(0, $local->writes + $remote->writes + $redis->writes, 'nothing is written');

    [$syncer, $redis, $local, $remote] = bothHaveData(['FORCE_INITIAL_SYNC' => true]);
    $stats = $syncer->run();
    assertSame(null, $stats['aborted']);
    assertSame(1, $stats['to_remote']['created']);
});

test('an empty destination is no reason to stop', function () {
    [$syncer, $redis, $local, $remote] = env();
    $local->addItem('org-l', 'X', [$local->addCollection('org-l', 'Team')]);
    $stats = $syncer->run();
    assertSame(null, $stats['aborted']);
    assertSame(1, $stats['to_remote']['created']);
});

test('rebuild-mapping links what exists on both servers, creates nothing and reports the differences', function () {
    [$syncer, $redis, $local, $remote] = env();
    $lt = $local->addCollection('org-l', 'Team');
    $li = $local->addCollection('org-l', 'Infra');
    $rt = $remote->addCollection('org-r', 'Team');
    $ri = $remote->addCollection('org-r', 'Infra');
    $lx = $local->addItem('org-l', 'X', [$lt]);
    $ly = $local->addItem('org-l', 'Y', [$li], ['login' => ['password' => 'one']]);
    $local->addItem('org-l', 'Z', [$lt]);
    $rx = $remote->addItem('org-r', 'X', [$rt]);
    $ry = $remote->addItem('org-r', 'Y', [$ri], ['login' => ['password' => 'two']]);
    $remote->addItem('org-r', 'W', [$rt]);

    $writes = [$local->writes, $remote->writes];
    $stats = $syncer->rebuildMapping();
    assertSame($writes, [$local->writes, $remote->writes], 'the servers are not touched');
    assertSame(2, $stats['rebuild']['collections']);
    assertSame(2, $stats['rebuild']['items']);
    assertSame(1, count($stats['rebuild']['differ']));
    assertSame($rx, $redis->get($lx)['linked_uuid']);
    assertSame($ly, $redis->get($ry)['linked_uuid']);
    assertSame($rt, $redis->get($lt));

    $stats = $syncer->run();
    assertSame(null, $stats['aborted']);
    assertSame(1, $stats['to_remote']['created'], 'Z exists only locally');
    assertSame(1, $stats['to_local']['created'], 'W exists only remotely');
    assertSame(0, $stats['to_remote']['updated'] + $stats['to_local']['updated']);
    assertSame('one', $local->item($ly)['login']['password'], 'linked items keep their content until one of them changes');
    assertSame('two', $remote->item($ry)['login']['password']);
});

test('rebuild-mapping does not link ambiguous items and writes nothing in dry-run mode', function () {
    [$syncer, $redis, $local, $remote] = env();
    $lt = $local->addCollection('org-l', 'Team');
    $rt = $remote->addCollection('org-r', 'Team');
    $local->addItem('org-l', 'Dup', [$lt]);
    $remote->addItem('org-r', 'Dup', [$rt], ['login' => ['username' => 'user', 'password' => 'a']]);
    $remote->addItem('org-r', 'Dup', [$rt], ['login' => ['username' => 'user', 'password' => 'b']]);
    $stats = $syncer->rebuildMapping();
    assertSame(1, $stats['rebuild']['ambiguous']);
    assertSame(0, $stats['rebuild']['items']);

    [$dry, $redis, $local, $remote] = bothHaveData([], true);
    $stats = $dry->rebuildMapping();
    assertSame(1, $stats['rebuild']['items']);
    assertSame(0, $redis->writes, 'dry run');
});

// ---------------------------------------------------------------- safety

test('dry run: nothing is written anywhere, the counters show what would happen', function () {
    [$syncer, $redis, $local, $remote] = env([], true);
    $local->addItem('org-l', 'X', [$local->addCollection('org-l', 'Team')]);
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['created']);
    assertSame(1, $stats['collections']['to_remote']);
    assertSame(0, $remote->writes);
    assertSame(0, $redis->writes);
    assertSame([], $remote->names('org-r'));
});

test('an item that cannot be processed does not stop the others', function () {
    [$syncer, $redis, $local, $remote] = env();
    $c = $local->addCollection('org-l', 'Team');
    $local->addItem('org-l', 'Good 1', [$c]);
    $local->addItem('org-l', 'Bad', [$c]);
    $local->addItem('org-l', 'Good 2', [$c]);
    $remote->throwOnCreate['Bad'] = 'boom';
    $stats = $syncer->run();
    assertSame(['Good 1', 'Good 2'], $remote->names('org-r'));
    assertSame(1, $stats['errors']);
    assertSame(1, $stats['to_remote']['failed']);
});

test('the cycle is aborted when too many items in a row fail', function () {
    [$syncer, $redis, $local, $remote] = env();
    $c = $local->addCollection('org-l', 'Team');
    for ($i = 0; $i < 25; $i++) {
        $local->addItem('org-l', "Bad $i", [$c]);
        $remote->throwOnCreate["Bad $i"] = 'boom';
    }
    $thrown = null;
    try {
        $syncer->run();
    } catch (\RuntimeException $e) {
        $thrown = $e;
    }
    assertTrue($thrown !== null && strpos($thrown->getMessage(), 'Too many items') === 0, 'the exception tells that the cycle is aborted');
});

test('nothing is changed when a list cannot be read', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    $local->edit($x, ['login' => ['password' => 'changed']]);
    $remote->failures['getItems'] = 1;
    $writes = [$local->writes, $remote->writes, $redis->writes];
    $stats = $syncer->run();
    assertTrue($stats['aborted'] !== null, 'aborted');
    assertSame($writes, [$local->writes, $remote->writes, $redis->writes]);
});

test('a missing reverse link is restored, an inconsistent one is left alone', function () {
    [$syncer, $redis, $local, $remote, $c, $x, $x2] = synced();
    unset($redis->data[$x2]);
    $writes = [$local->writes, $remote->writes];
    $syncer->run();
    assertSame($writes, [$local->writes, $remote->writes], 'no request to the servers');
    assertSame($x, $redis->get($x2)['linked_uuid'], 'restored');

    $redis->data[$x2]['linked_uuid'] = 'somebody-else';
    $local->edit($x, ['login' => ['password' => 'changed']]);
    $stats = $syncer->run();
    assertSame($writes, [$local->writes, $remote->writes], 'skipped');
    assertSame(0, $stats['errors']);
});

test('links written by the previous version are understood', function () {
    //the previous version stored [linked_uuid, revisionDate] and, for collections, a plain string
    [$syncer, $redis, $local, $remote] = env();
    $c = $local->addCollection('org-l', 'Team');
    $rc = $remote->addCollection('org-r', 'Team');
    $x = $local->addItem('org-l', 'X', [$c]);
    $x2 = $remote->addItem('org-r', 'X', [$rc]);
    $redis->data[$c] = $rc;
    $redis->data[$rc] = $c;
    $redis->data[$x] = ['linked_uuid' => $x2, 'revisionDate' => $local->item($x)['revisionDate']];
    $redis->data[$x2] = ['linked_uuid' => $x, 'revisionDate' => $remote->item($x2)['revisionDate']];

    $stats = $syncer->run();
    assertSame(0, $stats['errors']);
    assertSame(0, $local->writes + $remote->writes, 'nothing to copy');

    $local->edit($x, ['login' => ['password' => 'changed']]);
    $stats = $syncer->run();
    assertSame(1, $stats['to_remote']['updated']);
});
