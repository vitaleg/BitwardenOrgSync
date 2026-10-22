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

    public static function isSyncable(array $item) {
        return ($item['type'] ?? null) === 1 && isset($item['login']) && is_array($item['login']);
    }

    public static function countSkipped(array &$skipped, array $item) {
        $type = $item['type'] ?? '?';
        $name = self::ITEM_TYPES[$type] ?? 'type ' . $type;
        $skipped[$name] = ($skipped[$name] ?? 0) + 1;
    }

    public static function reportSkipped(string $server, array $skipped) {
        if (!count($skipped)) {
            return;
        }
        $details = [];
        foreach ($skipped as $name => $count) {
            $details[] = $name . ': ' . $count;
        }
        echo "$server: skipped " . array_sum($skipped) . ' item(s), only Login items are synchronized (' . implode(', ', $details) . ").\n";
    }

}
