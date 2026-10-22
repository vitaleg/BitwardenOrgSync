<?php
namespace BitwardenOrgSync;

class ApiClient {

    private $url;
    private $name;
    private $http;

    public function __construct(string $url, string $name = '') {
        $this->url = $url;
        $this->name = strlen($name) ? $name : $url;
        $this->http = new HttpClient;
    }

    private function call(string $path, array $postfields = [], string $method = 'GET', bool $quietConnectErrors = false) {
        $query = $this->http->setUrl($this->url . $path);
        $query->setCustomMethod($method);
        $query->setHeaders(['Content-Type: application/json']);
        if (count($postfields)) {
            $query->setPost(true)->setPostFields($postfields, true);
        }
        //$query->setVerbose(true);
        $query->exec();
        $code = $query->getStatusCode();
        $data = $query->jsonToArray();
        if ($code >= 200 && $code < 300) {
            if (!is_array($data)) {
                $this->logError($method, $path, "HTTP $code, response is not valid JSON");
            } elseif (isset($data['success']) && !$data['success']) {
                $this->logError($method, $path, "HTTP $code" . $this->errorDetails($data, ''));
            }
            return $data;
        }
        if ($code > 0 || !$quietConnectErrors) {
            $this->logError($method, $path, "HTTP $code" . $this->errorDetails($data, $query->getError()));
        }
        return false;
    }

    private function errorDetails($data, string $curlError) {
        if (is_array($data) && isset($data['message']) && is_string($data['message']) && strlen($data['message'])) {
            return ': ' . $data['message'];
        }
        if (strlen($curlError)) {
            return ': ' . $curlError;
        }
        return '';
    }

    private function logError(string $method, string $path, string $details) {
        echo "[{$this->name}] $method $path failed: $details\n";
    }

    private function onlyOrganization(array $objects, string $organizationId) {
        $filtered = array_values(array_filter($objects, function ($object) use ($organizationId) {
            return isset($object['organizationId']) && $object['organizationId'] === $organizationId;
        }));
        if (count($filtered) != count($objects)) {
            echo "[{$this->name}] Ignored " . (count($objects) - count($filtered)) . " object(s) not belonging to organization $organizationId\n";
        }
        return $filtered;
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


    public function getOrgItems(string $organizationId) {
        $query = $this->call('/list/object/collections?organizationId=' . rawurlencode($organizationId));
        if (isset($query['success']) && $query['success']) {
            $collections = $this->onlyOrganization($query['data']['data'] ?? [], $organizationId);
            if (!count($collections)) {
                echo "[{$this->name}] Warning: no collections found in organization $organizationId, check ORGANIZATION_ID\n";
            }
            return $collections;
        }
        return false;
    }

    public function createItem(string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = [], int $reprompt = 0) {
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
            'reprompt' => $reprompt
        ], 'POST');
        if (isset($query['success']) && $query['success']) {
            return $query['data'];
        }
        return false;
    }

    public function updateItem(string $id, string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = [], int $reprompt = 0) {
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
            'reprompt' => $reprompt
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

    public function getItems(string $organizationId) {
        $query = $this->call('/list/object/items?organizationId=' . rawurlencode($organizationId));
        if (isset($query['success']) && $query['success']) {
            return $this->onlyOrganization($query['data']['data'] ?? [], $organizationId);
        }
        return false;
    }

    public function sync() {
        return $this->call('/sync', [], 'POST');
    }

    public function status() {
        return $this->call('/status', [], 'GET', true);
    }

}
