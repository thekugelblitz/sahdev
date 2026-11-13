<?php
return ['default' => env('LOG_CHANNEL', 'stderr'), 'channels' => ['stderr' => ['driver' => 'monolog', 'handler' => Monolog\Handler\StreamHandler::class, 'with' => ['stream' => 'php://stderr'], 'level' => 'notice'], 'daily' => ['driver' => 'daily', 'path' => storage_path('logs/cloud.log'), 'level' => 'notice', 'days' => 14]]];
