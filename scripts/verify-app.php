#!/usr/bin/env php
<?php

/**
 * Quick sanity checks before deploy / composer install.
 * Usage: php scripts/verify-app.php
 */

$root = dirname(__DIR__);
$errors = [];

$routesFile = $root.'/routes/api.php';
$routes = file_get_contents($routesFile);

if ($routes === false) {
    $errors[] = 'Could not read routes/api.php';
} elseif (preg_match('/^<<<<<<<|^=======|^>>>>>>>/m', $routes)) {
    $errors[] = 'routes/api.php contains unresolved Git merge conflict markers (<<<<<<<, =======, >>>>>>>)';
}

if ($errors !== []) {
    fwrite(STDERR, "Deploy verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

passthru(PHP_BINARY.' -l '.escapeshellarg($routesFile), $code);
exit($code);
