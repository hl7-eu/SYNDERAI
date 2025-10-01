<?php

// Very small PSR-4 autoloader for the "Twig" namespace
spl_autoload_register(function ($class) {
    $prefix = 'Twig\\';
    $baseDir = __DIR__ . '/../lib/twig3x/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // not a Twig class
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require $file;
    }
});