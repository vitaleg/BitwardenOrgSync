<?php
namespace BitwardenOrgSync;

class Helper {

    private const ITEM_TYPES = [
        1 => 'Login',
        2 => 'Secure Note',
        3 => 'Card',
        4 => 'Identity',
        5 => 'SSH Key'
    ];

    private const DELETE_MARKER = '[DELETE]';

    private static $reported = [];

    public static function redis(array $config) {
        $redis = new \Redis();
        if (!$redis->connect($config['REDIS']['ADDRESS'], $config['REDIS']['PORT'], 5, null, 0, 30)) {
            throw new \RuntimeException('Cannot connect to Redis at ' . $config['REDIS']['ADDRESS'] . ':' . $config['REDIS']['PORT']);
        }
        if ($config['REDIS']['USERNAME']) {
            $redis->auth([$config['REDIS']['USERNAME'], $config['REDIS']['PASSWORD']]);
        } else if ($config['REDIS']['PASSWORD']) {
            $redis->auth($config['REDIS']['PASSWORD']);
        }
        if ($config['REDIS']['DATABASE_ID'] > 0) {
            $redis->select($config['REDIS']['DATABASE_ID']);
        }
        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        return $redis;
    }

    //Stores both directions of a link between two UUIDs in one transaction: either both keys are written or none
    public static function link($redis, string $keyA, $valueA, string $keyB, $valueB) {
        try {
            $result = $redis->multi()->set($keyA, $valueA)->set($keyB, $valueB)->exec();
        } catch (\Throwable $e) {
            try {
                $redis->discard(); //otherwise the client could stay in the MULTI mode and queue all following commands
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
        if (!is_array($result) || in_array(false, $result, true)) {
            throw new \RuntimeException("Failed to store the link $keyA <-> $keyB in Redis");
        }
    }

    public static function isSyncable(array $item) {
        return ($item['type'] ?? null) === 1 && isset($item['login']) && is_array($item['login']);
    }

    public static function countSkipped(array &$skipped, array $item) {
        $type = $item['type'] ?? '?';
        $name = self::ITEM_TYPES[$type] ?? 'type ' . $type;
        $skipped[$name] = ($skipped[$name] ?? 0) + 1;
    }

    //Warns when the set of skipped items appears or changes, otherwise the same warning every cycle would only add noise
    public static function reportSkipped(string $server, array $skipped) {
        $details = [];
        foreach ($skipped as $name => $count) {
            $details[] = $name . ': ' . $count;
        }
        $details = implode(', ', $details);
        $changed = (self::$reported[$server] ?? '') !== $details;
        self::$reported[$server] = $details;
        if (!count($skipped)) {
            return;
        }
        $message = "$server: skipped " . array_sum($skipped) . " item(s), only Login items are synchronized ($details)";
        if ($changed) {
            Logger::warning($message);
        } else {
            Logger::debug($message);
        }
    }

    //An item is deleted on both servers when its name starts or ends with the marker. The marker in the middle of a name is just text.
    public static function hasDeleteMarker($name) {
        $name = trim((string) $name);
        return str_starts_with($name, self::DELETE_MARKER) || str_ends_with($name, self::DELETE_MARKER);
    }

    //Hash of everything that is copied between the servers: equal hashes mean there is nothing to synchronize
    public static function fingerprint(array $item) {
        $login = (isset($item['login']) && is_array($item['login'])) ? $item['login'] : [];
        $uris = [];
        foreach (($login['uris'] ?? []) as $uri) {
            $uris[] = [$uri['match'] ?? null, $uri['uri'] ?? null];
        }
        $fields = [];
        foreach (($item['fields'] ?? []) as $field) {
            $fields[] = [$field['name'] ?? null, $field['value'] ?? null, $field['type'] ?? null];
        }
        return md5(json_encode([
            $item['type'] ?? null,
            $item['name'] ?? null,
            $item['notes'] ?? null,
            (int) ($item['reprompt'] ?? 0),
            $login['username'] ?? null,
            $login['password'] ?? null,
            $login['totp'] ?? null,
            $uris,
            $fields
        ]));
    }

    //What identifies "the same" item on the other server when there is no stored link
    public static function identityKey(array $item) {
        return ($item['type'] ?? '') . "\n" . ($item['name'] ?? '') . "\n" . ($item['login']['username'] ?? '');
    }

    public static function isNewer(string $a, string $b) {
        try {
            return new \DateTimeImmutable($a) > new \DateTimeImmutable($b);
        } catch (\Throwable $e) {
            return strcmp($a, $b) > 0;
        }
    }

    public static function newStats() {
        $items = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'failed' => 0];
        return [
            'collections' => ['to_remote' => 0, 'to_local' => 0],
            'to_remote' => $items,
            'to_local' => $items,
            'memberships' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'aborted' => null
        ];
    }

    public static function summary(int $cycle, array $stats, float $seconds, int $errors) {
        $parts = [];
        foreach (['to_remote' => 'local->remote', 'to_local' => 'remote->local'] as $key => $label) {
            $details = [];
            foreach ($stats[$key] as $name => $count) {
                $details[] = "$name $count";
            }
            $parts[] = "$label " . implode(', ', $details);
        }
        $parts[] = 'collections created: remote ' . $stats['collections']['to_remote'] . ', local ' . $stats['collections']['to_local'];
        $parts[] = 'membership changes ' . $stats['memberships'];
        $parts[] = 'conflicts ' . $stats['conflicts'];
        return "Cycle $cycle finished in " . number_format($seconds, 1) . 's, ' . ($errors ? "$errors error(s)" : 'no errors') . ' | ' . implode(' | ', $parts);
    }

    public static function describeException(\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    }

}
