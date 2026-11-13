<?php
return ['default' => env('QUEUE_CONNECTION', 'database'), 'connections' => ['database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 150, 'after_commit' => false], 'sync' => ['driver' => 'sync']], 'failed' => ['driver' => 'database-uuids', 'database' => env('DB_CONNECTION', 'pgsql'), 'table' => 'failed_jobs']];
