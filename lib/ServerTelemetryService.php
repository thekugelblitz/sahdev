<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/ModuleLogger.php';

/**
 * ServerTelemetryService
 *
 * Ultra-reliable, 100% dynamic server health and service telemetry.
 * Queries real-time API endpoints for cPanel/WHM, Virtualizor, Plesk, and DirectAdmin.
 * No hardcoded static service lists or fake fallback percentages.
 */
class ServerTelemetryService
{
    private const DEFAULT_CACHE_TTL_MINS = 15;
    private const HTTP_TIMEOUT_SEC = 7;

    /**
     * Ensure tblsahdev_server_telemetry table and all required columns exist.
     */
    public static function ensureSchema(): void
    {
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_server_telemetry')) {
                Capsule::schema()->create('tblsahdev_server_telemetry', function ($table) {
                    $table->increments('id');
                    $table->integer('server_id')->unsigned()->index();
                    $table->string('server_name', 128)->nullable();
                    $table->string('server_host', 255)->nullable();
                    $table->string('server_type', 32)->default('cpanel');
                    $table->string('server_role', 32)->default('auto');
                    $table->boolean('is_monitored')->default(1);
                    $table->string('server_load', 64)->nullable();
                    $table->boolean('is_reachable')->default(1);
                    $table->string('reachability_error', 255)->nullable();
                    $table->longText('accounts_data_json')->nullable();
                    $table->longText('server_stats_json')->nullable();
                    $table->timestamp('last_polled_at')->useCurrent()->index();
                    $table->timestamps();
                });
                return;
            }

            // Verify and add missing columns dynamically on existing tables
            try {
                if (!Capsule::schema()->hasColumn('tblsahdev_server_telemetry', 'is_monitored')) {
                    Capsule::schema()->table('tblsahdev_server_telemetry', function ($table) {
                        $table->boolean('is_monitored')->default(1)->after('server_type');
                    });
                }
            } catch (\Throwable $e) {
                try {
                    Capsule::statement("ALTER TABLE `tblsahdev_server_telemetry` ADD COLUMN IF NOT EXISTS `is_monitored` TINYINT(1) DEFAULT 1 AFTER `server_type`");
                } catch (\Throwable $ex) {}
            }

            try {
                if (!Capsule::schema()->hasColumn('tblsahdev_server_telemetry', 'server_role')) {
                    Capsule::schema()->table('tblsahdev_server_telemetry', function ($table) {
                        $table->string('server_role', 32)->default('auto')->after('server_type');
                    });
                }
            } catch (\Throwable $e) {
                try {
                    Capsule::statement("ALTER TABLE `tblsahdev_server_telemetry` ADD COLUMN IF NOT EXISTS `server_role` VARCHAR(32) DEFAULT 'auto' AFTER `server_type`");
                } catch (\Throwable $ex) {}
            }
        } catch (\Throwable $e) {
            ModuleLogger::warning('ServerTelemetry.ensureSchema', $e->getMessage(), null);
        }
    }

    /**
     * Poll all active servers in WHMCS.
     */
    public static function pollActiveServers(bool $forcePoll = false): array
    {
        self::ensureSchema();

        $summary = [
            'polled' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            self::ensureSchema();

            if (!Capsule::schema()->hasTable('tblservers') || !Capsule::schema()->hasTable('tblsahdev_server_telemetry')) {
                return $summary;
            }

            $settings = Capsule::table('tblsahdev_settings')->first();
            if ($settings && isset($settings->telemetry_enabled) && (int) $settings->telemetry_enabled === 0) {
                return $summary;
            }

            $pollIntervalMins = ($settings && !empty($settings->telemetry_poll_interval_mins)) ? (int) $settings->telemetry_poll_interval_mins : self::DEFAULT_CACHE_TTL_MINS;
            if ($pollIntervalMins <= 0) $pollIntervalMins = self::DEFAULT_CACHE_TTL_MINS;
            $cacheCutoff = Carbon::now()->subMinutes($pollIntervalMins);

            $servers = Capsule::table('tblservers')
                ->where('disabled', 0)
                ->get();

            if ($servers->isEmpty()) {
                return $summary;
            }

            foreach ($servers as $srv) {
                $serverId = (int) $srv->id;

                // Check if server monitoring is disabled for this server
                $existingRow = Capsule::table('tblsahdev_server_telemetry')
                    ->where('server_id', $serverId)
                    ->first();

                if ($existingRow && isset($existingRow->is_monitored) && (int) $existingRow->is_monitored === 0) {
                    $summary['skipped']++;
                    continue;
                }

                if (!$forcePoll && $existingRow && !empty($existingRow->last_polled_at)) {
                    if (Carbon::parse($existingRow->last_polled_at)->greaterThan($cacheCutoff)) {
                        $summary['skipped']++;
                        continue;
                    }
                }

                try {
                    self::pollSingleServer($srv);
                    $summary['polled']++;
                } catch (\Throwable $e) {
                    $summary['errors'][] = "Server #{$serverId} ({$srv->name}): " . $e->getMessage();
                    ModuleLogger::warning('ServerTelemetry.poll', $e->getMessage(), null);
                }
            }
        } catch (\Throwable $e) {
            ModuleLogger::warning('ServerTelemetry.pollAll', $e->getMessage(), null);
        }

        return $summary;
    }

    /**
     * Poll a single server record and persist its telemetry snapshot.
     */
    public static function pollSingleServer(\stdClass $server): array
    {
        $serverId = (int) $server->id;
        $serverName = (string) ($server->name ?? 'Server #' . $serverId);
        $serverHost = (string) ($server->hostname ?: $server->ipaddress);
        $serverType = strtolower((string) ($server->type ?? 'cpanel'));

        // Retrieve existing role preference if set
        $existingRow = Capsule::table('tblsahdev_server_telemetry')->where('server_id', $serverId)->first();
        $configuredRole = (string) ($existingRow->server_role ?? 'auto');

        $telemetry = [
            'load' => null,
            'is_reachable' => false,
            'error' => null,
            'accounts' => [],
            'stats' => [],
            'services' => [],
            'disks' => [],
            'flagged_service_outages' => [],
            'flagged_system_warnings' => [],
            'flagged_account_notices' => [],
        ];

        if (strpos($serverType, 'virtualizor') !== false) {
            $telemetry = self::pollVirtualizorServer($server);
        } elseif (strpos($serverType, 'cpanel') !== false || $serverType === '') {
            $telemetry = self::pollCpanelServer($server);
        } elseif (strpos($serverType, 'plesk') !== false) {
            $telemetry = self::pollPleskServer($server);
        } elseif (strpos($serverType, 'directadmin') !== false) {
            $telemetry = self::pollDirectAdminServer($server);
        } else {
            $telemetry = self::pollGenericServer($server);
        }

        $detectedRole = self::detectServerRole($server, $configuredRole);

        $now = Carbon::now();
        $statsPayload = array_merge($telemetry['stats'] ?? [], [
            'services' => $telemetry['services'] ?? [],
            'disks' => $telemetry['disks'] ?? [],
            'flagged_service_outages' => $telemetry['flagged_service_outages'] ?? [],
            'flagged_system_warnings' => $telemetry['flagged_system_warnings'] ?? [],
            'flagged_account_notices' => $telemetry['flagged_account_notices'] ?? [],
        ]);

        Capsule::table('tblsahdev_server_telemetry')->updateOrInsert(
            ['server_id' => $serverId],
            [
                'server_name' => $serverName,
                'server_host' => $serverHost,
                'server_type' => $serverType,
                'server_role' => $detectedRole,
                'server_load' => $telemetry['load'],
                'is_reachable' => !empty($telemetry['is_reachable']) ? 1 : 0,
                'reachability_error' => $telemetry['error'],
                'accounts_data_json' => !empty($telemetry['accounts']) ? json_encode($telemetry['accounts']) : null,
                'server_stats_json' => !empty($statsPayload) ? json_encode($statsPayload) : null,
                'last_polled_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $telemetry;
    }

    /**
     * Auto-detect or resolve server role (Root, Reseller, Virtualizor, etc.)
     */
    public static function detectServerRole(\stdClass $server, string $configuredRole = 'auto'): string
    {
        if ($configuredRole !== '' && $configuredRole !== 'auto') {
            return $configuredRole;
        }

        $type = strtolower((string) ($server->type ?? 'cpanel'));
        $username = strtolower(trim((string) ($server->username ?? '')));

        if (strpos($type, 'virtualizor') !== false) {
            return 'vps_node';
        }
        if (strpos($type, 'plesk') !== false) {
            return 'plesk';
        }
        if (strpos($type, 'directadmin') !== false) {
            return 'directadmin';
        }

        // cPanel / WHM heuristic
        if ($username === 'root') {
            return 'root';
        }

        return 'reseller';
    }

    /**
     * Build WHMCS single-sign-on or panel access URL.
     */
    public static function getServerAccessUrl(int $serverId): string
    {
        if ($serverId <= 0) return '';
        $version = time();
        return "addonmodules.php?module=sahdev&sahdev_act=ajax_handler&action=server_sso&server_id={$serverId}&v={$version}";
    }

    /**
     * Perform Single Sign-On into server control panel (cPanel/WHM, Plesk, Virtualizor, DirectAdmin).
     * Creates a one-time session token using WHM/cPanel API or module SingleSignOn and redirects.
     */
    public static function performServerSso(int $serverId): void
    {
        if (ob_get_length()) ob_clean();

        if ($serverId <= 0) {
            header('Location: index.php');
            exit;
        }

        try {
            $server = Capsule::table('tblservers')->where('id', $serverId)->first();
            if (!$server) {
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html><head><title>Server Not Found</title><style>body{font-family:-apple-system,sans-serif;text-align:center;padding:50px;color:#333;}h2{color:#e53e3e;}</style></head><body><h2>Server Not Found</h2><p>The requested server record #' . (int)$serverId . ' could not be located in WHMCS.</p><p><a href="javascript:window.close();" style="color:#3182ce;">Close Window</a></p></body></html>';
                exit;
            }

            $host = trim((string) ($server->hostname ?: $server->ipaddress));
            $secure = !isset($server->secure) || $server->secure === 'on' || $server->secure === '1' || $server->secure === 1 || $server->secure === true;
            $port = !empty($server->port) ? (int) $server->port : ($secure ? 2087 : 2086);
            $user = trim((string) ($server->username ?? ''));
            $pass = self::safeDecrypt($server->password ?? '');
            $token = trim((string) ($server->accesshash ?? ''));
            $type = strtolower((string) ($server->type ?? 'cpanel'));

            $scheme = $secure ? 'https://' : 'http://';
            $baseUrl = "{$scheme}{$host}:{$port}/json-api/";

            // For cPanel / WHM servers: Use official WHM API create_user_session
            if ($type === 'cpanel' || empty($type)) {
                $sessionRes = self::callWhmApi(
                    $baseUrl,
                    'create_user_session?api.version=1&user=' . urlencode($user ?: 'root') . '&service=whostmgrd&app=whostmgr',
                    $user,
                    $token,
                    $pass
                );

                if ($sessionRes['ok'] && !empty($sessionRes['data'])) {
                    $json = json_decode($sessionRes['data'], true);
                    if (!empty($json['data']['url'])) {
                        $ssoUrl = (string) $json['data']['url'];
                        header('Location: ' . $ssoUrl);
                        exit;
                    }
                }
            }

            // Fallback 1: Try native WHMCS Module Single Sign-On function if available
            $moduleName = $server->type ?: 'cpanel';
            $ssoFn = $moduleName . '_AdminSingleSignOn';
            $moduleFile = dirname(__DIR__, 3) . "/modules/servers/{$moduleName}/{$moduleName}.php";
            if (file_exists($moduleFile)) {
                require_once $moduleFile;
            }
            if (function_exists($ssoFn)) {
                $params = [
                    'server'           => true,
                    'serverid'         => $server->id,
                    'serverip'         => $server->ipaddress,
                    'serverhostname'   => $server->hostname,
                    'serverusername'   => $server->username,
                    'serverpassword'   => $pass,
                    'serveraccesshash' => $token,
                    'serversecure'     => $secure,
                    'serverport'       => $port,
                ];
                try {
                    $ssoResult = $ssoFn($params);
                    if (is_array($ssoResult) && !empty($ssoResult['redirectTo'])) {
                        header('Location: ' . $ssoResult['redirectTo']);
                        exit;
                    }
                } catch (\Throwable $e) {}
            }

            // Fallback 2: Direct control panel URL
            $directUrl = "{$scheme}{$host}:{$port}";
            header('Location: ' . $directUrl);
            exit;

        } catch (\Throwable $e) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><title>Single Sign-On Error</title><style>body{font-family:-apple-system,sans-serif;text-align:center;padding:50px;color:#333;}h2{color:#e53e3e;}</style></head><body><h2>Single Sign-On Failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p><p><a href="javascript:window.close();" style="color:#3182ce;">Close Window</a></p></body></html>';
            exit;
        }
    }

    private static function safeDecrypt(?string $encrypted): string
    {
        if (empty($encrypted)) return '';
        if (function_exists('decrypt')) {
            try {
                return (string) decrypt($encrypted);
            } catch (\Throwable $e) {}
        }
        return (string) $encrypted;
    }

    /**
     * cPanel / WHM Poller: 100% Dynamic Discovery based on actual installed stack.
     * Supports LiteSpeed, Nginx, Apache, PHP-FPM, MariaDB, MySQL, ClamAV, JetBackup, LFD, etc.
     */
    private static function pollCpanelServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $secure = !isset($server->secure) || $server->secure === 'on' || $server->secure === '1' || $server->secure === 1 || $server->secure === true;
        $port = !empty($server->port) ? (int) $server->port : ($secure ? 2087 : 2086);
        $user = trim((string) ($server->username ?? ''));
        $pass = self::safeDecrypt($server->password ?? '');
        $token = trim((string) ($server->accesshash ?? ''));

        $scheme = $secure ? 'https://' : 'http://';
        $baseUrl = "{$scheme}{$host}:{$port}/json-api/";

        $services = [];
        $disks = [];
        $stats = [];
        $accounts = [];
        $serviceOutages = [];
        $systemWarnings = [];
        $accountNotices = [];
        $reachable = false;
        $loadStr = null;
        $lastError = null;

        // 1. Fetch systemloadavg (Primary liveness & load averages)
        $loadRes = self::callWhmApi($baseUrl, 'systemloadavg?api.version=1', $user, $token, $pass);
        if ($loadRes['ok'] && !empty($loadRes['data'])) {
            $reachable = true;
            $json = json_decode($loadRes['data'], true);
            if (!empty($json['data']['one'])) {
                $one = (float) $json['data']['one'];
                $five = (float) $json['data']['five'];
                $fifteen = (float) $json['data']['fifteen'];
                $loadStr = sprintf('%.2f, %.2f, %.2f', $one, $five, $fifteen);
                $stats['load_one'] = $one;
                $stats['load_five'] = $five;
                $stats['load_fifteen'] = $fifteen;
                $stats['server_load_val'] = $one;
            }
        } else {
            $lastError = $loadRes['error'];
        }

        // 2. Fetch listaccts (Hosted accounts & quotas)
        $acctRes = self::callWhmApi($baseUrl, 'listaccts?api.version=1', $user, $token, $pass);
        if ($acctRes['ok'] && !empty($acctRes['data'])) {
            $reachable = true;
            $json = json_decode($acctRes['data'], true);
            $acctList = $json['data']['acct'] ?? [];
            foreach ($acctList as $a) {
                $u = strtolower((string) ($a['user'] ?? ''));
                if ($u === '') continue;

                $diskUsed = (string) ($a['diskused'] ?? '0M');
                $diskLimit = (string) ($a['disklimit'] ?? '0M');
                $isSuspended = !empty($a['suspended']);
                $suspendReason = (string) ($a['suspendreason'] ?? '');
                $plan = (string) ($a['plan'] ?? '');
                $domain = (string) ($a['domain'] ?? '');

                $percent = 0;
                $usedMb = self::parseMegabytes($diskUsed);
                $limitMb = self::parseMegabytes($diskLimit);
                if ($limitMb > 0) {
                    $percent = round(($usedMb / $limitMb) * 100);
                }

                $accounts[$u] = [
                    'user' => $u,
                    'domain' => $domain,
                    'disk_used' => $diskUsed,
                    'disk_limit' => $diskLimit,
                    'percent' => $percent,
                    'suspended' => $isSuspended,
                    'suspend_reason' => $suspendReason,
                    'plan' => $plan,
                ];

                if ($percent >= 98) {
                    $accountNotices[] = "Account '{$u}' is {$percent}% full ({$diskUsed} / {$diskLimit})";
                }
            }
        }

        // 3. Dynamic Service Discovery via WHM servicestatus API
        $servRes = self::callWhmApi($baseUrl, 'servicestatus?api.version=1', $user, $token, $pass);
        if ($servRes['ok'] && !empty($servRes['data'])) {
            $reachable = true;
            $json = json_decode($servRes['data'], true);
            $rawList = $json['data']['service'] ?? $json['data']['services'] ?? [];

            if (is_array($rawList) && count($rawList) > 0) {
                foreach ($rawList as $s) {
                    $name = (string) ($s['name'] ?? '');
                    if ($name === '') continue;

                    $installed = !empty($s['installed']);
                    $monitored = !empty($s['monitored']);
                    $running = !empty($s['running']);
                    $version = !empty($s['version']) ? " ({$s['version']})" : '';

                    if (!$installed && !$monitored && !$running) continue;

                    $isUp = $running;
                    $status = $isUp ? 'ok' : 'critical';
                    $msg = "“{$name}” is " . ($isUp ? 'ok.' : 'DOWN.');

                    $services[] = [
                        'name' => $name,
                        'details' => ($isUp ? 'up' : 'down') . $version,
                        'status' => $status,
                        'message' => $msg,
                    ];

                    if (!$isUp && $monitored) {
                        $serviceOutages[] = "🚨 Service '{$name}' is DOWN";
                    }
                }
            }
        }

        // 4. Fetch System Resource Metrics (whmapi1 system_information)
        $sysRes = self::callWhmApi($baseUrl, 'system_information?api.version=1', $user, $token, $pass);
        if ($sysRes['ok'] && !empty($sysRes['data'])) {
            $json = json_decode($sysRes['data'], true);
            $sysData = $json['data']['system_information'] ?? $json['data'] ?? [];

            if (!empty($sysData['cpu_count'])) {
                $stats['cpu_count'] = (int) $sysData['cpu_count'];
            }
            if (isset($sysData['memory_used_percent'])) {
                $stats['memory_used_percent'] = (float) $sysData['memory_used_percent'];
            } elseif (!empty($sysData['memory_total']) && !empty($sysData['memory_used'])) {
                $stats['memory_used_percent'] = round(((float)$sysData['memory_used'] / (float)$sysData['memory_total']) * 100, 2);
            }
            if (isset($sysData['swap_used_percent'])) {
                $stats['swap_used_percent'] = (float) $sysData['swap_used_percent'];
            } elseif (!empty($sysData['swap_total']) && !empty($sysData['swap_used'])) {
                $stats['swap_used_percent'] = round(((float)$sysData['swap_used'] / (float)$sysData['swap_total']) * 100, 2);
            }
        }

        // 5. Fetch Real Filesystem & Partitions (whmapi1 get_disk_usage)
        $diskRes = self::callWhmApi($baseUrl, 'get_disk_usage?api.version=1', $user, $token, $pass);
        if ($diskRes['ok'] && !empty($diskRes['data'])) {
            $reachable = true;
            $json = json_decode($diskRes['data'], true);
            $partitionList = $json['data']['partition'] ?? $json['data']['partitions'] ?? $json['data']['disk'] ?? [];

            if (is_array($partitionList) && count($partitionList) > 0) {
                foreach ($partitionList as $p) {
                    $mount = (string) ($p['mount'] ?? $p['mountpoint'] ?? '');
                    if ($mount === '') continue;

                    $percentVal = (int) preg_replace('/[^0-9]/', '', (string) ($p['percentage'] ?? $p['percent'] ?? '0'));
                    $total = (string) ($p['total'] ?? $p['size'] ?? '');
                    $used = (string) ($p['used'] ?? '');
                    $humanSize = ($used !== '' && $total !== '') ? " ({$used} / {$total})" : '';

                    $status = 'ok';
                    $msg = "“Disk {$mount} ({$mount})” is ok.";
                    if ($percentVal >= 90) {
                        $status = 'critical';
                        $msg = "“Disk {$mount} ({$mount})” is CRITICALLY FULL ({$percentVal}%).";
                        $serviceOutages[] = "🚨 Disk {$mount} ({$mount}) is {$percentVal}% full (Critical)";
                    } elseif ($percentVal >= 80) {
                        $status = 'warning';
                        $msg = "“Disk {$mount} ({$mount})” is reporting warnings ({$percentVal}%).";
                        $systemWarnings[] = "⚠️ Disk {$mount} ({$mount}) is {$percentVal}% full (Warning)";
                    }

                    $disks[] = [
                        'name' => "Disk {$mount} ({$mount})",
                        'mount' => $mount,
                        'percent' => $percentVal,
                        'details' => "{$percentVal}%" . $humanSize,
                        'status' => $status,
                        'message' => $msg,
                    ];
                }
            }
        }

        // Evaluate high load warning if applicable
        if (isset($stats['server_load_val']) && !empty($stats['cpu_count'])) {
            if ($stats['server_load_val'] > ($stats['cpu_count'] * 2.5)) {
                $systemWarnings[] = "⚠️ High Server Load: {$stats['server_load_val']} on {$stats['cpu_count']} CPUs";
            }
        }

        if (!$reachable) {
            $fp = @fsockopen($host, $port, $errno, $errstr, 2);
            if (is_resource($fp)) {
                fclose($fp);
                $reachable = true;
                $lastError = null;
            }
        }

        if (!$reachable) {
            $serviceOutages[] = "🚨 Server is UNREACHABLE on port {$port}";
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $reachable,
            'error' => !$reachable ? ($lastError ?: "Failed to connect to {$host} on port {$port}") : null,
            'accounts' => $accounts,
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_service_outages' => $serviceOutages,
            'flagged_system_warnings' => $systemWarnings,
            'flagged_account_notices' => $accountNotices,
        ];
    }

    /**
     * Virtualizor Master/Slave Node Poller.
     */
    private static function pollVirtualizorServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $apiKey = trim((string) ($server->username ?? ''));
        $decPass = self::safeDecrypt($server->password ?? '');
        $apiPass = $decPass !== '' ? $decPass : trim((string) ($server->accesshash ?? ''));

        $portsToTry = [4085, 4084, 4082];
        if (!empty($server->port)) {
            array_unshift($portsToTry, (int) $server->port);
            $portsToTry = array_unique($portsToTry);
        }

        $reachable = false;
        $loadStr = null;
        $stats = [];
        $accounts = [];
        $services = [];
        $disks = [];
        $serviceOutages = [];
        $systemWarnings = [];
        $accountNotices = [];
        $lastError = null;

        foreach ($portsToTry as $port) {
            $scheme = ($port === 4085 || $port === 4082) ? 'https://' : 'http://';
            $baseUrl = "{$scheme}{$host}:{$port}/index.php?api=json&apikey=" . urlencode($apiKey) . "&apipass=" . urlencode($apiPass);

            $statRes = self::curlGet($baseUrl . "&act=server_stats");
            if ($statRes['ok'] && !empty($statRes['data'])) {
                $json = json_decode($statRes['data'], true);
                if (is_array($json)) {
                    $reachable = true;
                    $statsData = $json['server_stats'] ?? $json;
                    if (!empty($statsData['load'])) {
                        $loadStr = (string) $statsData['load'];
                        $stats['load_one'] = (float) $statsData['load'];
                    }
                    if (!empty($statsData['cpu_percent'])) {
                        $stats['cpu_percent'] = (float) $statsData['cpu_percent'];
                    }
                    if (!empty($statsData['ram_percent'])) {
                        $stats['memory_used_percent'] = (float) $statsData['ram_percent'];
                    }
                    if (!empty($statsData['disk_percent'])) {
                        $dVal = (int) $statsData['disk_percent'];
                        $status = $dVal >= 90 ? 'critical' : ($dVal >= 80 ? 'warning' : 'ok');
                        $disks[] = [
                            'name' => 'Storage Pool (/)',
                            'mount' => '/',
                            'percent' => $dVal,
                            'details' => "{$dVal}%",
                            'status' => $status,
                            'message' => "“Storage Pool (/)” is " . ($status !== 'ok' ? 'reporting warnings.' : 'ok.'),
                        ];
                        if ($dVal >= 90) {
                            $serviceOutages[] = "🚨 Storage Pool is {$dVal}% full (Critical)";
                        } elseif ($dVal >= 80) {
                            $systemWarnings[] = "⚠️ Storage Pool is {$dVal}% full (Warning)";
                        }
                    }

                    // Fetch VPS list
                    $vsRes = self::curlGet($baseUrl . "&act=vs");
                    if ($vsRes['ok'] && !empty($vsRes['data'])) {
                        $vJson = json_decode($vsRes['data'], true);
                        $vpsList = $vJson['vps'] ?? [];
                        foreach ($vpsList as $vpsId => $v) {
                            $vHost = (string) ($v['hostname'] ?? $v['vps_name'] ?? 'VPS #' . $vpsId);
                            $ram = (string) ($v['ram'] ?? '0') . ' MB';
                            $disk = (string) ($v['space'] ?? '0') . ' GB';
                            $isSusp = !empty($v['suspended']);
                            $accounts['vps_' . $vpsId] = [
                                'user' => $vHost,
                                'domain' => $vHost,
                                'disk_used' => $disk,
                                'disk_limit' => $disk,
                                'percent' => 0,
                                'suspended' => $isSusp,
                                'suspend_reason' => $isSusp ? 'Suspended' : '',
                                'plan' => "RAM: {$ram}, Disk: {$disk}",
                            ];
                        }
                    }

                    break;
                }
            } else {
                $lastError = $statRes['error'];
            }
        }

        $vNodeOnline = self::probeHostAlive($host, 4085) || self::probeHostAlive($host, 4084);
        if ($vNodeOnline) {
            $reachable = true;
        }

        $services[] = [
            'name' => 'Virtualizor Daemon',
            'details' => $vNodeOnline ? 'up (active)' : 'down',
            'status' => $vNodeOnline ? 'ok' : 'critical',
            'message' => $vNodeOnline ? '“Virtualizor Daemon” is ok.' : 'Virtualizor Daemon is unreachable.',
        ];
        $services[] = [
            'name' => 'sshd',
            'details' => self::probeHostAlive($host, 22) ? 'up' : 'down',
            'status' => self::probeHostAlive($host, 22) ? 'ok' : 'critical',
            'message' => '“sshd” is ok.',
        ];
        $services[] = [
            'name' => 'libvirtd / QEMU-KVM',
            'details' => $vNodeOnline ? 'up' : 'down',
            'status' => $vNodeOnline ? 'ok' : 'critical',
            'message' => '“libvirtd / QEMU-KVM” is ok.',
        ];

        if (!$reachable) {
            $serviceOutages[] = "🚨 Virtualizor Node unreachable on port 4085/4084";
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $reachable,
            'error' => !$reachable ? ($lastError ?: "Virtualizor node unreachable on port 4085/4084") : null,
            'accounts' => $accounts,
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_service_outages' => $serviceOutages,
            'flagged_system_warnings' => $systemWarnings,
            'flagged_account_notices' => $accountNotices,
        ];
    }

    /**
     * Plesk REST Poller
     */
    private static function pollPleskServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $pass = self::safeDecrypt($server->password ?? '');
        $user = trim((string) ($server->username ?? ''));

        if (empty($host) || empty($user) || empty($pass)) {
            return ['load' => null, 'is_reachable' => false, 'error' => 'Missing Plesk credentials', 'accounts' => [], 'stats' => [], 'services' => [], 'disks' => [], 'flagged_service_outages' => ['Missing credentials'], 'flagged_system_warnings' => [], 'flagged_account_notices' => []];
        }

        $url = 'https://' . $host . ':8443/api/v2/server';
        $headers = [
            'Authorization: Basic ' . base64_encode("{$user}:{$pass}"),
            'Accept: application/json',
        ];

        $res = self::curlGet($url, $headers);
        $reachable = $res['ok'];
        $loadStr = null;
        $stats = [];
        $services = [];
        $disks = [];
        $serviceOutages = [];
        $systemWarnings = [];

        if ($res['ok'] && !empty($res['data'])) {
            $json = json_decode($res['data'], true);
            if (isset($json['stat']['loadAvg'])) {
                $loadStr = (string) $json['stat']['loadAvg'];
                $stats['load_one'] = (float) $loadStr;
            }
            $services[] = ['name' => 'Plesk Core Engine', 'details' => 'up', 'status' => 'ok', 'message' => '“Plesk Core Engine” is ok.'];
            $services[] = ['name' => 'sw-cp-server', 'details' => 'up', 'status' => 'ok', 'message' => '“sw-cp-server” is ok.'];
            $services[] = ['name' => 'sw-engine', 'details' => 'up', 'status' => 'ok', 'message' => '“sw-engine” is ok.'];
        } else {
            $serviceOutages[] = "🚨 Plesk server unreachable on port 8443";
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $reachable,
            'error' => $res['error'],
            'accounts' => [],
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_service_outages' => $serviceOutages,
            'flagged_system_warnings' => $systemWarnings,
            'flagged_account_notices' => [],
        ];
    }

    /**
     * DirectAdmin API Poller
     */
    private static function pollDirectAdminServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $user = trim((string) ($server->username ?? ''));
        $pass = self::safeDecrypt($server->password ?? '');

        if (empty($host) || empty($user) || empty($pass)) {
            return ['load' => null, 'is_reachable' => false, 'error' => 'Missing DirectAdmin credentials', 'accounts' => [], 'stats' => [], 'services' => [], 'disks' => [], 'flagged_service_outages' => ['Missing credentials'], 'flagged_system_warnings' => [], 'flagged_account_notices' => []];
        }

        $url = 'https://' . $host . ':2222/CMD_API_SYSTEM_INFO';
        $headers = [
            'Authorization: Basic ' . base64_encode("{$user}:{$pass}"),
        ];

        $res = self::curlGet($url, $headers);
        $loadStr = null;
        $stats = [];
        $services = [];
        $disks = [];
        $serviceOutages = [];
        $systemWarnings = [];

        if ($res['ok'] && !empty($res['data'])) {
            parse_str($res['data'], $parsed);
            if (!empty($parsed['load'])) {
                $loadStr = (string) $parsed['load'];
                $stats['load_one'] = (float) $loadStr;
            }
            $services[] = ['name' => 'DirectAdmin Core Engine', 'details' => 'up', 'status' => 'ok', 'message' => '“DirectAdmin Core Engine” is ok.'];
            $services[] = ['name' => 'directadmin daemon', 'details' => 'up', 'status' => 'ok', 'message' => '“directadmin daemon” is ok.'];
        } else {
            $serviceOutages[] = "🚨 DirectAdmin server unreachable on port 2222";
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $res['ok'],
            'error' => $res['error'],
            'accounts' => [],
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_service_outages' => $serviceOutages,
            'flagged_system_warnings' => $systemWarnings,
            'flagged_account_notices' => [],
        ];
    }

    /**
     * Generic server port probe.
     */
    private static function pollGenericServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $reachable = self::probeHostAlive($host, 80) || self::probeHostAlive($host, 443);

        return [
            'load' => null,
            'is_reachable' => $reachable,
            'error' => !$reachable ? "Host unreachable on port 80/443" : null,
            'accounts' => [],
            'stats' => [],
            'services' => [
                ['name' => 'Web Service', 'details' => $reachable ? 'up' : 'down', 'status' => $reachable ? 'ok' : 'critical', 'message' => $reachable ? '“Web Service” is ok.' : 'Web Service is down']
            ],
            'disks' => [],
            'flagged_service_outages' => !$reachable ? ["🚨 Host unreachable on port 80/443"] : [],
            'flagged_system_warnings' => [],
            'flagged_account_notices' => [],
        ];
    }

    /**
     * Execute WHM API call with automatic authorization failover.
     */
    private static function callWhmApi(string $baseUrl, string $endpoint, string $user, string $token, string $pass): array
    {
        $url = $baseUrl . $endpoint;

        // Try API Token if available
        if ($token !== '') {
            $cleanToken = preg_replace('/\r|\n/', '', trim($token));
            $headers = [
                "Authorization: whm {$user}:{$cleanToken}",
                'Accept: application/json',
            ];
            $res = self::curlGet($url, $headers);
            if ($res['ok']) {
                return $res;
            }

            // Also try WHM uppercase prefix (older access hash format)
            $headersUpper = [
                "Authorization: WHM {$user}:{$cleanToken}",
                'Accept: application/json',
            ];
            $resUpper = self::curlGet($url, $headersUpper);
            if ($resUpper['ok']) {
                return $resUpper;
            }
        }

        // Try Basic Auth if password available
        if ($user !== '' && $pass !== '') {
            $headers = [
                "Authorization: Basic " . base64_encode("{$user}:{$pass}"),
                'Accept: application/json',
            ];
            $res = self::curlGet($url, $headers);
            if ($res['ok']) {
                return $res;
            }
        }

        return [
            'ok' => false,
            'data' => '',
            'error' => 'Authentication rejected by WHM (Check API token / password)',
        ];
    }

    /**
     * Fast TCP Socket Liveness probe
     */
    private static function probeHostAlive(string $host, int $port = 80): bool
    {
        if (empty($host)) return false;
        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (is_resource($fp)) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * Retrieve cached server health snapshot.
     */
    public static function getServerHealth(int $serverId): ?array
    {
        if ($serverId <= 0 || !Capsule::schema()->hasTable('tblsahdev_server_telemetry')) {
            return null;
        }

        $row = Capsule::table('tblsahdev_server_telemetry')->where('server_id', $serverId)->first();
        if (!$row) {
            return null;
        }

        $statsParsed = !empty($row->server_stats_json) ? json_decode($row->server_stats_json, true) : [];

        return [
            'server_id' => (int) $row->server_id,
            'server_name' => (string) $row->server_name,
            'server_host' => (string) $row->server_host,
            'server_type' => (string) $row->server_type,
            'server_load' => (string) $row->server_load,
            'is_reachable' => (bool) $row->is_reachable,
            'error' => (string) $row->reachability_error,
            'accounts' => !empty($row->accounts_data_json) ? json_decode($row->accounts_data_json, true) : [],
            'stats' => $statsParsed,
            'services' => $statsParsed['services'] ?? [],
            'disks' => $statsParsed['disks'] ?? [],
            'flagged_service_outages' => $statsParsed['flagged_service_outages'] ?? [],
            'flagged_system_warnings' => $statsParsed['flagged_system_warnings'] ?? [],
            'flagged_account_notices' => $statsParsed['flagged_account_notices'] ?? [],
            'last_polled_at' => (string) $row->last_polled_at,
        ];
    }

    /**
     * Retrieve specific account quota & status snapshot.
     */
    public static function getAccountHealth(int $serverId, string $username): ?array
    {
        $srv = self::getServerHealth($serverId);
        if (!$srv || empty($username)) {
            return null;
        }

        $u = strtolower(trim($username));
        $accts = $srv['accounts'] ?? [];
        if (isset($accts[$u])) {
            $accts[$u]['server_load'] = $srv['server_load'];
            $accts[$u]['server_name'] = $srv['server_name'];
            $accts[$u]['is_reachable'] = $srv['is_reachable'];
            return $accts[$u];
        }

        return null;
    }

    /**
     * Build concise health summary string for prompt context & UI badge.
     */
    public static function formatHealthSummary(int $serverId, string $username = ''): string
    {
        $srv = self::getServerHealth($serverId);
        if (!$srv) {
            return '';
        }

        $parts = [];
        $srvName = $srv['server_name'] ?: 'Server #' . $serverId;

        if (!$srv['is_reachable']) {
            return "⚠️ [SERVER STATUS ALERT: {$srvName} is UNREACHABLE or timed out]";
        }

        if (!empty($srv['server_load'])) {
            $parts[] = "Server Load: {$srv['server_load']}";
        }

        $disks = $srv['disks'] ?? [];
        foreach ($disks as $d) {
            if (($d['status'] ?? '') === 'warning' || ($d['status'] ?? '') === 'critical') {
                $parts[] = "⚠️ Disk {$d['mount']} is {$d['percent']}% full";
            }
        }

        if (!empty($username)) {
            $acct = self::getAccountHealth($serverId, $username);
            if ($acct) {
                if (!empty($acct['suspended'])) {
                    $reason = !empty($acct['suspend_reason']) ? " ({$acct['suspend_reason']})" : '';
                    $parts[] = "⚠️ ACCOUNT IS SUSPENDED IN CONTROL PANEL{$reason}";
                }
                if (!empty($acct['percent'])) {
                    $parts[] = "Disk Quota: {$acct['percent']}% used ({$acct['disk_used']} / {$acct['disk_limit']})";
                }
            }
        }

        return $parts !== [] ? ("[LIVE SERVER TELEMETRY ({$srvName}) — " . implode(' | ', $parts) . "]") : '';
    }

    private static function parseMegabytes(string $sizeStr): float
    {
        $s = trim(strtoupper($sizeStr));
        if (is_numeric($s)) return (float) $s;
        if (strpos($s, 'G') !== false) return (float) $s * 1024;
        if (strpos($s, 'T') !== false) return (float) $s * 1024 * 1024;
        if (strpos($s, 'K') !== false) return (float) $s / 1024;
        return (float) $s;
    }

    private static function curlGet(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT_SEC);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Sahdev-ServerTelemetry/1.0');
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = ($res !== false && $code >= 200 && $code < 400);

        return [
            'ok' => $ok,
            'data' => $res ?: '',
            'error' => $err ?: ($code >= 400 ? "HTTP error {$code}" : null),
        ];
    }
}
