<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * Class MetricsIntelligenceService
 *
 * Native MetricsCube-Style Business Intelligence & AI Pattern Engine for WHMCS.
 * Computes financial KPIs (MRR, ARR, LTV, Churn, Aging Debt), gateway health,
 * support velocity, infrastructure usage, and detects operational anomalies with
 * actionable 1-click Copilot recommendations.
 */
class MetricsIntelligenceService
{
    /**
     * Compute and return complete 360° metrics snapshot for dashboard rendering.
     */
    public static function getMetricsSnapshot(bool $forceRecalculate = false): array
    {
        SchemaManager::ensureMetricsTables();

        $today = Carbon::today()->toDateString();

        // Check if we have cached daily rollups
        $cachedCount = Capsule::table('tblsahdev_metrics_daily')
            ->where('metric_date', $today)
            ->count();

        if ($cachedCount === 0 || $forceRecalculate) {
            self::runDailyAggregation($today);
        }

        return [
            'financial'     => self::getFinancialMetrics(),
            'clients'       => self::getClientMetrics(),
            'gateways'      => self::getGatewayHealthMetrics(),
            'support'       => self::getSupportVelocityMetrics(),
            'anomalies'     => self::getActiveAnomalies(),
            'mrr_trend'     => self::getMrrTrendData(),
            'aging_debt'    => self::getAgingInvoicesBuckets(),
            'top_products'  => self::getTopProductsByRevenue(),
        ];
    }

    /**
     * Nightly / On-Demand aggregation that stores metrics in tblsahdev_metrics_daily.
     */
    public static function runDailyAggregation(?string $date = null): void
    {
        SchemaManager::ensureMetricsTables();
        SchemaManager::ensureSettingsColumns();

        $settings = null;
        try {
            $settings = Capsule::table('tblsahdev_settings')->first();
        } catch (\Throwable $e) {}

        // If invoked by automated daily cron without explicit date, honor metrics_cron_enabled
        if ($date === null && $settings && isset($settings->metrics_cron_enabled) && !$settings->metrics_cron_enabled) {
            return;
        }

        $targetDate = $date ?: Carbon::today()->toDateString();

        // 1. Calculate MRR
        $mrr = self::calculateMRR();
        self::saveMetric($targetDate, 'financial', 'mrr', $mrr);
        self::saveMetric($targetDate, 'financial', 'arr', $mrr * 12);

        // 2. Unpaid Aging Debt
        $unpaidTotal = (float) Capsule::table('tblinvoices')->where('status', 'Unpaid')->sum('total');
        self::saveMetric($targetDate, 'financial', 'unpaid_invoices_total', $unpaidTotal);

        // 3. Active Clients & Subscriptions
        $activeClients = (int) Capsule::table('tblclients')->where('status', 'Active')->count();
        self::saveMetric($targetDate, 'clients', 'active_clients', $activeClients);

        $activeServices = (int) Capsule::table('tblhosting')->where('domainstatus', 'Active')->count();
        self::saveMetric($targetDate, 'clients', 'active_services', $activeServices);

        // 4. ARPU & LTV
        $arpu = $activeClients > 0 ? round($mrr / $activeClients, 2) : 0;
        self::saveMetric($targetDate, 'clients', 'arpu', $arpu);

        $totalRevenueAllTime = (float) Capsule::table('tblinvoices')->where('status', 'Paid')->sum('total');
        $allClientsCount = (int) Capsule::table('tblclients')->count();
        $ltv = $allClientsCount > 0 ? round($totalRevenueAllTime / $allClientsCount, 2) : 0;
        self::saveMetric($targetDate, 'clients', 'ltv', $ltv);

        // 5. Gateway Success Rate (last 7 days)
        $gwStats = self::calculateGatewaySuccessRate(7);
        self::saveMetric($targetDate, 'gateways', 'success_rate', $gwStats['rate'], ['details' => $gwStats]);

        // 6. Support Ticket First Response Time
        $frtHours = self::calculateAverageFirstResponseHours(30);
        self::saveMetric($targetDate, 'support', 'avg_frt_hours', $frtHours);

        // 7. Run AI Anomaly & Pattern Detector
        self::detectAnomaliesAndPatterns();

        // 8. Enforce historical snapshot retention
        try {
            $retentionDays = max(30, (int) ($settings->metrics_retention_days ?? 365));
            $cutoff = Carbon::today()->subDays($retentionDays)->toDateString();
            Capsule::table('tblsahdev_metrics_daily')->where('metric_date', '<', $cutoff)->delete();
        } catch (\Throwable $e) {}
    }

    /**
     * Compute Monthly Recurring Revenue (MRR) from active hosting services & addons.
     */
    public static function calculateMRR(): float
    {
        try {
            $services = Capsule::table('tblhosting')
                ->where('domainstatus', 'Active')
                ->get(['amount', 'billingcycle']);

            $mrr = 0.0;
            foreach ($services as $s) {
                $amt = (float) $s->amount;
                switch (strtolower(trim($s->billingcycle))) {
                    case 'monthly':
                        $mrr += $amt;
                        break;
                    case 'quarterly':
                        $mrr += ($amt / 3);
                        break;
                    case 'semi-annually':
                    case 'semiannually':
                        $mrr += ($amt / 6);
                        break;
                    case 'annually':
                        $mrr += ($amt / 12);
                        break;
                    case 'biennially':
                        $mrr += ($amt / 24);
                        break;
                    case 'triennially':
                        $mrr += ($amt / 36);
                        break;
                }
            }

            return round($mrr, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Financial KPI Summary.
     */
    public static function getFinancialMetrics(): array
    {
        $mrr = self::calculateMRR();
        $arr = $mrr * 12;

        $unpaidInvoices = Capsule::table('tblinvoices')->where('status', 'Unpaid')->count();
        $unpaidAmount = (float) Capsule::table('tblinvoices')->where('status', 'Unpaid')->sum('total');

        $thisMonthPaid = (float) Capsule::table('tblinvoices')
            ->where('status', 'Paid')
            ->where('datepaid', '>=', Carbon::now()->startOfMonth()->toDateTimeString())
            ->sum('total');

        $lastMonthPaid = (float) Capsule::table('tblinvoices')
            ->where('status', 'Paid')
            ->whereBetween('datepaid', [
                Carbon::now()->subMonth()->startOfMonth()->toDateTimeString(),
                Carbon::now()->subMonth()->endOfMonth()->toDateTimeString(),
            ])
            ->sum('total');

        $growthPct = $lastMonthPaid > 0 ? round((($thisMonthPaid - $lastMonthPaid) / $lastMonthPaid) * 100, 1) : 0.0;

        return [
            'mrr'                => $mrr,
            'arr'                => $arr,
            'this_month_paid'    => $thisMonthPaid,
            'last_month_paid'    => $lastMonthPaid,
            'growth_percentage'  => $growthPct,
            'unpaid_count'       => $unpaidInvoices,
            'unpaid_amount'      => round($unpaidAmount, 2),
        ];
    }

    /**
     * Client & Churn Metrics.
     */
    public static function getClientMetrics(): array
    {
        $activeClients = (int) Capsule::table('tblclients')->where('status', 'Active')->count();
        $totalClients = (int) Capsule::table('tblclients')->count();

        // New clients this month
        $newThisMonth = (int) Capsule::table('tblclients')
            ->where('datecreated', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->count();

        // Cancellations / Suspensions this month
        $cancelledThisMonth = (int) Capsule::table('tblhosting')
            ->whereIn('domainstatus', ['Cancelled', 'Terminated'])
            ->where('updated_at', '>=', Carbon::now()->startOfMonth()->toDateTimeString())
            ->count();

        $activeServices = (int) Capsule::table('tblhosting')->where('domainstatus', 'Active')->count();

        $mrr = self::calculateMRR();
        $arpu = $activeClients > 0 ? round($mrr / $activeClients, 2) : 0;

        $totalPaid = (float) Capsule::table('tblinvoices')->where('status', 'Paid')->sum('total');
        $ltv = $totalClients > 0 ? round($totalPaid / $totalClients, 2) : 0;

        // Churn rate estimate
        $churnRate = ($activeServices + $cancelledThisMonth) > 0
            ? round(($cancelledThisMonth / ($activeServices + $cancelledThisMonth)) * 100, 2)
            : 0.0;

        return [
            'active_clients'       => $activeClients,
            'total_clients'        => $totalClients,
            'new_clients_month'    => $newThisMonth,
            'cancellations_month'  => $cancelledThisMonth,
            'churn_rate_pct'       => $churnRate,
            'arpu'                 => $arpu,
            'ltv'                  => $ltv,
        ];
    }

    /**
     * Aging Unpaid Invoices Buckets (1-30, 31-60, 61-90, 90+ days overdue).
     */
    public static function getAgingInvoicesBuckets(): array
    {
        $now = Carbon::today();

        $b1_30 = 0.0;
        $b31_60 = 0.0;
        $b61_90 = 0.0;
        $b90_plus = 0.0;

        $unpaids = Capsule::table('tblinvoices')
            ->where('status', 'Unpaid')
            ->get(['duedate', 'total']);

        foreach ($unpaids as $inv) {
            $due = Carbon::parse($inv->duedate);
            $total = (float) $inv->total;

            if ($due->gte($now)) {
                $b1_30 += $total; // not yet overdue or within current cycle
            } else {
                $daysOverdue = $now->diffInDays($due);
                if ($daysOverdue <= 30) {
                    $b1_30 += $total;
                } elseif ($daysOverdue <= 60) {
                    $b31_60 += $total;
                } elseif ($daysOverdue <= 90) {
                    $b61_90 += $total;
                } else {
                    $b90_plus += $total;
                }
            }
        }

        return [
            '1_to_30_days'  => round($b1_30, 2),
            '31_to_60_days' => round($b31_60, 2),
            '61_to_90_days' => round($b61_90, 2),
            '90_plus_days'  => round($b90_plus, 2),
            'total_aging'   => round($b1_30 + $b31_60 + $b61_90 + $b90_plus, 2),
        ];
    }

    /**
     * Gateway Health & Success Rate Analysis.
     */
    public static function getGatewayHealthMetrics(): array
    {
        $stats = self::calculateGatewaySuccessRate(30);

        $breakdown = [];
        try {
            $gateways = Capsule::table('tblgatewaylog')
                ->where('date', '>=', Carbon::now()->subDays(30)->toDateTimeString())
                ->groupBy('gateway')
                ->selectRaw("gateway, COUNT(*) as total, SUM(CASE WHEN result = 'Success' THEN 1 ELSE 0 END) as successes")
                ->get();

            foreach ($gateways as $gw) {
                $total = (int) $gw->total;
                $succ = (int) $gw->successes;
                $rate = $total > 0 ? round(($succ / $total) * 100, 1) : 0;
                $breakdown[$gw->gateway] = [
                    'total'        => $total,
                    'successes'    => $succ,
                    'failures'     => $total - $succ,
                    'success_rate' => $rate,
                ];
            }
        } catch (\Throwable $e) {}

        return [
            'overall_rate' => $stats['rate'],
            'total_trans'  => $stats['total'],
            'failed_trans' => $stats['failures'],
            'gateways'     => $breakdown,
        ];
    }

    /**
     * Support Ticket Velocity & First Response Times.
     */
    public static function getSupportVelocityMetrics(): array
    {
        $openTickets = (int) Capsule::table('tbltickets')->whereIn('status', ['Open', 'Customer-Reply', 'In Progress'])->count();
        $avgFrt = self::calculateAverageFirstResponseHours(30);

        $deptBreakdown = [];
        try {
            $depts = Capsule::table('tbltickets')
                ->join('tblticketdepartments', 'tbltickets.did', '=', 'tblticketdepartments.id')
                ->whereIn('tbltickets.status', ['Open', 'Customer-Reply', 'In Progress'])
                ->groupBy('tblticketdepartments.name')
                ->selectRaw('tblticketdepartments.name, COUNT(*) as count')
                ->get();

            foreach ($depts as $d) {
                $deptBreakdown[$d->name] = (int) $d->count;
            }
        } catch (\Throwable $e) {}

        return [
            'open_tickets'        => $openTickets,
            'avg_frt_hours'       => $avgFrt,
            'department_backlog'  => $deptBreakdown,
        ];
    }

    /**
     * Top Hosting Products by Recurring Revenue.
     */
    public static function getTopProductsByRevenue(): array
    {
        try {
            return Capsule::table('tblhosting')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.domainstatus', 'Active')
                ->groupBy('tblproducts.id', 'tblproducts.name')
                ->selectRaw('tblproducts.name, COUNT(tblhosting.id) as accounts, SUM(tblhosting.amount) as revenue')
                ->orderBy('revenue', 'desc')
                ->limit(6)
                ->get()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Historical MRR trend for Chart.js rendering (past 6 months).
     */
    public static function getMrrTrendData(): array
    {
        $labels = [];
        $values = [];

        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i);
            $labels[] = $m->format('M Y');

            // Check if stored in daily rollups
            $saved = Capsule::table('tblsahdev_metrics_daily')
                ->where('metric_category', 'financial')
                ->where('metric_key', 'mrr')
                ->whereYear('metric_date', $m->year)
                ->whereMonth('metric_date', $m->month)
                ->orderBy('metric_date', 'desc')
                ->value('metric_value');

            $values[] = $saved !== null ? (float) $saved : self::calculateMRR();
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * AI Pattern & Anomaly Engine: Scans logs and metrics for bottlenecks, payment failures, and churn surges.
     */
    public static function detectAnomaliesAndPatterns(): array
    {
        SchemaManager::ensureMetricsTables();
        $detected = [];

        // 1. Gateway Failure Anomaly: Any gateway with failure rate > 25% in last 48h
        try {
            $recentFails = Capsule::table('tblgatewaylog')
                ->where('date', '>=', Carbon::now()->subHours(48)->toDateTimeString())
                ->groupBy('gateway')
                ->selectRaw("gateway, COUNT(*) as total, SUM(CASE WHEN result = 'Success' THEN 1 ELSE 0 END) as successes")
                ->get();

            foreach ($recentFails as $rf) {
                if ($rf->total >= 5) {
                    $failRate = round((($rf->total - $rf->successes) / $rf->total) * 100, 1);
                    if ($failRate >= 25) {
                        $detected[] = [
                            'category'               => 'gateways',
                            'severity'               => 'critical',
                            'title'                  => "Payment Gateway Spike: {$rf->gateway} failure rate is {$failRate}%",
                            'description'            => "Out of {$rf->total} transaction attempts in the last 48 hours, " . ($rf->total - $rf->successes) . " failed on {$rf->gateway}. This could indicate API credential expiration or 3DS webhook timeouts.",
                            'suggested_action_key'   => 'gateway_diagnostics',
                            'suggested_action_payload'=> json_encode(['gateway' => $rf->gateway]),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {}

        // 2. High Overdue Debt: Invoices 60+ days overdue > $1,000
        $aging = self::getAgingInvoicesBuckets();
        $severeDebt = $aging['61_to_90_days'] + $aging['90_plus_days'];
        if ($severeDebt >= 500.0) {
            $detected[] = [
                'category'               => 'financial',
                'severity'               => 'warning',
                'title'                  => "Revenue Leakage: $" . number_format($severeDebt, 2) . " overdue past 60+ days",
                'description'            => "Significant unpaid revenue is sitting in aged buckets (61-90+ days). Automated reminder emails or service suspension review is recommended.",
                'suggested_action_key'   => 'invoice_lookup',
                'suggested_action_payload'=> json_encode(['filter' => 'overdue_60']),
            ];
        }

        // 3. Support FRT Bottleneck: Avg response time > 4 hours
        $frt = self::calculateAverageFirstResponseHours(7);
        if ($frt > 4.0) {
            $detected[] = [
                'category'               => 'support',
                'severity'               => 'warning',
                'title'                  => "Support SLA Warning: Average First Response is {$frt} hours",
                'description'            => "First response time across support tickets in the past 7 days has slipped to {$frt} hours. Consider activating Sahdev Autopilot or reassigning tickets.",
                'suggested_action_key'   => 'ticket_lookup',
                'suggested_action_payload'=> json_encode(['status' => 'Open']),
            ];
        }

        // 4. Product Suspension Surge: > 5 suspensions in last 72 hours
        try {
            $suspensions = Capsule::table('tblhosting')
                ->where('domainstatus', 'Suspended')
                ->where('updated_at', '>=', Carbon::now()->subHours(72)->toDateTimeString())
                ->count();

            if ($suspensions >= 5) {
                $detected[] = [
                    'category'               => 'servers',
                    'severity'               => 'info',
                    'title'                  => "Service Suspension Surge: {$suspensions} accounts suspended in 72h",
                    'description'            => "An elevated number of accounts were suspended recently. Check whether this is automated billing cron or abuse/resource limit overages.",
                    'suggested_action_key'   => 'service_lookup',
                    'suggested_action_payload'=> json_encode(['status' => 'Suspended']),
                ];
            }
        } catch (\Throwable $e) {}

        // Persist new anomalies without duplicates
        foreach ($detected as $item) {
            $exists = Capsule::table('tblsahdev_metrics_anomalies')
                ->where('title', $item['title'])
                ->where('status', 'active')
                ->exists();

            if (!$exists) {
                Capsule::table('tblsahdev_metrics_anomalies')->insert([
                    'detected_at'              => Carbon::now(),
                    'category'                 => $item['category'],
                    'severity'                 => $item['severity'],
                    'title'                    => $item['title'],
                    'description'              => $item['description'],
                    'suggested_action_key'     => $item['suggested_action_key'],
                    'suggested_action_payload' => $item['suggested_action_payload'],
                    'status'                   => 'active',
                    'created_at'               => Carbon::now(),
                    'updated_at'               => Carbon::now(),
                ]);
            }
        }

        return $detected;
    }

    public static function getActiveAnomalies(int $limit = 10): array
    {
        return Capsule::table('tblsahdev_metrics_anomalies')
            ->where('status', 'active')
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private static function calculateGatewaySuccessRate(int $days = 30): array
    {
        try {
            $total = Capsule::table('tblgatewaylog')
                ->where('date', '>=', Carbon::now()->subDays($days)->toDateTimeString())
                ->count();

            $success = Capsule::table('tblgatewaylog')
                ->where('date', '>=', Carbon::now()->subDays($days)->toDateTimeString())
                ->where('result', 'Success')
                ->count();

            $rate = $total > 0 ? round(($success / $total) * 100, 1) : 100.0;

            return [
                'total'     => $total,
                'success'   => $success,
                'failures'  => $total - $success,
                'rate'      => $rate,
            ];
        } catch (\Throwable $e) {
            return ['total' => 0, 'success' => 0, 'failures' => 0, 'rate' => 100.0];
        }
    }

    private static function calculateAverageFirstResponseHours(int $days = 30): float
    {
        try {
            $tickets = Capsule::table('tbltickets')
                ->where('date', '>=', Carbon::now()->subDays($days)->toDateTimeString())
                ->limit(50)
                ->get(['id', 'date']);

            if ($tickets->isEmpty()) return 1.5;

            $totalHours = 0.0;
            $count = 0;

            foreach ($tickets as $t) {
                $firstReply = Capsule::table('tblticketreplies')
                    ->where('tid', $t->id)
                    ->where('admin', '!=', '')
                    ->orderBy('id', 'asc')
                    ->value('date');

                if ($firstReply) {
                    $created = Carbon::parse($t->date);
                    $replied = Carbon::parse($firstReply);
                    $diff = $replied->diffInMinutes($created) / 60;
                    $totalHours += $diff;
                    $count++;
                }
            }

            return $count > 0 ? round($totalHours / $count, 1) : 1.5;
        } catch (\Throwable $e) {
            return 1.5;
        }
    }

    private static function saveMetric(string $date, string $category, string $key, float $val, array $meta = []): void
    {
        try {
            Capsule::table('tblsahdev_metrics_daily')->updateOrInsert(
                [
                    'metric_date'     => $date,
                    'metric_category' => $category,
                    'metric_key'      => $key,
                ],
                [
                    'metric_value'    => $val,
                    'metadata_json'   => !empty($meta) ? json_encode($meta) : null,
                    'updated_at'      => Carbon::now(),
                ]
            );
        } catch (\Throwable $e) {}
    }
}
