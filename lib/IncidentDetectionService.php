<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/ModuleLogger.php';

/**
 * IncidentDetectionService
 *
 * Detects ticket surges and server-wide incidents across incoming tickets
 * in rolling time windows. Generates AI Incident Briefings and Broadcast Templates.
 */
class IncidentDetectionService
{
    /**
     * Run cluster evaluation over recent tickets.
     * Triggered by CronProcessor or manual trigger.
     */
    public static function evaluateClusters(): array
    {
        $result = [
            'tickets_scanned' => 0,
            'clusters_found' => 0,
            'incidents_declared' => 0,
            'active_incidents' => 0,
        ];

        try {
            if (!Capsule::schema()->hasTable('tblsahdev_incidents') || !Capsule::schema()->hasTable('tbltickets')) {
                return $result;
            }

            $settings = Capsule::table('tblsahdev_settings')->first();
            if ($settings && empty($settings->incident_detection_enabled)) {
                return $result;
            }

            $windowHours = max(1, min(24, (int) ($settings->incident_window_hours ?? 3)));
            $threshold = max(2, min(50, (int) ($settings->incident_threshold_tickets ?? 3)));
            $cutoff = Carbon::now()->subHours($windowHours);

            // Fetch open tickets created or updated in window
            $tickets = Capsule::table('tbltickets as t')
                ->leftJoin('tblsahdev_sentiment as s', 's.ticket_id', '=', 't.id')
                ->whereIn('t.status', ['Customer-Reply', 'Awaiting Reply', 'Open', 'In Progress'])
                ->where('t.lastreply', '>=', $cutoff)
                ->select(
                    't.id',
                    't.tid',
                    't.title',
                    't.did',
                    't.userid',
                    't.service',
                    't.lastreply',
                    's.ai_tags_json',
                    's.urgency',
                    's.label as sentiment_label'
                )
                ->get();

            $result['tickets_scanned'] = $tickets->count();
            if ($tickets->isEmpty()) {
                return $result;
            }

            // Map hosting servers for each ticket
            $clusters = [];
            foreach ($tickets as $t) {
                $ticketId = (int) $t->id;
                $serverId = self::resolveTicketServerId($t);
                $tags = !empty($t->ai_tags_json) ? json_decode($t->ai_tags_json, true) : [];

                // 1. Group by Server ID if known
                if ($serverId > 0) {
                    $key = 'server_' . $serverId;
                    if (!isset($clusters[$key])) {
                        $clusters[$key] = [
                            'type' => 'server',
                            'server_id' => $serverId,
                            'tickets' => [],
                            'titles' => [],
                            'tags' => [],
                        ];
                    }
                    $clusters[$key]['tickets'][] = $ticketId;
                    $clusters[$key]['titles'][] = $t->title;
                    if (is_array($tags)) {
                        $clusters[$key]['tags'] = array_merge($clusters[$key]['tags'], $tags);
                    }
                }

                // 2. Group by Outage / Service Tags
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $tag = strtolower(trim((string) $tag));
                        if (in_array($tag, ['ai-outage', 'ai-mysql', 'ai-500error', 'ai-mail-outage', 'ai-dns-down', 'ai-network', 'ai-apache', 'ai-nginx'])) {
                            $key = 'tag_' . $tag . ($serverId > 0 ? '_srv' . $serverId : '');
                            if (!isset($clusters[$key])) {
                                $clusters[$key] = [
                                    'type' => 'tag',
                                    'server_id' => $serverId > 0 ? $serverId : null,
                                    'tag' => $tag,
                                    'tickets' => [],
                                    'titles' => [],
                                    'tags' => [$tag],
                                ];
                            }
                            $clusters[$key]['tickets'][] = $ticketId;
                            $clusters[$key]['titles'][] = $t->title;
                        }
                    }
                }
            }

            // Evaluate clusters meeting threshold
            foreach ($clusters as $clusterKey => $data) {
                $uniqueTickets = array_values(array_unique($data['tickets']));
                $count = count($uniqueTickets);

                if ($count >= $threshold) {
                    $result['clusters_found']++;
                    self::processIncidentCluster($clusterKey, $data, $uniqueTickets);
                    $result['incidents_declared']++;
                }
            }

            $result['active_incidents'] = Capsule::table('tblsahdev_incidents')
                ->whereIn('status', ['Active', 'Investigating', 'Monitoring'])
                ->count();

        } catch (\Throwable $e) {
            ModuleLogger::warning('IncidentDetection.evaluate', $e->getMessage(), null);
        }

        return $result;
    }

    /**
     * Process an identified cluster meeting the incident threshold.
     */
    private static function processIncidentCluster(string $clusterKey, array $data, array $ticketIds): void
    {
        $existing = Capsule::table('tblsahdev_incidents')
            ->where('cluster_key', $clusterKey)
            ->whereIn('status', ['Active', 'Investigating', 'Monitoring'])
            ->first();

        $now = Carbon::now();
        $ticketIdsJson = json_encode($ticketIds);

        if ($existing) {
            // Update ticket list & timestamp
            Capsule::table('tblsahdev_incidents')
                ->where('id', $existing->id)
                ->update([
                    'ticket_ids_json' => $ticketIdsJson,
                    'updated_at' => $now,
                ]);
            return;
        }

        // Declare new incident
        $serverId = $data['server_id'] ?? null;
        $serverName = null;
        if ($serverId > 0 && Capsule::schema()->hasTable('tblservers')) {
            $serverName = Capsule::table('tblservers')->where('id', $serverId)->value('name');
        }

        $sampleTitles = array_slice(array_unique($data['titles'] ?? []), 0, 5);
        $incNum = 'INC-' . date('Ymd') . '-' . rand(100, 999);
        $title = $serverName
            ? "Surge on {$serverName} (" . count($ticketIds) . " tickets)"
            : "Service Surge Detected (" . count($ticketIds) . " tickets)";

        $summary = "Multiple clients (" . count($ticketIds) . " tickets) reporting issues related to: " . implode(' | ', $sampleTitles);
        $actionPlan = "1. Verify server connectivity & service status\n2. Check web/database error logs\n3. Deploy broadcast reply to reassuring clients";
        $broadcast = "Hello {{CLIENT_NAME}},\n\nOur systems engineering team is actively investigating a service disruption that may be affecting your services. We are working to resolve this as quickly as possible.\n\nWe will update you as soon as normal operations are restored. Thank you for your patience.";

        Capsule::table('tblsahdev_incidents')->insert([
            'incident_num' => $incNum,
            'title' => $title,
            'severity' => count($ticketIds) >= 5 ? 'Critical' : 'High',
            'status' => 'Active',
            'server_id' => $serverId,
            'server_name' => $serverName,
            'cluster_key' => $clusterKey,
            'root_cause_summary' => $summary,
            'action_plan' => $actionPlan,
            'broadcast_template' => $broadcast,
            'ticket_ids_json' => $ticketIdsJson,
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Check if a ticket is part of an active incident.
     */
    public static function getActiveIncidentForTicket(int $ticketId): ?array
    {
        if ($ticketId <= 0 || !Capsule::schema()->hasTable('tblsahdev_incidents')) {
            return null;
        }

        $incidents = Capsule::table('tblsahdev_incidents')
            ->whereIn('status', ['Active', 'Investigating', 'Monitoring'])
            ->orderBy('id', 'desc')
            ->get();

        foreach ($incidents as $inc) {
            $ids = !empty($inc->ticket_ids_json) ? json_decode($inc->ticket_ids_json, true) : [];
            if (is_array($ids) && in_array($ticketId, $ids)) {
                return (array) $inc;
            }
        }

        return null;
    }

    /**
     * Get all currently active incidents.
     */
    public static function getActiveIncidents(): array
    {
        if (!Capsule::schema()->hasTable('tblsahdev_incidents')) {
            return [];
        }

        return Capsule::table('tblsahdev_incidents')
            ->whereIn('status', ['Active', 'Investigating', 'Monitoring'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($row) {
                $r = (array) $row;
                $r['ticket_count'] = count(!empty($row->ticket_ids_json) ? json_decode($row->ticket_ids_json, true) : []);
                return $r;
            })
            ->toArray();
    }

    /**
     * Resolve hosting server ID for a ticket.
     */
    private static function resolveTicketServerId(\stdClass $ticket): int
    {
        $userId = (int) ($ticket->userid ?? 0);
        $service = (string) ($ticket->service ?? '');

        if ($userId <= 0 || !Capsule::schema()->hasTable('tblhosting')) {
            return 0;
        }

        // If specific hosting service selected
        if (preg_match('/^S([0-9]+)$/i', $service, $m)) {
            $hostId = (int) $m[1];
            $srv = Capsule::table('tblhosting')->where('id', $hostId)->value('server');
            if ($srv) return (int) $srv;
        }

        // Fallback: Check primary active hosting server for client
        $srv = Capsule::table('tblhosting')
            ->where('userid', $userId)
            ->whereIn('domainstatus', ['Active', 'Suspended'])
            ->orderBy('id', 'desc')
            ->value('server');

        return $srv ? (int) $srv : 0;
    }
}
