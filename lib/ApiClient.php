<?php
namespace BitwardenOrgSync;

class ApiClient {

    private const STATUS_TIMEOUT = 10;

    private $url;
    private $name;
    private $timeout;
    private $http;
    private $errors = 0;
    private $lastError = '';
    private $quietDetails = '';
    private $quietTime = 0;

    public function __construct(string $url, string $name = '', int $timeout = 120) {
        $this->url = $url;
        $this->name = strlen($name) ? $name : $url;
        $this->timeout = $timeout;
        $this->http = new HttpClient;
        $this->http->setConnectTimeout(5);
    }

    public function getErrorCount() {
        return $this->errors;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function resetErrors() {
        $this->errors = 0;
        $this->lastError = '';
    }

    private function call(string $path, array $postfields = [], string $method = 'GET', array $options = []) {
        $query = $this->http->setUrl($this->url . $path);
        $query->setTimeout($options['timeout'] ?? $this->timeout);
        $query->setCustomMethod($method);
        $query->setHeaders(['Content-Type: application/json']);
        if (count($postfields) || $method == 'POST' || $method == 'PUT') {
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
        $details = "HTTP $code" . $this->errorDetails($data, $query->getError());
        if (empty($options['quiet'])) {
            $this->logError($method, $path, $details);
        } elseif ($code > 0 && !$this->isRepeated($details)) {
            //a probe that is repeated every second while the server starts: log it when it appears and then every 30 seconds
            $this->logError($method, $path, $details);
        }
        return false;
    }

    private function isRepeated(string $details) {
        $now = time();
        if ($details === $this->quietDetails && $now - $this->quietTime < 30) {
            return true;
        }
        $this->quietDetails = $details;
        $this->quietTime = $now;
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
        $this->errors++;
        $this->lastError = "[{$this->name}] $method $path failed: $details";
        Logger::error($this->lastError);
    }

    private function onlyOrganization(array $objects, string $organizationId) {
        $filtered = array_values(array_filter($objects, function ($object) use ($organizationId) {
            return isset($object['organizationId']) && $object['organizationId'] === $organizationId;
        }));
        if (count($filtered) != count($objects)) {
            Logger::warning("[{$this->name}] Ignored " . (count($objects) - count($filtered)) . " object(s) not belonging to organization $organizationId");
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
                Logger::warning("[{$this->name}] No collections found in organization $organizationId, check ORGANIZATION_ID");
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
        $query = $this->call('/sync', [], 'POST');
        return isset($query['success']) && $query['success'];
    }

    public function status() {
        return $this->call('/status', [], 'GET', ['quiet' => true, 'timeout' => self::STATUS_TIMEOUT]);
    }

    public function vaultStatus() {
        $query = $this->status();
        if (isset($query['data']['template']['status']) && is_string($query['data']['template']['status'])) {
            return $query['data']['template']['status'];
        }
        return false;
    }

}
