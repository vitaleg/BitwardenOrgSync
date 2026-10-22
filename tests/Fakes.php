<?php
// In-memory replacements of Redis and of the bw API servers.

class FakeRedis {

    public $data = [];
    public $writes = 0;
    public $failExec = false;
    private $queue = null;

    public function get($key) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : false;
    }

    public function set($key, $value, $options = null) {
        if ($this->queue !== null) {
            $this->queue[] = [$key, $value];
            return $this;
        }
        $this->writes++;
        $this->data[$key] = $value;
        return true;
    }

    public function del($keys) {
        $keys = is_array($keys) ? $keys : func_get_args();
        $this->writes++;
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function multi() {
        $this->queue = [];
        return $this;
    }

    public function exec() {
        $queue = $this->queue;
        $this->queue = null;
        if ($this->failExec) {
            throw new \RedisException('Redis server went away');
        }
        $this->writes++;
        foreach ($queue as [$key, $value]) {
            $this->data[$key] = $value;
        }
        return array_fill(0, count($queue), true);
    }

    public function discard() {
        $this->queue = null;
        return true;
    }

}

/**
 * Behaves like bw serve: "edit item" does not change the collections (only setItemCollections does),
 * an organization item needs at least one collection, a trashed item cannot be edited.
 */
class FakeApi implements \BitwardenOrgSync\ApiInterface {

    public static $clock = 0; //shared by all servers: a later write is a newer revision

    public $collections = [];
    public $items = [];
    public $failures = [];       //method => number of the next calls that fail
    public $throwOnCreate = [];  //item name => exception message
    public $bumpOnCollections = false;
    public $writes = 0;
    public $calls = [];
    private $prefix;
    private $seq = 0;

    public function __construct(string $prefix) {
        $this->prefix = $prefix;
    }

    private static function now() {
        self::$clock++;
        return gmdate('Y-m-d\TH:i:s', 1700000000 + self::$clock) . '.000000Z';
    }

    private function nextId() {
        return $this->prefix . '-' . (++$this->seq);
    }

    private function fails(string $method) {
        $this->calls[] = $method;
        if (($this->failures[$method] ?? 0) > 0) {
            $this->failures[$method]--;
            return true;
        }
        return false;
    }

    // ---- what the test does as a user of the server

    public function addCollection(string $org, string $name) {
        $id = $this->nextId();
        $this->collections[$id] = ['id' => $id, 'organizationId' => $org, 'name' => $name];
        return $id;
    }

    public function addItem(string $org, string $name, array $collectionIds, array $extra = []) {
        $id = $this->nextId();
        $this->items[$id] = array_merge([
            'id' => $id,
            'organizationId' => $org,
            'type' => 1,
            'name' => $name,
            'notes' => null,
            'reprompt' => 0,
            'fields' => [],
            'collectionIds' => $collectionIds,
            'login' => ['username' => 'user', 'password' => 'pass', 'totp' => null, 'uris' => []],
            'revisionDate' => self::now(),
            'deletedDate' => null
        ], $extra);
        return $id;
    }

    public function edit(string $id, array $changes) {
        foreach ($changes as $key => $value) {
            $this->items[$id][$key] = ($key === 'login') ? array_merge($this->items[$id]['login'], $value) : $value;
        }
        $this->items[$id]['revisionDate'] = self::now();
    }

    public function touch(string $id) {
        $this->items[$id]['revisionDate'] = self::now();
    }

    //like the web vault: the revision date of the item is not changed
    public function moveToCollections(string $id, array $collectionIds) {
        $this->items[$id]['collectionIds'] = array_values($collectionIds);
    }

    public function trash(string $id) {
        $this->items[$id]['deletedDate'] = self::now();
    }

    public function item(string $id) {
        return $this->items[$id];
    }

    public function find(string $org, string $name) {
        foreach ($this->items as $item) {
            if ($item['organizationId'] === $org && $item['name'] === $name && $item['deletedDate'] === null) {
                return $item;
            }
        }
        return null;
    }

    public function names(string $org) {
        $names = [];
        foreach ($this->items as $item) {
            if ($item['organizationId'] === $org && $item['deletedDate'] === null) {
                $names[] = $item['name'];
            }
        }
        sort($names);
        return $names;
    }

    // ---- ApiInterface

    public function getOrgItems(string $organizationId) {
        if ($this->fails('getOrgItems')) {
            return false;
        }
        return array_values(array_filter($this->collections, function ($c) use ($organizationId) {
            return $c['organizationId'] === $organizationId;
        }));
    }

    public function getItems(string $organizationId) {
        if ($this->fails('getItems')) {
            return false;
        }
        return array_values(array_filter($this->items, function ($i) use ($organizationId) {
            return $i['organizationId'] === $organizationId && $i['deletedDate'] === null;
        }));
    }

    public function createOrgCollection(string $organizationId, string $name) {
        if ($this->fails('createOrgCollection')) {
            return false;
        }
        $this->writes++;
        $id = $this->addCollection($organizationId, $name);
        return $this->collections[$id];
    }

    public function createItem(string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = [], int $reprompt = 0) {
        if (isset($this->throwOnCreate[$name])) {
            throw new \RuntimeException($this->throwOnCreate[$name]);
        }
        if ($this->fails('createItem') || !count($collectionIds)) {
            return false;
        }
        $this->writes++;
        $id = $this->addItem($organizationId, $name, $collectionIds, ['type' => $type, 'login' => $login, 'notes' => $notes, 'fields' => $fields, 'reprompt' => $reprompt]);
        return $this->items[$id];
    }

    public function updateItem(string $id, string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = [], int $reprompt = 0) {
        if ($this->fails('updateItem') || !isset($this->items[$id]) || $this->items[$id]['deletedDate'] !== null) {
            return false;
        }
        $this->writes++;
        $this->items[$id] = array_merge($this->items[$id], ['name' => $name, 'type' => $type, 'login' => $login, 'notes' => $notes, 'fields' => $fields, 'reprompt' => $reprompt, 'revisionDate' => self::now()]);
        return $this->items[$id];
    }

    public function setItemCollections(string $id, string $organizationId, array $collectionIds) {
        if ($this->fails('setItemCollections') || !isset($this->items[$id]) || $this->items[$id]['deletedDate'] !== null) {
            return false;
        }
        $this->writes++;
        $this->items[$id]['collectionIds'] = array_values($collectionIds);
        if ($this->bumpOnCollections) {
            $this->items[$id]['revisionDate'] = self::now();
        }
        return $this->items[$id];
    }

    public function deleteItem(string $id) {
        if ($this->fails('deleteItem')) {
            return false;
        }
        $this->writes++;
        if (isset($this->items[$id]) && $this->items[$id]['deletedDate'] === null) {
            $this->items[$id]['deletedDate'] = self::now();
        }
        return true; //an item that is not there counts as deleted, like in ApiClient
    }

}
