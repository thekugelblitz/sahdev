<?php
return ['default' => env('CACHE_STORE', 'database'), 'stores' => ['database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks'], 'array' => ['driver' => 'array', 'serialize' => false]], 'prefix' => 'sahdev_cloud_'];
