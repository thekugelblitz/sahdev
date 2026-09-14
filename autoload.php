<?php
/**
 * Sahdev Universal Autoloader
 *
 * Provides PSR-4 compatible class resolution for all Sahdev module classes.
 * Handles case-sensitivity discrepancies across Linux (e.g. ext4) and Windows filesystems.
 *
 * Maps:
 *   Sahdev\Lib\*         => lib/*
 *   Sahdev\Controllers\* => controllers/*
 *   Sahdev\Modules\*     => modules/*
 */

if (!function_exists('sahdev_register_autoloader')) {
    function sahdev_register_autoloader()
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(function ($class) {
            $prefix = 'Sahdev\\';
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $baseDir = __DIR__ . '/';
            $relativeClass = substr($class, $len);
            $parts = explode('\\', $relativeClass);
            if (empty($parts)) {
                return;
            }

            // 1. Direct path check
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }

            // 2. Lowercase first segment (e.g. Lib -> lib, Controllers -> controllers, Modules -> modules)
            $firstLower = $parts;
            $firstLower[0] = strtolower($firstLower[0]);
            $file = $baseDir . implode('/', $firstLower) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }

            // 3. Lowercase all directory segments except the class filename
            $allLower = $parts;
            for ($i = 0; $i < count($allLower) - 1; $i++) {
                $allLower[$i] = strtolower($allLower[$i]);
            }
            $file = $baseDir . implode('/', $allLower) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        });
    }

    sahdev_register_autoloader();
}
