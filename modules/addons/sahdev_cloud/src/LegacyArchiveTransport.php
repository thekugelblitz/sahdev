<?php
namespace SahdevConnector;

use RuntimeException;

final class LegacyArchiveTransport
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function upload(string $directory, ?callable $progress = null): void
    {
        LegacyArchive::verify($directory);
        $manifest = LegacyArchive::manifest($directory);
        $base = '/api/v1/legacy-archives/'.$manifest['id'];
        $state = $this->client->request('POST',$base.'/start',[
            'chunks'=>$manifest['chunks'], 'bytes'=>$manifest['bytes'], 'chain'=>$manifest['chain'],
        ]);
        $received = $state['received_chunks'] ?? -1;
        if (!is_int($received) || $received < 0 || $received > $manifest['chunks']) {
            throw new RuntimeException('Cloud returned an invalid archive cursor.');
        }

        $chain = str_repeat('0',64);
        $sequence = 0;
        foreach (LegacyArchive::chunks($directory.'/chunks') as $encoded) {
            $sequence++;
            $chain = hash('sha256',$chain.hash('sha256',$encoded));
            if ($sequence <= $received) {
                if ($sequence === $received && !hash_equals($chain,(string)($state['received_chain'] ?? ''))) {
                    throw new RuntimeException('Cloud archive prefix differs from the local capture.');
                }
                continue;
            }
            $accepted = $this->client->request('POST',$base.'/chunks/'.$sequence,['ciphertext'=>$encoded]);
            if (($accepted['received_chunks'] ?? null) !== $sequence || !hash_equals($chain,(string)($accepted['received_chain'] ?? ''))) {
                throw new RuntimeException('Cloud archive acknowledgement differs. Retry the same archive.');
            }
            if ($progress && ($sequence % 50 === 0 || $sequence === $manifest['chunks'])) $progress($sequence,$manifest['chunks']);
            usleep(600000); // Leave room under the installation rate limit for ticket workflow traffic.
        }
        $complete = $this->client->request('POST',$base.'/complete');
        if (empty($complete['completed'])) throw new RuntimeException('The cloud has not confirmed completion. Retry the same archive.');
    }

    public function recover(string $directory, ?callable $progress = null): array
    {
        $manifest = LegacyArchive::manifest($directory);
        $target = $directory.'/chunks';
        if (file_exists($target) || is_link($target)) throw new RuntimeException('Use a recovery folder containing only the original manifest and key.');
        $temporary = $directory.'/recovery-'.bin2hex(random_bytes(8)).'.partial';
        $file = LegacyArchive::createFile($temporary);
        try {
            try {
                for ($sequence = 1; $sequence <= $manifest['chunks']; $sequence++) {
                    $response = $this->client->request('GET','/api/v1/legacy-archives/'.$manifest['id'].'/chunks/'.$sequence);
                    $encoded = $response['ciphertext'] ?? null;
                    if (!is_string($encoded) || !hash_equals(hash('sha256',$encoded),(string)($response['digest'] ?? ''))) {
                        throw new RuntimeException('Cloud returned a damaged chunk.');
                    }
                    LegacyArchive::write($file,$encoded."\n");
                    if ($progress && ($sequence % 50 === 0 || $sequence === $manifest['chunks'])) $progress($sequence,$manifest['chunks']);
                    usleep(600000);
                }
            } finally {
                fclose($file);
            }
            $summary = LegacyArchive::verify($directory,$temporary);
            // Link only after verifying contents. link() cannot overwrite an archive created by a concurrent recovery.
            if (!@link($temporary,$target)) throw new RuntimeException('Cannot publish the recovered file. Check the path and filesystem.');
            return $summary;
        } finally {
            @unlink($temporary);
        }
    }
}
