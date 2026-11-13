<?php
namespace App\Services;
final class ConnectorArchive
{
    public static function verify(string $path): void
    {
        $policy = require __DIR__.'/../../config/distribution.php';
        $expected = array_map(fn ($name) => $policy['prefix'].$name,$policy['files']);
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Connector archive is invalid.');
        try {
            $seen = []; $total = 0;
            for ($index=0; $index<$zip->numFiles; $index++) {
                $entry = $zip->statIndex($index); $name = $entry['name']; $total += $entry['size'];
                if (!in_array($name,$expected,true) || isset($seen[$name]) || $total > 2*1024*1024) throw new \RuntimeException('Unexpected content in connector archive.');
                $seen[$name] = true;
            }
            if (array_diff($expected,array_keys($seen))) throw new \RuntimeException('Connector archive is incomplete.');
        } finally { $zip->close(); }
    }
}
