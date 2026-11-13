<?php
require_once __DIR__.'/../app/Services/ConnectorArchive.php';
\App\Services\ConnectorArchive::verify($argv[1] ?? '');
echo 'PASS exact thin connector archive: no legacy core, cloud source, providers, prompts, credentials or workspace files.'.PHP_EOL;
