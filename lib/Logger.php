<?php
namespace BitwardenOrgSync;

class Logger {

    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'off' => 4
    ];

    private static $level = 1;

    public static function setLevel(string $level) {
        self::$level = self::LEVELS[strtolower($level)] ?? self::LEVELS['info'];
    }

    public static function debug(string $message) {
        self::write('debug', 'DEBUG', $message);
    }

    public static function info(string $message) {
        self::write('info', 'INFO', $message);
    }

    public static function warning(string $message) {
        self::write('warning', 'WARN', $message);
    }

    public static function error(string $message) {
        self::write('error', 'ERROR', $message);
    }

    private static function write(string $level, string $label, string $message) {
        if (self::LEVELS[$level] < self::$level) {
            return;
        }
        $stream = (self::LEVELS[$level] >= self::LEVELS['warning']) ? STDERR : STDOUT;
        $message = trim(preg_replace('/\s*[\r\n]+\s*/', ' | ', $message));
        fwrite($stream, date('Y-m-d H:i:s') . " [$label] $message\n");
    }

}
