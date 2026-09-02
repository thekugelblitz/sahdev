<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/ModuleLogger.php';

/**
 * ServerTelemetryService
 *
 * Proactively polls server health, service daemons, CPU/RAM/Swap/Disk partitions,
 * and hosted account quotas for cPanel/WHM, Virtualizor, Plesk, and DirectAdmin.
 * Compatible with reseller accounts. Caches snapshots in tblsahdev_server_telemetry.
 */
class ServerTelemetryService
{
    private const DEFAULT_CACHE_TTL_MINS = 15;
    private const HTTP_TIMEOUT_SEC = 7;

    /**
     * Poll all active servers in WHMCS.
     */
    public static function pollActiveServers(bool $forcePoll = false): array
    {
        $summary = [
            'polled' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        try {
            if (!Capsule::schema()->hasTable('tblservers') || !Capsule::schema()->hasTable('tblsahdev_server_telemetry')) {
                return $summary;
            }

            $settings = Capsule::table('tblsahdev_settings')->first();
            if ($settings && empty($settings->telemetry_enabled)) {
                return $summary;
            }

            $cacheCutoff = Carbon::now()->subMinutes(self::DEFAULT_CACHE_TTL_MINS);

            // Fetch all active servers in WHMCS
            $servers = Capsule::table('tblservers')
                ->where('disabled', 0)
                ->get();

            if ($servers->isEmpty()) {
                return $summary;
            }

            foreach ($servers as $srv) {
                $serverId = (int) $srv->id;

                if (!$forcePoll) {
                    $recent = Capsule::table('tblsahdev_server_telemetry')
                        ->where('server_id', $serverId)
                        ->where('last_polled_at', '>', $cacheCutoff)
                        ->first();
                    if ($recent) {
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

        $telemetry = [
            'load' => null,
            'is_reachable' => false,
            'error' => null,
            'accounts' => [],
            'stats' => [],
            'services' => [],
            'disks' => [],
            'flagged_items' => [],
        ];

        if (strpos($serverType, 'virtualizor') !== false) {
            $telemetry = self::pollVirtualizorServer($server);
        } elseif (strpos($serverType, 'cpanel') !== false) {
            $telemetry = self::pollCpanelServer($server);
        } elseif (strpos($serverType, 'plesk') !== false) {
            $telemetry = self::pollPleskServer($server);
        } elseif (strpos($serverType, 'directadmin') !== false) {
            $telemetry = self::pollDirectAdminServer($server);
        } else {
            $telemetry = self::pollGenericServer($server);
        }

        $now = Carbon::now();
        $statsPayload = array_merge($telemetry['stats'] ?? [], [
            'services' => $telemetry['services'] ?? [],
            'disks' => $telemetry['disks'] ?? [],
            'flagged_items' => $telemetry['flagged_items'] ?? [],
        ]);

        Capsule::table('tblsahdev_server_telemetry')->updateOrInsert(
            ['server_id' => $serverId],
            [
                'server_name' => $serverName,
                'server_host' => $serverHost,
                'server_type' => $serverType,
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
     * Comprehensive cPanel / WHM Poller.
     * Fetches Services, Load, CPU Count, RAM, Swap, Disk Mounts (/tmp, /, etc.), and Accounts.
     */
    private static function pollCpanelServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $secure = !isset($server->secure) || $server->secure === 'on' || $server->secure === '1' || $server->secure === 1 || $server->secure === true;
        $port = !empty($server->port) ? (int) $server->port : ($secure ? 2087 : 2086);
        $user = trim((string) ($server->username ?? ''));
        $pass = !empty($server->password) ? decrypt($server->password) : '';
        $token = trim((string) ($server->accesshash ?? ''));

        if (empty($host) || (empty($pass) && empty($token))) {
            return [
                'load' => null,
                'is_reachable' => false,
                'error' => 'Missing server host or authentication credentials.',
                'accounts' => [],
                'stats' => [],
                'services' => [],
                'disks' => [],
                'flagged_items' => ['Missing authentication credentials (WHM Access Token / Password)'],
            ];
        }

        $scheme = $secure ? 'https://' : 'http://';
        $baseUrl = "{$scheme}{$host}:{$port}/json-api/";

        // Build authorization headers
        $authHeaders = [];
        if ($token !== '') {
            $cleanToken = preg_replace('/\r|\n/', '', trim($token));
            $authHeaders[] = "Authorization: whm {$user}:{$cleanToken}";
        }
        if ($user !== '' && $pass !== '') {
            $authHeaders[] = "Authorization: Basic " . base64_encode("{$user}:{$pass}");
        }
        $authHeaders[] = 'Accept: application/json';

        $services = [];
        $disks = [];
        $stats = [];
        $accounts = [];
        $flagged = [];
        $reachable = false;
        $lastError = null;

        // 1. Fetch System Load
        $loadStr = null;
        $loadRes = self::curlGet($baseUrl . 'systemloadavg?api.version=1', $authHeaders);
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

        // 2. Fetch Service Status daemons (httpd, mysql, exim, ftpd, cpdavd, named, etc.)
        $servRes = self::curlGet($baseUrl . 'servicestatus?api.version=1', $authHeaders);
        if ($servRes['ok'] && !empty($servRes['data'])) {
            $reachable = true;
            $json = json_decode($servRes['data'], true);
            $serviceList = $json['data']['service'] ?? [];

            foreach ($serviceList as $s) {
                $name = (string) ($s['name'] ?? '');
                if ($name === '') continue;

                $installed = !empty($s['installed']);
                $monitored = !empty($s['monitored']);
                $running = !empty($s['running']);
                $version = !empty($s['version']) ? " ({$s['version']})" : '';

                if (!$installed && !$monitored && !$running) {
                    continue;
                }

                $statusText = $running ? 'up' : 'down';
                $isOk = $running;
                $message = "“{$name}” is " . ($isOk ? 'ok.' : 'DOWN.');

                $services[] = [
                    'name' => $name,
                    'details' => $statusText . $version,
                    'status' => $isOk ? 'ok' : 'critical',
                    'message' => $message,
                ];

                if (!$isOk && $monitored) {
                    $flagged[] = "🚨 Service '{$name}' is DOWN";
                }
            }
        }

        // 3. Fetch Disk Usage & Partitions (/tmp, /, /var/tmp, /boot/efi, etc.)
        $diskRes = self::curlGet($baseUrl . 'get_disk_usage?api.version=1', $authHeaders);
        if ($diskRes['ok'] && !empty($diskRes['data'])) {
            $reachable = true;
            $json = json_decode($diskRes['data'], true);
            $partitionList = $json['data']['partition'] ?? [];

            foreach ($partitionList as $p) {
                $mount = (string) ($p['mount'] ?? $p['mountpoint'] ?? '');
                if ($mount === '') continue;

                $percentVal = (int) preg_replace('/[^0-9]/', '', (string) ($p['percentage'] ?? '0'));
                $total = (string) ($p['total'] ?? $p['size'] ?? '');
                $used = (string) ($p['used'] ?? '');
                $humanSize = ($used !== '' && $total !== '') ? " ({$used} / {$total})" : '';

                $status = 'ok';
                $msg = "“Disk {$mount} ({$mount})” is ok.";
                if ($percentVal >= 90) {
                    $status = 'critical';
                    $msg = "“Disk {$mount} ({$mount})” is CRITICALLY FULL ({$percentVal}%).";
                    $flagged[] = "⚠️ Disk {$mount} is {$percentVal}% full (Critical)";
                } elseif ($percentVal >= 80) {
                    $status = 'warning';
                    $msg = "“Disk {$mount} ({$mount})” is reporting warnings ({$percentVal}%).";
                    $flagged[] = "⚠️ Disk {$mount} is {$percentVal}% full (Warning)";
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

        // 4. Fetch System Resource Details (CPU Count, Memory, Swap)
        $sysRes = self::curlGet($baseUrl . 'get_system_information?api.version=1', $authHeaders);
        if ($sysRes['ok'] && !empty($sysRes['data'])) {
            $json = json_decode($sysRes['data'], true);
            $sysData = $json['data'] ?? [];

            if (!empty($sysData['cpu_count'])) {
                $stats['cpu_count'] = (int) $sysData['cpu_count'];
            }
            if (!empty($sysData['memory_used_percent'])) {
                $stats['memory_used_percent'] = (float) $sysData['memory_used_percent'];
            }
            if (!empty($sysData['swap_used_percent'])) {
                $stats['swap_used_percent'] = (float) $sysData['swap_used_percent'];
            }
        }

        // 5. Fetch Accounts List (Reseller listaccts)
        $acctRes = self::curlGet($baseUrl . 'listaccts?api.version=1', $authHeaders);
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
            }
        }

        // Flag high load if applicable
        if (isset($stats['server_load_val']) && !empty($stats['cpu_count'])) {
            if ($stats['server_load_val'] > ($stats['cpu_count'] * 2.5)) {
                $flagged[] = "🔥 High Server Load: {$stats['server_load_val']} on {$stats['cpu_count']} CPUs";
            }
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $reachable,
            'error' => !$reachable ? ($lastError ?: 'Connection timed out on WHM port ' . $port) : null,
            'accounts' => $accounts,
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_items' => $flagged,
        ];
    }

    /**
     * Virtualizor Master/Slave Node Poller.
     * Fetches Node CPU, RAM, Disk utilization, Virtualizor status, and hosted VPS instances.
     */
    private static function pollVirtualizorServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $port = !empty($server->port) ? (int) $server->port : 4085;
        $secure = !isset($server->secure) || $server->secure === 'on' || $server->secure === '1' || $server->secure === 1 || $server->secure === true;
        $scheme = $secure ? 'https://' : 'http://';

        $apiKey = trim((string) ($server->username ?? ''));
        $apiPass = !empty($server->password) ? decrypt($server->password) : trim((string) ($server->accesshash ?? ''));

        if (empty($host) || empty($apiKey) || empty($apiPass)) {
            // Attempt generic connection test
            return self::pollGenericVirtualizor($host, $port, $scheme);
        }

        $baseUrl = "{$scheme}{$host}:{$port}/index.php?api=json&apikey=" . urlencode($apiKey) . "&apipass=" . urlencode($apiPass);

        $services = [];
        $disks = [];
        $stats = [];
        $accounts = [];
        $flagged = [];
        $reachable = false;
        $loadStr = null;

        // 1. Fetch Server Info & Stats
        $statRes = self::curlGet($baseUrl . "&act=server_stats");
        if ($statRes['ok'] && !empty($statRes['data'])) {
            $reachable = true;
            $json = json_decode($statRes['data'], true);

            $statsData = $json['server_stats'] ?? $json ?? [];
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
                $disks[] = [
                    'name' => 'Storage Pool (/)',
                    'mount' => '/',
                    'percent' => (int) $statsData['disk_percent'],
                    'details' => ((int) $statsData['disk_percent']) . '%',
                    'status' => (int) $statsData['disk_percent'] >= 90 ? 'critical' : ((int) $statsData['disk_percent'] >= 80 ? 'warning' : 'ok'),
                    'message' => "Virtualizor Storage is at " . ((int) $statsData['disk_percent']) . "%",
                ];
            }
        }

        // 2. Fetch VPS Instances (act=vs)
        $vsRes = self::curlGet($baseUrl . "&act=vs");
        if ($vsRes['ok'] && !empty($vsRes['data'])) {
            $reachable = true;
            $json = json_decode($vsRes['data'], true);
            $vpsList = $json['vps'] ?? [];

            $services[] = [
                'name' => 'Virtualizor Daemon',
                'details' => 'up (v4.x)',
                'status' => 'ok',
                'message' => 'Virtualizor Node daemon is running.',
            ];

            foreach ($vpsList as $vpsId => $v) {
                $vHost = (string) ($v['hostname'] ?? $v['vps_name'] ?? 'VPS #' . $vpsId);
                $statusVal = (int) ($v['status'] ?? 1);
                $isOnline = ($statusVal === 1);
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
                    'suspend_reason' => $isSusp ? 'Suspended in Virtualizor' : '',
                    'plan' => "RAM: {$ram}, Disk: {$disk}",
                ];
            }
        }

        if (!$reachable) {
            $generic = self::pollGenericVirtualizor($host, $port, $scheme);
            return $generic;
        }

        return [
            'load' => $loadStr,
            'is_reachable' => true,
            'error' => null,
            'accounts' => $accounts,
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_items' => $flagged,
        ];
    }

    /**
     * Fallback probe for Virtualizor port 4085 / 4084 / 4082.
     */
    private static function pollGenericVirtualizor(string $host, int $port, string $scheme): array
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 3);
        if (!is_resource($fp) && $port === 4085) {
            // try 4084
            $fp = @fsockopen($host, 4084, $errno, $errstr, 3);
        }
        $reachable = is_resource($fp);
        if ($reachable) {
            fclose($fp);
        }

        return [
            'load' => null,
            'is_reachable' => $reachable,
            'error' => !$reachable ? "Virtualizor node unreachable on port {$port} ({$errstr})" : null,
            'accounts' => [],
            'stats' => [],
            'services' => [
                ['name' => 'Virtualizor Node', 'details' => $reachable ? 'Port open' : 'down', 'status' => $reachable ? 'ok' : 'critical', 'message' => $reachable ? 'Virtualizor port responsive' : 'Node port connection refused']
            ],
            'disks' => [],
            'flagged_items' => !$reachable ? ["Virtualizor node unreachable on {$host}:{$port}"] : [],
        ];
    }

    /**
     * Plesk REST Poller
     */
    private static function pollPleskServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $pass = !empty($server->password) ? decrypt($server->password) : '';
        $user = trim((string) ($server->username ?? ''));

        if (empty($host) || empty($user) || empty($pass)) {
            return ['load' => null, 'is_reachable' => false, 'error' => 'Missing Plesk credentials', 'accounts' => [], 'stats' => [], 'services' => [], 'disks' => [], 'flagged_items' => ['Missing credentials']];
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
        $flagged = [];

        if ($res['ok'] && !empty($res['data'])) {
            $json = json_decode($res['data'], true);
            if (isset($json['stat']['loadAvg'])) {
                $loadStr = (string) $json['stat']['loadAvg'];
                $stats['load_one'] = (float) $loadStr;
            }
            $services[] = [
                'name' => 'Plesk Core Engine',
                'details' => 'up',
                'status' => 'ok',
                'message' => 'Plesk API engine is responsive.',
            ];
        } else {
            $flagged[] = "Plesk server unreachable or auth failed";
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $reachable,
            'error' => $res['error'],
            'accounts' => [],
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_items' => $flagged,
        ];
    }

    /**
     * DirectAdmin API Poller
     */
    private static function pollDirectAdminServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $user = trim((string) ($server->username ?? ''));
        $pass = !empty($server->password) ? decrypt($server->password) : '';

        if (empty($host) || empty($user) || empty($pass)) {
            return ['load' => null, 'is_reachable' => false, 'error' => 'Missing DirectAdmin credentials', 'accounts' => [], 'stats' => [], 'services' => [], 'disks' => [], 'flagged_items' => ['Missing credentials']];
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
        $flagged = [];

        if ($res['ok'] && !empty($res['data'])) {
            parse_str($res['data'], $parsed);
            if (!empty($parsed['LoadAvg'])) {
                $loadStr = (string) $parsed['LoadAvg'];
                $stats['load_one'] = (float) $loadStr;
            }
            $services[] = [
                'name' => 'DirectAdmin Core Engine',
                'details' => 'up',
                'status' => 'ok',
                'message' => 'DirectAdmin API is responsive.',
            ];
        } else {
            $flagged[] = "DirectAdmin unreachable or auth failed";
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $res['ok'],
            'error' => $res['error'],
            'accounts' => [],
            'stats' => $stats,
            'services' => $services,
            'disks' => $disks,
            'flagged_items' => $flagged,
        ];
    }

    /**
     * Generic ICMP / TCP port probe
     */
    private static function pollGenericServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $fp = @fsockopen($host, 80, $errno, $errstr, 3);
        if (!is_resource($fp)) {
            $fp = @fsockopen($host, 443, $errno, $errstr, 3);
        }
        $reachable = is_resource($fp);
        if ($reachable) {
            fclose($fp);
        }

        return [
            'load' => null,
            'is_reachable' => $reachable,
            'error' => !$reachable ? "Host unreachable ({$errstr})" : null,
            'accounts' => [],
            'stats' => [],
            'services' => [],
            'disks' => [],
            'flagged_items' => !$reachable ? ["Host unreachable on port 80/443"] : [],
        ];
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
            'flagged_items' => $statsParsed['flagged_items'] ?? [],
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

        // Include any disk warnings if present
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
