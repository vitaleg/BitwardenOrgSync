<?php
use BitwardenOrgSync\Helper;

test('delete marker: only at the start or at the end of the name', function () {
    assertTrue(Helper::hasDeleteMarker('[DELETE] Server'));
    assertTrue(Helper::hasDeleteMarker('Server [DELETE]'));
    assertTrue(Helper::hasDeleteMarker('  [DELETE]  '));
    assertTrue(!Helper::hasDeleteMarker('Docs: what the [DELETE] button does'), 'the middle of a name is just text');
    assertTrue(!Helper::hasDeleteMarker('[delete] Server'), 'the marker is case sensitive');
    assertTrue(!Helper::hasDeleteMarker(null));
    assertTrue(!Helper::hasDeleteMarker(''));
});

test('fingerprint: equal for the same content, different when anything copied changes', function () {
    $item = [
        'type' => 1, 'name' => 'X', 'notes' => 'n', 'reprompt' => 0, 'fields' => [['name' => 'a', 'value' => 'b', 'type' => 0]],
        'login' => ['username' => 'u', 'password' => 'p', 'totp' => null, 'uris' => [['match' => null, 'uri' => 'https://x']]]
    ];
    $same = $item;
    $same['id'] = 'other-id';
    $same['revisionDate'] = '2030-01-01T00:00:00.000000Z';
    $same['collectionIds'] = ['c'];
    assertSame(Helper::fingerprint($item), Helper::fingerprint($same), 'ids, revisions and collections are not part of the content');

    foreach ([['name', 'Y'], ['notes', 'm'], ['reprompt', 1]] as [$key, $value]) {
        $changed = $item;
        $changed[$key] = $value;
        assertTrue(Helper::fingerprint($item) !== Helper::fingerprint($changed), "$key matters");
    }
    $changed = $item;
    $changed['login']['password'] = 'q';
    assertTrue(Helper::fingerprint($item) !== Helper::fingerprint($changed), 'password matters');
    $changed = $item;
    $changed['fields'][0]['value'] = 'c';
    assertTrue(Helper::fingerprint($item) !== Helper::fingerprint($changed), 'custom fields matter');
});

test('isNewer compares revision dates, also with fractions of a second', function () {
    assertTrue(Helper::isNewer('2026-01-01T10:00:00.500000Z', '2026-01-01T10:00:00.400000Z'));
    assertTrue(!Helper::isNewer('2026-01-01T10:00:00.400000Z', '2026-01-01T10:00:00.500000Z'));
    assertTrue(!Helper::isNewer('2026-01-01T10:00:00.000000Z', '2026-01-01T10:00:00.000000Z'));
    assertTrue(Helper::isNewer('2026-02-01T00:00:00Z', '2026-01-31T23:59:59Z'));
});

test('identity key: type, name and user name', function () {
    $a = ['type' => 1, 'name' => 'X', 'login' => ['username' => 'u']];
    $b = ['type' => 1, 'name' => 'X', 'login' => ['username' => 'u', 'password' => 'different']];
    $c = ['type' => 1, 'name' => 'X', 'login' => ['username' => 'other']];
    assertSame(Helper::identityKey($a), Helper::identityKey($b));
    assertTrue(Helper::identityKey($a) !== Helper::identityKey($c));
});

test('only login items with a login object are syncable', function () {
    assertTrue(Helper::isSyncable(['type' => 1, 'login' => []]));
    assertTrue(!Helper::isSyncable(['type' => 2, 'secureNote' => ['type' => 0]]));
    assertTrue(!Helper::isSyncable(['type' => 1, 'login' => null]));
});

test('summary mentions every counter', function () {
    $stats = Helper::newStats();
    $stats['to_remote']['created'] = 3;
    $stats['conflicts'] = 2;
    $stats['memberships'] = 4;
    $line = Helper::summary(7, $stats, 1.25, 0);
    foreach (['Cycle 7', '1.3s', 'no errors', 'local->remote created 3', 'membership changes 4', 'conflicts 2'] as $part) {
        assertTrue(strpos($line, $part) !== false, "the summary contains '$part': $line");
    }
});
