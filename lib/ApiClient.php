<?php
namespace BitwardenOrgSync;

class ApiClient {

    private $url;

    public function __construct(string $url) {
        $this->url = $url;
        $this->http = new HttpClient;
    }

    private function call(string $path, array $postfields = [], string $method = 'GET') {
        $query = $this->http->setUrl($this->url . $path);
        $query->setCustomMethod($method);
        $query->setHeaders(['Content-Type: application/json']);
        if (count($postfields)) {
            $query->setPost(true)->setPostFields($postfields, true);
        }
        //$query->setVerbose(true);
        $query->exec();
        if ($query->getStatusCode() >= 200 && $query->getStatusCode() < 300) {
            return $query->jsonToArray();
        }
        return false;
    }

    public function createOrgCollection(string $organizationId, string $name) {
        $query = $this->call('/object/org-collection?organizationId=' . $organizationId, [
            'organizationId' => $organizationId,
            'name' => $name,
            'externalId' => null,
            'groups' => []
        ], 'POST');
        if (isset($query['success']) && $query['success']) {
            return $query['data'];
        }
        return false;
    }


    public function getOrgItems() {
        $query = $this->call('/list/object/collections');
        if (isset($query['success']) && $query['success']) {
            return $query['data']['data'];
        }
        return false;
    }

    public function createItem(string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = []) {
        $query = $this->call('/object/item', [
            'organizationId' => $organizationId,
            'name' => $name,
            'collectionIds' => $collectionIds,
            'folderId' => null,
            'type' => $type,
            'notes' => $notes,
            'favorite' => false,
            'fields' => $fields,
            'login' => $login,
            'reprompt' => 0
        ], 'POST');
        if (isset($query['success']) && $query['success']) {
            return $query['data'];
        }
        return false;
    }

    public function updateItem(string $id, string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = []) {
        $query = $this->call('/object/item/' . $id, [
            'organizationId' => $organizationId,
            'name' => $name,
            'collectionIds' => $collectionIds,
            'folderId' => null,
            'type' => $type,
            'notes' => $notes,
            'favorite' => false,
            'fields' => $fields,
            'login' => $login,
            'reprompt' => 0
        ], 'PUT');
        if (isset($query['success']) && $query['success']) {
            return $query['data'];
        }
        return false;
    }

    public function deleteItem(string $id) {
        $query = $this->call('/object/item/' . $id, [], 'DELETE');
        if (isset($query['success']) && $query['success']) {
            return true;
        }
        return false;
    }

    public function getItems() {
        $query = $this->call('/list/object/items');
        if (isset($query['success']) && $query['success']) {
            return $query['data']['data'];
        }
        return false;
    }

    public function sync() {
        return $this->call('/sync', [], 'POST');
    }

    public function status() {
        return $this->call('/status');
    }

}
