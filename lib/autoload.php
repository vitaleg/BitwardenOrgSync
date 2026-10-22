<?php
// Loads the classes of the BitwardenOrgSync namespace from this directory when they are needed
spl_autoload_register(function ($class) {
    $prefix = 'BitwardenOrgSync\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if ($name === 'BWSync') {
        $name = 'Manager';
    }
    $file = __DIR__ . '/' . $name . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
