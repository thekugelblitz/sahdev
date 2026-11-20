<?php
namespace App\Services;

final class ReadinessReport
{
    public function __construct(private LaunchReadiness $readiness) {}

    public function snapshot(): array
    {
        $register = config('migration');
        $checks = $this->readiness->checks();
        $counts = array_fill_keys(array_keys(MigrationRegister::STATUSES), 0);
        $capabilities = [];
        foreach ($register['features'] as $id => &$domain) {
            $domain['counts'] = array_fill_keys(array_keys(MigrationRegister::STATUSES), 0);
            foreach ($domain['capabilities'] as $capability) {
                $status = $capability['status'];
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                $domain['counts'][$status] = ($domain['counts'][$status] ?? 0) + 1;
                $capabilities[$id.'.'.$capability['id']] = $capability['label'];
            }
        }
        unset($domain);
        return [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'register_revision' => hash('sha256', json_encode(config('migration'), JSON_THROW_ON_ERROR)),
            'ready' => collect($checks)->every(fn ($check) => $check['ready']),
            'checks' => $checks,
            'statuses' => MigrationRegister::STATUSES,
            'summary' => [
                'domains' => count($register['features']),
                'capabilities' => array_sum($counts),
                'journeys' => count($register['journeys']),
                'verified_journeys' => count(array_filter($register['journeys'], fn ($flow) => $flow['status'] === 'verified')),
                'ready_checks' => count(array_filter($checks, fn ($check) => $check['ready'])),
                'checks' => count($checks),
                'statuses' => $counts,
            ],
            'capability_labels' => $capabilities,
            'register' => $register,
        ];
    }

    /** Filters are presentation only. The full register always controls release eligibility. */
    public function filter(array $report, array $filters): array
    {
        $domains = [];
        foreach ($report['register']['features'] as $id => $domain) {
            if (($filters['domain'] ?? '') !== '' && $filters['domain'] !== $id) continue;
            if (($filters['surface'] ?? '') !== '' && $filters['surface'] !== $domain['surface']) continue;
            $domain['capabilities'] = array_values(array_filter($domain['capabilities'], function ($capability) use ($filters, $domain, $id) {
                return $this->matchesStatus($capability, $filters)
                    && $this->matchesText([$id, $domain['label'], $domain['summary'], $domain['source'], $domain['tests'], $capability], $filters);
            }));
            if ($domain['capabilities']) $domains[$id] = $domain;
        }
        $journeys = array_values(array_filter($report['register']['journeys'], function ($journey) use ($filters, $report) {
            if (!$this->matchesStatus($journey, $filters)) return false;
            // Domain and surface must match the same covered capability.
            $scopeMatches = false;
            foreach ($journey['covers'] as $ref) {
                $id = explode('.', $ref, 2)[0];
                $domain = $report['register']['features'][$id];
                if ((($filters['domain'] ?? '') === '' || $filters['domain'] === $id)
                    && (($filters['surface'] ?? '') === '' || $filters['surface'] === $domain['surface'])) $scopeMatches = true;
            }
            return $scopeMatches && $this->matchesText([$journey, array_map(fn ($ref) => $report['capability_labels'][$ref], $journey['covers'])], $filters);
        }));
        return ['domains' => $domains, 'journeys' => $journeys,
            'capabilities' => array_sum(array_map(fn ($domain) => count($domain['capabilities']), $domains))];
    }

    private function matchesStatus(array $item, array $filters): bool
    {
        return ($filters['status'] ?? '') === '' || $item['status'] === $filters['status'];
    }

    private function matchesText(array $values, array $filters): bool
    {
        $query = trim($filters['q'] ?? '');
        if ($query === '') return true;
        $text = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (preg_split('/\s+/u', $query) as $word) {
            if (mb_stripos($text, $word) === false) return false;
        }
        return true;
    }
}
