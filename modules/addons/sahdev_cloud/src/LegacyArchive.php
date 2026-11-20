<?php
namespace SahdevConnector;

use RuntimeException;

/** Streaming, encrypted interchange. Column values are base64 inside each encrypted record. */
final class LegacyArchive
{
    private const CHUNK_SIZE = 120 * 1024;
    private const MAX_RECORD_SIZE = 64 * 1024 * 1024;
    private const INITIAL_CHAIN = '0000000000000000000000000000000000000000000000000000000000000000';

    public static function capture(string $directory, iterable $records): array
    {
        $id = bin2hex(random_bytes(16));
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        self::writeFile($directory.'/key', bin2hex($key)."\n");
        $file = self::createFile($directory.'/chunks');
        $chain = self::INITIAL_CHAIN;
        $buffer = '';
        $sequence = 0;
        $bytes = 0;
        try {
            foreach ($records as $record) {
                $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                if (strlen($line) > self::MAX_RECORD_SIZE) {
                    throw new RuntimeException('A source row exceeds the 64 MiB encoded record limit. The capture is incomplete.');
                }
                for ($offset = 0, $length = strlen($line); $offset < $length;) {
                    $size = min(self::CHUNK_SIZE - strlen($buffer), $length - $offset);
                    $buffer .= substr($line,$offset,$size);
                    $offset += $size;
                    if (strlen($buffer) === self::CHUNK_SIZE) {
                        self::writeChunk($file,$buffer,$key,$chain,$sequence,$bytes);
                        $buffer = '';
                    }
                }
            }
            if ($buffer !== '') {
                self::writeChunk($file,$buffer,$key,$chain,$sequence,$bytes);
            }
        } finally {
            fclose($file);
        }
        if ($sequence === 0) {
            throw new RuntimeException('The archive has no source records.');
        }
        $manifest = ['version'=>1, 'id'=>$id, 'chunks'=>$sequence, 'bytes'=>$bytes, 'chain'=>$chain];
        $manifest['authentication'] = self::authenticate($manifest,$key);
        self::writeFile($directory.'/manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR)."\n");
        return $manifest;
    }

    public static function manifest(string $directory): array
    {
        $manifest = json_decode((string)file_get_contents($directory.'/manifest.json',false,null,0,4096),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
            || !preg_match('/^[a-f0-9]{32}$/D',$manifest['id'] ?? '')
            || !is_int($manifest['chunks'] ?? null) || $manifest['chunks'] < 1
            || !is_int($manifest['bytes'] ?? null) || $manifest['bytes'] < 1
            || !preg_match('/^[a-f0-9]{64}$/D',$manifest['chain'] ?? '')
            || !hash_equals(self::authenticate($manifest,self::key($directory)),(string)($manifest['authentication'] ?? ''))) {
            throw new RuntimeException('Archive manifest authentication failed.');
        }
        return $manifest;
    }

    public static function chunks(string $path): \Generator
    {
        $file = fopen($path,'rb');
        if (!$file) throw new RuntimeException('Cannot read archive chunks.');
        try {
            while (($line = fgets($file,180001)) !== false) {
                if (substr($line,-1) !== "\n" || strlen($line) > 180000) {
                    throw new RuntimeException('Archive contains an invalid or incomplete chunk.');
                }
                yield substr($line,0,-1);
            }
            if (!feof($file)) throw new RuntimeException('Cannot finish reading archive chunks.');
        } finally {
            fclose($file);
        }
    }

    /** Consume the entire generator to authenticate a snapshot before importing any records. */
    public static function records(string $directory, ?string $path = null): \Generator
    {
        $manifest = self::manifest($directory);
        $key = self::key($directory);
        $chain = self::INITIAL_CHAIN;
        $sequence = 0;
        $bytes = 0;
        $buffer = '';
        foreach (self::chunks($path ?? $directory.'/chunks') as $encoded) {
            $sequence++;
            $bytes += strlen($encoded);
            $chain = hash('sha256',$chain.hash('sha256',$encoded));
            $chunk = base64_decode($encoded,true);
            if ($chunk === false || strlen($chunk) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                throw new RuntimeException('Invalid encrypted chunk.');
            }
            $data = sodium_crypto_secretbox_open(substr($chunk,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($chunk,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key);
            $plain = $data === false ? false : @gzinflate($data,self::CHUNK_SIZE);
            if ($plain === false) throw new RuntimeException('Chunk authentication or decompression failed.');
            $offset = 0;
            while (($end = strpos($plain,"\n",$offset)) !== false) {
                $line = $buffer.substr($plain,$offset,$end-$offset);
                if (strlen($line) > self::MAX_RECORD_SIZE) throw new RuntimeException('Archive record exceeds the verification limit.');
                try {
                    yield json_decode($line,true,64,JSON_THROW_ON_ERROR);
                } catch (\JsonException $error) {
                    throw new RuntimeException('Archive records are damaged or reordered.',0,$error);
                }
                $buffer = '';
                $offset = $end+1;
            }
            $buffer .= substr($plain,$offset);
            if (strlen($buffer) > self::MAX_RECORD_SIZE) throw new RuntimeException('Archive record exceeds the verification limit.');
        }
        if ($buffer !== '' || $sequence !== $manifest['chunks'] || $bytes !== $manifest['bytes'] || !hash_equals($manifest['chain'],$chain)) {
            throw new RuntimeException('Archive integrity check failed. A chunk is missing, reordered or changed.');
        }
    }

    public static function verify(string $directory, ?string $path = null): array
    {
        $tables = [];
        $open = null;
        $rows = 0;
        foreach (self::records($directory,$path) as $record) {
            if (isset($record['table'], $record['columns'], $record['ddl']) && $open === null && !isset($tables[$record['table']])) {
                if (!is_array($record['columns']) || !$record['columns']) throw new RuntimeException('Invalid archive schema.');
                $open = $record['table'];
                $tables[$open] = ['columns'=>count($record['columns']), 'rows'=>0];
            } elseif (isset($record['row']) && $open !== null) {
                if (count($record['row']) !== $tables[$open]['columns']) throw new RuntimeException('A row does not match its archived schema.');
                $tables[$open]['rows']++;
                $rows++;
            } elseif ($open !== null && ($record['end'] ?? null) === $open && ($record['rows'] ?? null) === $tables[$open]['rows']) {
                $open = null;
            } else {
                throw new RuntimeException('Archive table boundaries or row counts do not match.');
            }
        }
        if ($open !== null || !$tables) throw new RuntimeException('Incomplete table archive.');
        return ['tables'=>$tables, 'rows'=>$rows];
    }

    public static function createFile(string $path)
    {
        $handle = fopen($path,'xb');
        if (!$handle) throw new RuntimeException('Cannot create archive file. Use a new private directory.');
        return $handle;
    }

    public static function write($file, string $data): void
    {
        if (fwrite($file,$data) !== strlen($data)) throw new RuntimeException('Incomplete archive write. Check disk space.');
    }

    private static function writeFile(string $path, string $data): void
    {
        $file = self::createFile($path);
        try {
            self::write($file,$data);
        } finally {
            fclose($file);
        }
    }

    private static function key(string $directory): string
    {
        $hex = trim((string)file_get_contents($directory.'/key',false,null,0,128));
        if (!preg_match('/^[a-f0-9]{64}$/D',$hex)) throw new RuntimeException('Archive key is missing or invalid.');
        return hex2bin($hex);
    }

    private static function authenticate(array $manifest, string $key): string
    {
        return hash_hmac('sha256',implode(':',[$manifest['version'],$manifest['id'],$manifest['chunks'],$manifest['bytes'],$manifest['chain']]),$key);
    }

    private static function writeChunk($file, string $plain, string $key, string &$chain, int &$sequence, int &$bytes): void
    {
        $compressed = gzdeflate($plain,3);
        if ($compressed === false) throw new RuntimeException('Archive compression failed.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encoded = base64_encode($nonce.sodium_crypto_secretbox($compressed,$nonce,$key));
        self::write($file,$encoded."\n");
        $chain = hash('sha256',$chain.hash('sha256',$encoded));
        $sequence++;
        $bytes += strlen($encoded);
    }
}
