<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/ModuleLogger.php';

/**
 * ServerTelemetryService
 *
 * Proactively polls server health and account quota metrics using
 * unprivileged / reseller-safe API endpoints (cPanel/WHM, Plesk, DirectAdmin).
 * Never requires root access. Caches snapshots in tblsahdev_server_telemetry.
 */
class ServerTelemetryService
{
    private const DEFAULT_CACHE_TTL_MINS = 15;
    private const HTTP_TIMEOUT_SEC = 6;

    /**
     * Poll all active servers or servers tied to open tickets.
     * @param bool $forcePoll
     * @return array
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

            // Fetch active servers in WHMCS
            $servers = Capsule::table('tblservers')
                ->where('disabled', 0)
                ->get();

            if ($servers->isEmpty()) {
                return $summary;
            }

            foreach ($servers as $srv) {
                $serverId = (int) $srv->id;

                // Check cache if not forcing
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
     * Poll a single server record.
     */
    public static function pollSingleServer(\stdClass $server): array
    {
        $serverId = (int) $server->id;
        $serverName = (string) ($server->name ?? 'Server #' . $serverId);
        $serverHost = (string) ($server->hostname ?: $server->ipaddress);
        $serverType = strtolower((string) ($server->type ?? 'cpanel'));

        $telemetry = [
            'load' => null,
            'is_reachable' => true,
            'error' => null,
            'accounts' => [],
            'stats' => [],
        ];

        switch ($serverType) {
            case 'cpanel':
            case 'cpanelwhm':
                $telemetry = self::pollCpanelServer($server);
                break;

            case 'plesk':
                $telemetry = self::pollPleskServer($server);
                break;

            case 'directadmin':
                $telemetry = self::pollDirectAdminServer($server);
                break;

            default:
                $telemetry = self::pollGenericServer($server);
                break;
        }

        $now = Carbon::now();
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
                'server_stats_json' => !empty($telemetry['stats']) ? json_encode($telemetry['stats']) : null,
                'last_polled_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $telemetry;
    }

    /**
     * Reseller-Safe cPanel/WHM Poller.
     * Uses WHM API 1 endpoints (systemloadavg, listaccts, servicestatus) compatible with Reseller permissions.
     */
    private static function pollCpanelServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $secure = !isset($server->secure) || (int) $server->secure === 1;
        $port = $secure ? 2087 : 2086;
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
            ];
        }

        $baseUrl = ($secure ? 'https://' : 'http://') . $host . ':' . $port . '/json-api/';

        $authHeader = '';
        if ($token !== '') {
            $cleanedToken = preg_replace('/\s+/', '', $token);
            $authHeader = "Authorization: whm {$user}:{$cleanedToken}";
        } else {
            $authHeader = "Authorization: Basic " . base64_encode("{$user}:{$pass}");
        }

        $headers = [$authHeader, 'Accept: application/json'];

        // 1. Fetch system load averages
        $loadStr = null;
        $stats = [];
        $loadRes = self::curlGet($baseUrl . 'systemloadavg?api.version=1', $headers);
        if ($loadRes['ok'] && !empty($loadRes['data'])) {
            $json = json_decode($loadRes['data'], true);
            if (!empty($json['data']['one'])) {
                $loadStr = sprintf('%.2f, %.2f, %.2f', (float) $json['data']['one'], (float) $json['data']['five'], (float) $json['data']['fifteen']);
                $stats['load_one'] = (float) $json['data']['one'];
                $stats['load_five'] = (float) $json['data']['five'];
                $stats['load_fifteen'] = (float) $json['data']['fifteen'];
            }
        }

        // 2. Fetch Accounts Quota & Status (Reseller listaccts)
        $accounts = [];
        $acctRes = self::curlGet($baseUrl . 'listaccts?api.version=1', $headers);
        if ($acctRes['ok'] && !empty($acctRes['data'])) {
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

                // Parse percent
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

        $reachable = $loadRes['ok'] || $acctRes['ok'];
        $error = !$reachable ? ($loadRes['error'] ?: $acctRes['error'] ?: 'Connection failed') : null;

        return [
            'load' => $loadStr,
            'is_reachable' => $reachable,
            'error' => $error,
            'accounts' => $accounts,
            'stats' => $stats,
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
            return ['load' => null, 'is_reachable' => false, 'error' => 'Missing Plesk credentials', 'accounts' => [], 'stats' => []];
        }

        $url = 'https://' . $host . ':8443/api/v2/server';
        $headers = [
            'Authorization: Basic ' . base64_encode("{$user}:{$pass}"),
            'Accept: application/json',
        ];

        $res = self::curlGet($url, $headers);
        $reachable = $res['ok'];
        $loadStr = null;

        if ($res['ok'] && !empty($res['data'])) {
            $json = json_decode($res['data'], true);
            if (isset($json['stat']['loadAvg'])) {
                $loadStr = (string) $json['stat']['loadAvg'];
            }
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $reachable,
            'error' => $res['error'],
            'accounts' => [],
            'stats' => [],
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
            return ['load' => null, 'is_reachable' => false, 'error' => 'Missing DirectAdmin credentials', 'accounts' => [], 'stats' => []];
        }

        $url = 'https://' . $host . ':2222/CMD_API_SYSTEM_INFO';
        $headers = [
            'Authorization: Basic ' . base64_encode("{$user}:{$pass}"),
        ];

        $res = self::curlGet($url, $headers);
        $loadStr = null;

        if ($res['ok'] && !empty($res['data'])) {
            parse_str($res['data'], $parsed);
            if (!empty($parsed['LoadAvg'])) {
                $loadStr = (string) $parsed['LoadAvg'];
            }
        }

        return [
            'load' => $loadStr,
            'is_reachable' => $res['ok'],
            'error' => $res['error'],
            'accounts' => [],
            'stats' => [],
        ];
    }

    /**
     * Generic ICMP / TCP port probe
     */
    private static function pollGenericServer(\stdClass $server): array
    {
        $host = trim((string) ($server->hostname ?: $server->ipaddress));
        $fp = @fsockopen($host, 80, $errno, $errstr, 3);
        $reachable = is_resource($fp);
        if ($reachable) {
            fclose($fp);
        }

        return [
            'load' => null,
            'is_reachable' => $reachable,
            'error' => !$reachable ? "TCP port 80 unreachable ({$errstr})" : null,
            'accounts' => [],
            'stats' => [],
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

        return [
            'server_id' => (int) $row->server_id,
            'server_name' => (string) $row->server_name,
            'server_host' => (string) $row->server_host,
            'server_load' => (string) $row->server_load,
            'is_reachable' => (bool) $row->is_reachable,
            'error' => (string) $row->reachability_error,
            'accounts' => !empty($row->accounts_data_json) ? json_decode($row->accounts_data_json, true) : [],
            'stats' => !empty($row->server_stats_json) ? json_decode($row->server_stats_json, true) : [],
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

        if (!empty($username)) {
            $acct = self::getAccountHealth($serverId, $username);
            if ($acct) {
                if (!empty($acct['suspended'])) {
                    $reason = !empty($acct['suspend_reason']) ? " ({$acct['suspend_reason']})" : '';
                    $parts[] = "⚠️ ACCOUNT IS SUSPENDED IN WHM{$reason}";
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
