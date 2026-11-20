<?php
// This file is included in the web-served add-on directory but can only execute from WHMCS shell access.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use SahdevConnector\{Client,LegacyArchive,LegacyArchiveTransport,LegacySource};
use WHMCS\Database\Capsule;

require_once __DIR__.'/src/LegacyArchive.php';
require_once __DIR__.'/src/LegacySource.php';
require_once __DIR__.'/src/LegacyArchiveTransport.php';
require_once __DIR__.'/src/Client.php';

$action = $argv[1] ?? '';
$path = $argv[2] ?? '';
if (!in_array($action,['capture','verify','upload','recover'],true) || $path === '' || count($argv) !== 3) {
    fwrite(STDERR,"Usage: php legacy-migrate.php capture|verify|upload|recover /private/archive-directory\n");
    exit(2);
}
umask(0077);
$root = realpath(dirname(__DIR__,3));
$path = rtrim($path,'/\\');
try {
    if (!extension_loaded('sodium') || !extension_loaded('zlib')) {
        throw new RuntimeException('PHP sodium and zlib extensions are required.');
    }
    $parent = realpath(dirname($path));
    $name = basename($path);
    if (!$root || !$parent || $name === '' || in_array($name,['.','..'],true)) throw new RuntimeException('Use a named archive directory with an existing parent.');
    $directory = $parent.DIRECTORY_SEPARATOR.$name;
    $prefix = rtrim($root,'/\\').DIRECTORY_SEPARATOR;
    if ($directory === $root || strncmp($directory,$prefix,strlen($prefix)) === 0 || is_link($directory)) {
        throw new RuntimeException('Use a private directory outside the WHMCS web root.');
    }
    if ($action === 'capture') {
        if (file_exists($directory) || !@mkdir($directory,0700)) throw new RuntimeException('Capture requires a new private archive directory.');
    } elseif (!is_dir($directory) || (DIRECTORY_SEPARATOR === '/' && (fileperms($directory) & 0077) !== 0)) {
        throw new RuntimeException('Use an existing private archive directory (mode 0700).');
    }

    if ($action !== 'verify') require_once $root.'/init.php';
    $progress = static function (int $done, int $total): void {
        fwrite(STDERR,"Chunks: {$done}/{$total}\n");
    };
    if ($action === 'capture') {
        $source = new LegacySource(Capsule::connection()->getPdo());
        LegacyArchive::capture($directory,$source->records());
        $summary = LegacyArchive::verify($directory);
    } elseif ($action === 'verify') {
        $summary = LegacyArchive::verify($directory);
    } else {
        $transport = new LegacyArchiveTransport(new Client());
        if ($action === 'upload') {
            $transport->upload($directory,$progress);
            $summary = ['state'=>'complete','archive'=>LegacyArchive::manifest($directory)['id']];
        } else {
            $summary = $transport->recover($directory,$progress);
        }
    }
    echo json_encode($summary,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (PDOException $error) {
    fwrite(STDERR,"Database capture failed. Check MySQL permissions, engine support and concurrent schema changes. No source records were changed.\n");
    exit(1);
} catch (RuntimeException $error) {
    fwrite(STDERR,$error->getMessage()."\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR,"Archive operation failed. Check the connection, data and archive integrity before retrying.\n");
    exit(1);
}
