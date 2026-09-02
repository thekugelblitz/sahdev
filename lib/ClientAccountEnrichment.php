<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/ModuleLogger.php';

/**
 * Read-only WHMCS account snippets for AI context (userid-scoped).
 * Each subsection is isolated in try/catch so failures never break the ticket panel or cron.
 */
class ClientAccountEnrichment
{
    private const INTERNAL_MAX_RETURN_CHARS = 16000;

    /**
     * @param int $clientUserId tblclients.id / tbltickets.userid
     * @param array $options Settings row fields (context_enrichment_*)
     * @param int|null $ticketIdForScope Current ticket ID for ticket-specific logs
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

        // 1. Client Profile & VIP / Group (Phase 1)
        if (!empty($options['context_enrichment_client_profile'])) {
            try {
                $s = self::sectionClientProfile($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('client_profile', $e, $ticketIdForScope);
            }
        }

        // 2. Active Cancellation Requests (Phase 2 - Churn signals top priority)
        if (!empty($options['context_enrichment_cancellations'])) {
            try {
                $s = self::sectionCancellations($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('cancellations', $e, $ticketIdForScope);
            }
        }

        // 3. Recent Orders & Fraud Flags (Phase 2)
        if (!empty($options['context_enrichment_orders'])) {
            try {
                $s = self::sectionOrders($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('orders', $e, $ticketIdForScope);
            }
        }

        // 4. Invoices (Phase 1)
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

        // 5. Invoice Line Items Breakdown (Phase 2)
        if (!empty($options['context_enrichment_invoice_items'])) {
            try {
                $s = self::sectionInvoiceItems($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('invoice_items', $e, $ticketIdForScope);
            }
        }

        // 6. Transactions & Lifetime Spend (Phase 2)
        if (!empty($options['context_enrichment_transactions'])) {
            try {
                $s = self::sectionTransactions($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('transactions', $e, $ticketIdForScope);
            }
        }

        // 7. Hosting & Servers with Suspend Reasons (Phase 1)
        if (!empty($options['context_enrichment_hosting'])) {
            try {
                $s = self::sectionHosting($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('hosting', $e, $ticketIdForScope);
            }
        }

        // 8. Domains with Auto-Renew & DNS Status (Phase 1)
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

        // 9. Hosting Addons (Phase 1)
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

        // 10. SSL Orders (Phase 4)
        if (!empty($options['context_enrichment_ssl'])) {
            try {
                $s = self::sectionSSLOrders($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('ssl', $e, $ticketIdForScope);
            }
        }

        // 11. Sales Quotes (Phase 4)
        if (!empty($options['context_enrichment_quotes'])) {
            try {
                $s = self::sectionQuotes($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('quotes', $e, $ticketIdForScope);
            }
        }

        // 12. Ticket Journey & Log (Phase 3)
        if (!empty($options['context_enrichment_ticket_log']) && $ticketIdForScope !== null && $ticketIdForScope > 0) {
            try {
                $s = self::sectionTicketLog($ticketIdForScope);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('ticket_log', $e, $ticketIdForScope);
            }
        }

        // 13. Recent Emails Sent (Phase 3)
        if (!empty($options['context_enrichment_emails'])) {
            try {
                $s = self::sectionEmailLog($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('emails', $e, $ticketIdForScope);
            }
        }

        // 14. Authorized Contacts / Sub-Accounts (Phase 3)
        if (!empty($options['context_enrichment_contacts'])) {
            try {
                $s = self::sectionContacts($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('contacts', $e, $ticketIdForScope);
            }
        }

        // 15. Custom Fields (Phase 1)
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

        // 16. Staff Client Notes (Phase 1)
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

        // 17. Client Activity Log (Phase 4 - Disabled by default)
        if (!empty($options['context_enrichment_activity_log'])) {
            try {
                $s = self::sectionActivityLog($clientUserId);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } catch (\Throwable $e) {
                self::logSectionFailure('activity_log', $e, $ticketIdForScope);
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

    /**
     * 1. Client Profile & Group (VIP status, credit balance, language, last login)
     */
    private static function sectionClientProfile(int $userid): string
    {
        if (!self::hasTable('tblclients')) {
            return '';
        }

        $client = Capsule::table('tblclients')->where('id', $userid)->first();
        if (!$client) {
            return '';
        }

        $status = (string) ($client->status ?? 'Active');
        $credit = isset($client->credit) ? (float) $client->credit : 0.0;
        $currencyCode = self::getCurrencyCode($client->currency ?? 0);
        $dateCreated = isset($client->datecreated) ? substr((string) $client->datecreated, 0, 10) : '';
        $lastLogin = isset($client->lastlogin) && $client->lastlogin && (string) $client->lastlogin !== '0000-00-00 00:00:00'
            ? substr((string) $client->lastlogin, 0, 16)
            : 'Never / Unknown';

        $groupInfo = '';
        $groupId = (int) ($client->groupid ?? 0);
        if ($groupId > 0 && self::hasTable('tblclientgroups')) {
            try {
                $group = Capsule::table('tblclientgroups')->where('id', $groupId)->first();
                if ($group && !empty($group->groupname)) {
                    $discountStr = !empty($group->discountpercent) ? " ({$group->discountpercent}% discount)" : '';
                    $groupInfo = " | Client Group: {$group->groupname}{$discountStr}";
                }
            } catch (\Throwable $e) {}
        }

        $creditFormatted = number_format($credit, 2) . ' ' . $currencyCode;

        $lines = ["Client account profile:"];
        $lines[] = "- Status: {$status}" . ($dateCreated !== '' ? " | Client since: {$dateCreated}" : '') . $groupInfo;
        $lines[] = "- Account Credit: {$creditFormatted} | Last Portal Login: {$lastLogin}";

        return implode("\n", $lines);
    }

    /**
     * 2. Active Cancellation Requests
     */
    private static function sectionCancellations(int $userid): string
    {
        if (!self::hasTable('tblcancelrequests') || !self::hasTable('tblhosting')) {
            return '';
        }

        $query = Capsule::table('tblcancelrequests as cr')
            ->join('tblhosting as h', 'h.id', '=', 'cr.relid')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.userid', $userid)
            ->orderBy('cr.id', 'desc')
            ->limit(3)
            ->select('cr.id', 'cr.type', 'cr.reason', 'cr.date as cancel_date', 'h.domain', 'p.name as product_name');

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['⚠️ Active cancellation requests:'];
        foreach ($rows as $r) {
            $product = (string) ($r->product_name ?? 'Service');
            $dom = !empty($r->domain) ? " ({$r->domain})" : '';
            $type = (string) ($r->type ?? 'Immediate');
            $date = isset($r->cancel_date) ? substr((string) $r->cancel_date, 0, 10) : '';
            $reason = trim((string) ($r->reason ?? ''));
            if (strlen($reason) > 150) {
                $reason = substr($reason, 0, 147) . '...';
            }
            $reasonStr = $reason !== '' ? " | Reason: {$reason}" : '';
            $lines[] = "- [CANCELLATION REQUESTED] {$product}{$dom}: Type: {$type}" . ($date !== '' ? ", Date: {$date}" : '') . $reasonStr;
        }

        return implode("\n", $lines);
    }

    /**
     * 3. Recent Orders & Fraud Flags
     */
    private static function sectionOrders(int $userid): string
    {
        if (!self::hasTable('tblorders')) {
            return '';
        }

        $rows = Capsule::table('tblorders')
            ->where('userid', $userid)
            ->orderBy('id', 'desc')
            ->limit(3)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Recent orders:'];
        foreach ($rows as $r) {
            $orderNum = !empty($r->ordernum) ? (string) $r->ordernum : '#' . (int) $r->id;
            $status = (string) ($r->status ?? 'Active');
            $amount = isset($r->amount) ? (string) $r->amount : '0.00';
            $date = isset($r->date) ? substr((string) $r->date, 0, 10) : '';
            $gateway = !empty($r->paymentmethod) ? (string) $r->paymentmethod : '';
            $invoiceId = !empty($r->invoiceid) ? " | Invoice #{$r->invoiceid}" : '';

            $fraudStr = '';
            if (strcasecmp($status, 'Fraud') === 0 || !empty($r->fraudoutput)) {
                $fo = trim(strip_tags((string) ($r->fraudoutput ?? '')));
                if (strlen($fo) > 100) $fo = substr($fo, 0, 97) . '...';
                $fraudStr = " | ⚠️ FRAUD CHECK: " . ($fo !== '' ? $fo : 'Flagged');
            }

            $line = "- Order {$orderNum}: {$status}, Total: {$amount}" . ($date !== '' ? ", Date: {$date}" : '') . ($gateway !== '' ? " ({$gateway})" : '') . $invoiceId . $fraudStr;
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * 4. Invoices
     */
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
            $due = isset($r->duedate) && (string) $r->duedate !== '0000-00-00' ? substr((string) $r->duedate, 0, 10) : '';
            $dueStr = $due !== '' ? ", Due: {$due}" : '';
            $lines[] = "- {$label}: {$status}, {$total}{$cur}" . ($date !== '' ? ", Date: {$date}" : '') . $dueStr;
        }

        return implode("\n", $lines);
    }

    /**
     * 5. Invoice Line Items Breakdown (latest unpaid/recent invoice line items)
     */
    private static function sectionInvoiceItems(int $userid): string
    {
        if (!self::hasTable('tblinvoices') || !self::hasTable('tblinvoiceitems')) {
            return '';
        }

        // Get latest unpaid or latest invoice
        $invoice = Capsule::table('tblinvoices')
            ->where('userid', $userid)
            ->whereIn('status', ['Unpaid', 'Payment Pending', 'Overdue'])
            ->orderBy('id', 'desc')
            ->first();

        if (!$invoice) {
            $invoice = Capsule::table('tblinvoices')
                ->where('userid', $userid)
                ->orderBy('id', 'desc')
                ->first();
        }

        if (!$invoice) {
            return '';
        }

        $items = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoice->id)
            ->limit(5)
            ->get();

        if ($items->isEmpty()) {
            return '';
        }

        $invLabel = !empty($invoice->invoicenum) ? $invoice->invoicenum : '#' . $invoice->id;
        $lines = ["Invoice line items (Invoice {$invLabel} - {$invoice->status}, Total: {$invoice->total}):"];
        foreach ($items as $item) {
            $desc = trim((string) ($item->description ?? 'Line item'));
            if (strlen($desc) > 120) {
                $desc = substr($desc, 0, 117) . '...';
            }
            $amount = isset($item->amount) ? (string) $item->amount : '0.00';
            $lines[] = "- {$desc}: {$amount}";
        }

        return implode("\n", $lines);
    }

    /**
     * 6. Transactions & Lifetime Customer Spend
     */
    private static function sectionTransactions(int $userid): string
    {
        if (!self::hasTable('tbltransactions') && !self::hasTable('tblaccounts')) {
            return '';
        }

        $tableName = self::hasTable('tbltransactions') ? 'tbltransactions' : 'tblaccounts';

        $totalSpent = (float) Capsule::table($tableName)->where('userid', $userid)->sum('amountin');
        $currencyCode = self::getClientCurrencyCode($userid);
        $lifetimeFormatted = number_format($totalSpent, 2) . ' ' . $currencyCode;

        $rows = Capsule::table($tableName)
            ->where('userid', $userid)
            ->orderBy('id', 'desc')
            ->limit(3)
            ->get();

        if ($rows->isEmpty() && $totalSpent <= 0) {
            return '';
        }

        $lines = ["Payment & transaction summary:"];
        $lines[] = "- Lifetime Spend: {$lifetimeFormatted} (Total payments received)";

        foreach ($rows as $r) {
            $date = isset($r->date) ? substr((string) $r->date, 0, 10) : '';
            $in = (float) ($r->amountin ?? 0);
            $out = (float) ($r->amountout ?? 0);
            $gateway = !empty($r->gateway) ? (string) $r->gateway : 'Gateway';
            $txId = !empty($r->transid) ? " (TxID: {$r->transid})" : '';

            if ($in > 0) {
                $lines[] = "- Payment: " . number_format($in, 2) . " via {$gateway} on {$date}{$txId}";
            } elseif ($out > 0) {
                $lines[] = "- Refund/Chargeback: " . number_format($out, 2) . " via {$gateway} on {$date}{$txId}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 7. Hosting & Servers with Billing Cycles & Suspend Reasons
     */
    private static function sectionHosting(int $userid): string
    {
        if (!self::hasTable('tblhosting') || !self::hasTable('tblproducts')) {
            return '';
        }

        $query = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->leftJoin('tblservers as s', 'h.server', '=', 's.id')
            ->where('h.userid', $userid)
            ->whereIn('h.domainstatus', ['Active', 'Suspended', 'Pending'])
            ->select(
                'p.name as product_name',
                'h.domain',
                'h.username',
                'h.dedicatedip',
                'h.assignedips',
                'h.domainstatus',
                'h.billingcycle',
                'h.amount',
                'h.nextduedate',
                'h.regdate',
                'h.suspendreason',
                'h.paymentmethod',
                's.name as server_name',
                's.ipaddress as server_ip',
                's.hostname as server_host',
                's.nameserver1', 's.nameserver2', 's.nameserver3', 's.nameserver4', 's.nameserver5'
            )
            ->orderBy('h.id', 'desc')
            ->limit(10);

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
            ];

            if (strcasecmp($st, 'Suspended') === 0 && !empty($r->suspendreason)) {
                $details[] = "SUSPEND_REASON: " . strip_tags((string) $r->suspendreason);
            }

            if (!empty($r->billingcycle)) {
                $amtStr = !empty($r->amount) && (float)$r->amount > 0 ? " ({$r->amount})" : "";
                $dueStr = !empty($r->nextduedate) && (string)$r->nextduedate !== '0000-00-00' ? " | Due: {$r->nextduedate}" : "";
                $details[] = "Billing: {$r->billingcycle}{$amtStr}{$dueStr}";
            }

            if ($ip) $details[] = "Product IP: {$ip}";
            if (!empty($r->server_host)) $details[] = "SERVER_HOSTNAME: {$r->server_host}";
            if (!empty($r->server_ip)) $details[] = "SERVER_IP: {$r->server_ip}";
            if ($ns !== []) $details[] = "MANDATORY_TARGET_NS: " . implode(', ', $ns);
            
            $lines[] = "- {$name} ({$dom}): " . implode(' | ', $details);
        }

        return implode("\n", $lines);
    }

    /**
     * 8. Domains with Auto-Renew, Addons & Expiry
     */
    private static function sectionDomains(int $userid): string
    {
        if (!self::hasTable('tbldomains')) {
            return '';
        }

        $rows = Capsule::table('tbldomains')
            ->where('userid', $userid)
            ->whereNotIn('status', ['Cancelled', 'Expired', 'Fraud'])
            ->orderBy('expirydate', 'asc')
            ->limit(10)
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
            
            $addons = [];
            if (!empty($r->dnsmanagement)) $addons[] = 'DNS Mgmt';
            if (!empty($r->emailforwarding)) $addons[] = 'Email Fwd';
            if (!empty($r->idprotection)) $addons[] = 'ID Protect';
            $addonStr = $addons !== [] ? ' | Addons: ' . implode(', ', $addons) : '';

            $autoRenew = isset($r->autorenew) ? ((int)$r->autorenew === 1 ? 'ON' : 'OFF') : '';
            $autoRenewStr = $autoRenew !== '' ? " | Auto-Renew: {$autoRenew}" : '';

            $ns = [];
            for ($i = 1; $i <= 5; $i++) {
                $f = 'ns' . $i;
                if (!empty($r->$f)) $ns[] = (string) $r->$f;
            }
            $nsStr = $ns !== [] ? (' (Current Registrar NS: ' . implode(', ', $ns) . ')') : '';

            $lines[] = "- {$dom}: {$st}{$ex}{$autoRenewStr}{$addonStr}{$nsStr}";
        }

        return implode("\n", $lines);
    }

    /**
     * 9. Hosting Addons
     */
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
            ->limit(8)
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

    /**
     * 10. SSL Orders (tblsslorders / tblssl)
     */
    private static function sectionSSLOrders(int $userid): string
    {
        $sslTable = self::hasTable('tblsslorders') ? 'tblsslorders' : (self::hasTable('tblssl') ? 'tblssl' : null);
        if (!$sslTable) {
            return '';
        }

        $rows = Capsule::table($sslTable)
            ->where('userid', $userid)
            ->orderBy('id', 'desc')
            ->limit(4)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['SSL Certificates:'];
        foreach ($rows as $r) {
            $certType = !empty($r->certtype) ? (string) $r->certtype : 'SSL Certificate';
            $status = !empty($r->status) ? (string) $r->status : 'Active';
            $date = !empty($r->completiondate) && (string) $r->completiondate !== '0000-00-00 00:00:00'
                ? ', Issued: ' . substr((string) $r->completiondate, 0, 10)
                : '';
            $lines[] = "- {$certType}: Status: {$status}{$date}";
        }

        return implode("\n", $lines);
    }

    /**
     * 11. Sales Quotes (tblquotes)
     */
    private static function sectionQuotes(int $userid): string
    {
        if (!self::hasTable('tblquotes')) {
            return '';
        }

        $rows = Capsule::table('tblquotes')
            ->where('userid', $userid)
            ->whereNotIn('stage', ['Lost', 'Dead'])
            ->orderBy('id', 'desc')
            ->limit(3)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Recent sales quotes:'];
        foreach ($rows as $r) {
            $num = '#' . (int) $r->id;
            $subject = !empty($r->subject) ? (string) $r->subject : 'Quote';
            $stage = (string) ($r->stage ?? 'Delivered');
            $total = isset($r->total) ? (string) $r->total : '0.00';
            $valid = !empty($r->validuntil) && (string) $r->validuntil !== '0000-00-00' ? ', Valid until: ' . substr((string) $r->validuntil, 0, 10) : '';
            $lines[] = "- Quote {$num} \"{$subject}\": {$stage}, Total: {$total}{$valid}";
        }

        return implode("\n", $lines);
    }

    /**
     * 12. Ticket Journey & Activity Log (tblticketlog)
     */
    private static function sectionTicketLog(int $ticketId): string
    {
        $logTable = self::hasTable('tblticketlog') ? 'tblticketlog' : (self::hasTable('tblticketlogs') ? 'tblticketlogs' : null);
        if (!$logTable || $ticketId <= 0) {
            return '';
        }

        $rows = Capsule::table($logTable)
            ->where('tid', $ticketId)
            ->orWhere('ticketid', $ticketId)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        // Reverse to chronological
        $rows = $rows->reverse();
        $lines = ['Ticket journey & activity log:'];
        foreach ($rows as $r) {
            $date = isset($r->date) ? substr((string) $r->date, 0, 16) : '';
            $action = trim(strip_tags((string) ($r->action ?? '')));
            if ($action === '') continue;
            if (strlen($action) > 120) $action = substr($action, 0, 117) . '...';
            $lines[] = "- [{$date}] {$action}";
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * 13. Recent Outgoing Emails Sent to Client (tblemails)
     */
    private static function sectionEmailLog(int $userid): string
    {
        if (!self::hasTable('tblemails')) {
            return '';
        }

        $rows = Capsule::table('tblemails')
            ->where('userid', $userid)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Recent emails sent to client:'];
        foreach ($rows as $r) {
            $date = isset($r->date) ? substr((string) $r->date, 0, 10) : '';
            $subj = trim(strip_tags((string) ($r->subject ?? '')));
            if ($subj === '') continue;
            if (strlen($subj) > 80) $subj = substr($subj, 0, 77) . '...';
            $lines[] = "- [{$date}] \"{$subj}\"";
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * 14. Authorized Sub-Accounts / Contacts (tblcontacts)
     */
    private static function sectionContacts(int $userid): string
    {
        if (!self::hasTable('tblcontacts')) {
            return '';
        }

        $rows = Capsule::table('tblcontacts')
            ->where('userid', $userid)
            ->limit(4)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Authorized contacts / sub-accounts:'];
        foreach ($rows as $r) {
            $name = trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? ''));
            $email = (string) ($r->email ?? '');
            $isSub = !empty($r->subaccount);
            $type = $isSub ? 'Sub-Account (Portal Access)' : 'Contact Only';
            $lines[] = "- {$name} ({$email}): {$type}";
        }

        return implode("\n", $lines);
    }

    /**
     * 15. Custom Fields
     */
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

    /**
     * 16. Staff Client Notes
     */
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

    /**
     * 17. Client Activity Log (tblactivitylog)
     */
    private static function sectionActivityLog(int $userid): string
    {
        if (!self::hasTable('tblactivitylog')) {
            return '';
        }

        $rows = Capsule::table('tblactivitylog')
            ->where('userid', $userid)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = ['Recent client activity:'];
        foreach ($rows as $r) {
            $date = isset($r->date) ? substr((string) $r->date, 0, 16) : '';
            $desc = trim(strip_tags((string) ($r->description ?? '')));
            if ($desc === '') continue;
            if (strlen($desc) > 100) $desc = substr($desc, 0, 97) . '...';
            $ip = !empty($r->ipaddr) ? " (IP: {$r->ipaddr})" : '';
            $lines[] = "- [{$date}] {$desc}{$ip}";
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * Currency helper
     */
    private static function getCurrencyCode(int $currencyId): string
    {
        if ($currencyId > 0 && self::hasTable('tblcurrencies')) {
            try {
                $code = Capsule::table('tblcurrencies')->where('id', $currencyId)->value('code');
                if (!empty($code)) return (string) $code;
            } catch (\Throwable $e) {}
        }
        return 'USD';
    }

    private static function getClientCurrencyCode(int $userid): string
    {
        if ($userid > 0 && self::hasTable('tblclients')) {
            try {
                $curId = (int) Capsule::table('tblclients')->where('id', $userid)->value('currency');
                if ($curId > 0) {
                    return self::getCurrencyCode($curId);
                }
            } catch (\Throwable $e) {}
        }
        return 'USD';
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
        foreach (['password', 'passwd', 'pwd', 'secret', 'token', 'apikey', 'api_key', 'pin', 'cpanel', 'secure', 'private', 'card', 'cvv', 'hash', 'auth'] as $bad) {
            if (strpos($n, $bad) !== false) {
                return true;
            }
        }

        return false;
    }
}
