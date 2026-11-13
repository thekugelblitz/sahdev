<?php
namespace App\Services;
class DnsResolver
{
    public function addresses(string $host): array
    {
        $rows = dns_get_record($host,DNS_A|DNS_AAAA) ?: [];
        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null,$rows)));
    }
}
