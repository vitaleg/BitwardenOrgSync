<?php
namespace BitwardenOrgSync;

interface ApiInterface {

    public function getOrgItems(string $organizationId);

    public function getItems(string $organizationId);

    public function createOrgCollection(string $organizationId, string $name);

    public function createItem(string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = [], int $reprompt = 0);

    public function updateItem(string $id, string $organizationId, string $name, array $collectionIds, int $type, array $login, string|NULL $notes = '', array $fields = [], int $reprompt = 0);

    public function setItemCollections(string $id, string $organizationId, array $collectionIds);

    public function deleteItem(string $id);

}
