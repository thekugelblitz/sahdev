<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/ModuleLogger.php';

/**
 * Read-only WHMCS account snippets for AI context (userid-scoped).
 * Each subsection is isolated in try/catch so failures never break the ticket panel.
 */
class ClientAccountEnrichment
{
    private const INTERNAL_MAX_RETURN_CHARS = 12000;

    /**
     * @param int $clientUserId tblclients.id / tbltickets.userid
     * @param array $options Settings row fields (context_enrichment_*)
     * @param int|null $ticketIdForScope Reserved for future scope checks; ticket client is already enforced via userid
     */
    public static function build(int $clientUserId, array $options, ?int $ticketIdForScope = null): string
    {
        if ($clientUserId <= 0) {
            return '';
        }

        if (empty($options['context_enrichment_enabled'])) {
            return '';
        }

        $parts = [];

        if (!empty($options['context_enrichment_invoices'])) {
            try {
                $s = self::sectionInvoices($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('invoices', $e, $ticketIdForScope);
            }
        }

        if (!empty($options['context_enrichment_domains'])) {
            try {
                $s = self::sectionDomains($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('domains', $e, $ticketIdForScope);
            }
        }

        if (!empty($options['context_enrichment_addons'])) {
            try {
                $s = self::sectionAddons($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('addons', $e, $ticketIdForScope);
            }
        }

        if (!empty($options['context_enrichment_hosting'])) {
            try {
                $s = self::sectionHosting($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
                
                // Add system-wide defaults as a reference
                $defaults = self::sectionSystemDefaults();
                if ($defaults !== '') {
                    $parts[] = $defaults;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('hosting', $e, $ticketIdForScope);
            }
        }

        if (!empty($options['context_enrichment_custom_fields'])) {
            try {
                $allowRaw = $options['context_enrichment_custom_field_allowlist'] ?? null;
                $s = self::sectionCustomFields($clientUserId, is_string($allowRaw) ? $allowRaw : null);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('custom_fields', $e, $ticketIdForScope);
            }
        }

        if (!empty($options['context_enrichment_client_notes'])) {
            try {
                $s = self::sectionClientNotes($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('client_notes', $e, $ticketIdForScope);
            }
        }

        if ($parts === []) {
            return '';
        }

        $out = "=== ACCOUNT DATA (read-only) ===\n" . implode("\n\n", $parts);

        if (strlen($out) > self::INTERNAL_MAX_RETURN_CHARS) {
            $out = substr($out, 0, self::INTERNAL_MAX_RETURN_CHARS) . "\n[truncated]";
        }

        return $out;
    }

    private static function logSectionFailure(string $section, \Throwable $e, ?int $ticketId): void
    {
        $msg = $e->getMessage();
        if (strlen($msg) > 500) {
            $msg = substr($msg, 0, 497) . '...';
        }
        ModuleLogger::warning('ClientAccountEnrichment.' . $section, $msg, $ticketId);
    }

    private static function hasTable(string $name): bool
    {
        try {
            return Capsule::schema()->hasTable($name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function sectionInvoices(int $userid): string
    {
        if (!self::hasTable('tblinvoices')) {
            return '';
        }

        $rows = Capsule::table('tblinvoices')
            ->where('userid', $userid)
            ->orderBy('date', 'desc')
            ->limit(3)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Recent invoices:'];
        foreach ($rows as $r) {
            $label = '';
            if (isset($r->invoicenum) && (string) $r->invoicenum !== '') {
                $label = (string) $r->invoicenum;
            } else {
                $label = '#' . (int) ($r->id ?? 0);
            }
            $status = isset($r->status) ? (string) $r->status : '?';
            $total = isset($r->total) ? (string) $r->total : '?';
            $cur = '';
            if (isset($r->currencycode) && $r->currencycode !== '') {
                $cur = ' ' . $r->currencycode;
            } elseif (isset($r->currency) && $r->currency !== '') {
                $cur = ' ' . $r->currency;
            }
            $date = isset($r->date) ? substr((string) $r->date, 0, 10) : '';
            $lines[] = "- {$label}: {$status}, {$total}{$cur}" . ($date !== '' ? ", date {$date}" : '');
        }

        return implode("\n", $lines);
    }

    private static function sectionDomains(int $userid): string
    {
        if (!self::hasTable('tbldomains')) {
            return '';
        }

        $rows = Capsule::table('tbldomains')
            ->where('userid', $userid)
            ->whereNotIn('status', ['Cancelled', 'Expired', 'Fraud'])
            ->orderBy('expirydate', 'asc')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Domains:'];
        foreach ($rows as $r) {
            $dom = isset($r->domain) ? (string) $r->domain : '?';
            $st = isset($r->status) ? (string) $r->status : '?';
            $ex = '';
            if (isset($r->expirydate) && $r->expirydate && (string) $r->expirydate !== '0000-00-00') {
                $ex = ', expires ' . substr((string) $r->expirydate, 0, 10);
            }
            $ns = [];
            for ($i = 1; $i <= 5; $i++) {
                $f = 'ns' . $i;
                if (!empty($r->$f)) $ns[] = (string) $r->$f;
            }
            $nsStr = $ns !== [] ? (' (Current Registrar NS: ' . implode(', ', $ns) . ')') : '';
            $lines[] = "- {$dom}: {$st}{$ex}{$nsStr}";
        }

        return implode("\n", $lines);
    }

    private static function sectionHosting(int $userid): string
    {
        if (!self::hasTable('tblhosting') || !self::hasTable('tblproducts')) {
            return '';
        }

        $query = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->leftJoin('tblservers as s', 'h.server', '=', 's.id')
            ->where('h.userid', $userid)
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->select(
                'p.name as product_name',
                'h.domain',
                'h.username',
                'h.dedicatedip',
                'h.assignedips',
                'h.domainstatus',
                's.name as server_name',
                's.ipaddress as server_ip',
                's.hostname as server_host',
                's.nameserver1', 's.nameserver2', 's.nameserver3', 's.nameserver4', 's.nameserver5'
            )
            ->orderBy('h.id', 'desc')
            ->limit(5);

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Hosting services:'];
        foreach ($rows as $r) {
            $name = (string) $r->product_name;
            $dom = (string) $r->domain;
            $st = (string) $r->domainstatus;
            
            $ip = trim((string) $r->dedicatedip) ?: trim((string) $r->assignedips);
            if (!$ip) $ip = (string) $r->server_ip;
            
            $ns = [];
            for ($i = 1; $i <= 5; $i++) {
                $f = 'nameserver' . $i;
                if (!empty($r->$f)) $ns[] = (string) $r->$f;
            }
            
            $details = [
                "Status: {$st}",
                "Product IP: " . ($ip ?: 'N/A'),
            ];
            if (!empty($r->server_name)) $details[] = "Server: {$r->server_name}";
            if (!empty($r->server_host)) $details[] = "Server Host: {$r->server_host}";
            if (!empty($r->server_ip)) $details[] = "Server IP: {$r->server_ip}";
            
            if ($ns !== []) $details[] = "Required Hosting NS (Point here): " . implode(', ', $ns);
            
            $lines[] = "- {$name} ({$dom}): " . implode(' | ', $details);
        }

        return implode("\n", $lines);
    }
    
    private static function sectionSystemDefaults(): string
    {
        $ns = [];
        try {
            $nameservers = Capsule::table('tblconfiguration')
                ->whereIn('setting', ['DefaultNameserver1', 'DefaultNameserver2', 'DefaultNameserver3', 'DefaultNameserver4'])
                ->pluck('value', 'setting');
            
            foreach (['DefaultNameserver1', 'DefaultNameserver2', 'DefaultNameserver3', 'DefaultNameserver4'] as $key) {
                if (!empty($nameservers[$key])) {
                    $ns[] = $nameservers[$key];
                }
            }
        } catch (\Exception $e) {
            return '';
        }
        
        if (empty($ns)) {
            return '';
        }
        
        return "Company Default Nameservers (Backup Reference): " . implode(', ', $ns);
    }

    private static function sectionAddons(int $userid): string
    {
        if (!self::hasTable('tblhostingaddons') || !self::hasTable('tbladdons') || !self::hasTable('tblhosting')) {
            return '';
        }

        $rows = Capsule::table('tblhostingaddons as ha')
            ->join('tblhosting as h', 'h.id', '=', 'ha.hostingid')
            ->join('tbladdons as a', 'a.id', '=', 'ha.addonid')
            ->where('h.userid', $userid)
            ->orderBy('ha.id', 'desc')
            ->limit(10)
            ->select('a.name as addon_name', 'h.domain as service_domain', 'ha.status as addon_status')
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Hosting addons:'];
        foreach ($rows as $r) {
            $name = isset($r->addon_name) ? (string) $r->addon_name : 'Addon';
            $dom = isset($r->service_domain) ? (string) $r->service_domain : '';
            $st = isset($r->addon_status) ? (string) $r->addon_status : '';
            $lines[] = '- ' . $name . ($dom !== '' ? " ({$dom})" : '') . ($st !== '' ? " — {$st}" : '');
        }

        return implode("\n", $lines);
    }

    private static function sectionCustomFields(int $userid, ?string $allowlistRaw): string
    {
        if (!self::hasTable('tblcustomfields') || !self::hasTable('tblcustomfieldsvalues')) {
            return '';
        }

        $hostingIds = Capsule::table('tblhosting')
            ->where('userid', $userid)
            ->pluck('id')
            ->all();

        $allowlist = self::parseAllowlist($allowlistRaw);
        $useAllowlistOnly = $allowlist !== [];

        $fields = Capsule::table('tblcustomfields')
            ->whereIn('type', ['client', 'product'])
            ->get(['id', 'fieldname', 'type']);

        if ($fields->isEmpty()) {
            return '';
        }

        $lines = [];
        $lineBudget = 24;
        $charBudget = 2000;

        foreach ($fields as $f) {
            if (count($lines) >= $lineBudget) {
                break;
            }
            $fid = (int) $f->id;
            $fname = isset($f->fieldname) ? (string) $f->fieldname : 'field';
            if (self::isSensitiveFieldName($fname)) {
                continue;
            }
            if ($useAllowlistOnly && !self::fieldMatchesAllowlist($fid, $fname, $allowlist)) {
                continue;
            }

            $type = isset($f->type) ? (string) $f->type : '';
            $relid = null;
            if ($type === 'client') {
                $relid = $userid;
            } elseif ($type === 'product') {
                // product fields are per hosting row; fetch first matching value in scope
                foreach ($hostingIds as $hid) {
                    $val = Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $fid)
                        ->where('relid', $hid)
                        ->value('value');
                    if ($val !== null && trim((string) $val) !== '') {
                        $relid = $hid;
                        break;
                    }
                }
                if ($relid === null) {
                    continue;
                }
            } else {
                continue;
            }

            if ($type === 'client') {
                $val = Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $fid)
                    ->where('relid', $userid)
                    ->value('value');
            } else {
                $val = Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $fid)
                    ->where('relid', $relid)
                    ->value('value');
            }

            if ($val === null || trim((string) $val) === '') {
                continue;
            }

            $v = trim((string) $val);
            if (strlen($v) > 200) {
                $v = substr($v, 0, 197) . '...';
            }
            $line = "- {$fname}: {$v}";
            if (strlen(implode("\n", $lines)) + strlen($line) > $charBudget) {
                break;
            }
            $lines[] = $line;
        }

        if ($lines === []) {
            return '';
        }

        return "Custom fields:\n" . implode("\n", $lines);
    }

    private static function sectionClientNotes(int $userid): string
    {
        if (!self::hasTable('tblnotes')) {
            return '';
        }

        $q = Capsule::table('tblnotes')->where('userid', $userid);
        try {
            $rows = $q->orderBy('id', 'desc')->limit(5)->get();
        } catch (\Throwable $e) {
            try {
                $rows = $q->orderBy('datecreated', 'desc')->limit(5)->get();
            } catch (\Throwable $e2) {
                return '';
            }
        }

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Staff notes (internal only):'];
        foreach ($rows as $r) {
            $note = '';
            if (isset($r->note)) {
                $note = trim(strip_tags((string) $r->note));
            }
            if ($note === '') {
                continue;
            }
            if (strlen($note) > 400) {
                $note = substr($note, 0, 397) . '...';
            }
            $lines[] = '- [internal] ' . $note;
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /** @return string[] */
    private static function parseAllowlist(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static function ($p) {
            return $p !== '';
        }));
    }

    /**
     * When allowlist is non-empty, only listed names or numeric IDs match.
     */
    private static function fieldMatchesAllowlist(int $fieldId, string $fieldName, array $allowlist): bool
    {
        foreach ($allowlist as $entry) {
            if (ctype_digit($entry) && (int) $entry === $fieldId) {
                return true;
            }
            if (strcasecmp($entry, $fieldName) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function isSensitiveFieldName(string $name): bool
    {
        $n = strtolower($name);
        foreach (['password', 'passwd', 'pwd', 'secret', 'token', 'apikey', 'api_key', 'pin', 'cpanel', 'secure', 'private'] as $bad) {
            if (strpos($n, $bad) !== false) {
                return true;
            }
        }

        return false;
    }
}
