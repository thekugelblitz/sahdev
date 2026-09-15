<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * Class ClientChatScopeService
 *
 * Provides high-performance, secure, tenant-isolated data grounding for the Client Live Chat assistant:
 * 1. Client-Isolated Scope: Read-only summary strictly partitioned by client ID (and service ID).
 * 2. Public / Presales Scope: Safely ground public announcements, accepted payment gateways,
 *    public promo coupons, network status, and support routing SLAs for both guests and authenticated users.
 */
class ClientChatScopeService
{
    /**
     * Check if a specific data source switch is enabled in settings.
     */
    public static function isSourceEnabled(string $key, bool $default = true): bool
    {
        return (bool) ChatService::getChatSetting($key, $default ? 1 : 0);
    }

    /**
     * Safely format monetary amount with client's native currency symbol if available.
     */
    public static function formatMoney($amount, ?object $currency = null): string
    {
        $num = number_format((float) $amount, 2);
        if ($currency) {
            $prefix = $currency->prefix ?? '';
            $suffix = $currency->suffix ?? '';
            return "{$prefix}{$num}{$suffix}";
        }
        return '$' . $num;
    }

    /**
     * Safely check if a collection, array, or countable has items.
     */
    public static function hasItems($data): bool
    {
        if (empty($data)) {
            return false;
        }
        if (is_array($data) || $data instanceof \Countable) {
            return count($data) > 0;
        }
        return true;
    }

    /**
     * Build the authenticated client-isolated data projection.
     * Enforces strict multi-tenant isolation: every single query is bound to $clientId.
     */
    public static function buildClientScope(?int $clientId): string
    {
        if (!$clientId || $clientId <= 0) {
            return "VISITOR AUTHENTICATION: Unauthenticated Guest (Not logged in).\n"
                . "ACCOUNT ACCESS: None. No WHMCS client account is associated with this visitor.\n"
                . "GUARDRAIL RULE: Do NOT disclose or guess any customer account details, services, or invoices. Instruct the visitor to log into the client portal to discuss specific account matters.";
        }

        try {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first([
                'id', 'firstname', 'lastname', 'email', 'companyname', 'status', 'datecreated', 'credit', 'currency'
            ]);
            if (!$client) {
                return "Client record not found in system.";
            }

            // Resolve client currency
            $currency = null;
            if (!empty($client->currency) && Capsule::schema()->hasTable('tblcurrencies')) {
                try {
                    $currency = Capsule::table('tblcurrencies')->where('id', $client->currency)->first();
                } catch (\Throwable $e) {}
            }

            $sections = [];

            // Client Header & Credit Balance
            $cName = trim($client->firstname . ' ' . $client->lastname) ?: 'Valued Client';
            $company = !empty($client->companyname) ? " ({$client->companyname})" : '';
            $header = "AUTHENTICATED CLIENT PROFILE:\n"
                . "- Customer: {$cName}{$company} | Status: {$client->status} | Client ID #{$client->id}";

            if (self::isSourceEnabled('client_chat_ds_credit_balance', true)) {
                $creditFormatted = self::formatMoney($client->credit ?? 0, $currency);
                $header .= "\n- Available Account Store Credit: {$creditFormatted}";
            }
            $sections[] = $header;

            // 1. Hosting Services & Server Nameservers
            if (self::isSourceEnabled('client_chat_ds_services', true)) {
                try {
                    $query = Capsule::table('tblhosting')
                        ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                        ->where('tblhosting.userid', $clientId);

                    $includeNameservers = self::isSourceEnabled('client_chat_ds_server_nameservers', true)
                        && Capsule::schema()->hasTable('tblservers');

                    if ($includeNameservers) {
                        $query->leftJoin('tblservers', 'tblhosting.server', '=', 'tblservers.id')
                              ->select([
                                  'tblhosting.id',
                                  'tblhosting.domain',
                                  'tblhosting.domainstatus',
                                  'tblhosting.nextduedate',
                                  'tblhosting.billingcycle',
                                  'tblproducts.name as product_name',
                                  'tblproducts.retired as product_retired',
                                  'tblservers.hostname as server_hostname',
                                  'tblservers.nameserver1',
                                  'tblservers.nameserver2',
                                  'tblservers.nameserver3',
                                  'tblservers.nameserver4',
                              ]);
                    } else {
                        $query->select([
                            'tblhosting.id',
                            'tblhosting.domain',
                            'tblhosting.domainstatus',
                            'tblhosting.nextduedate',
                            'tblhosting.billingcycle',
                            'tblproducts.name as product_name',
                            'tblproducts.retired as product_retired',
                        ]);
                    }

                    $services = $query->orderBy('tblhosting.id', 'desc')->limit(10)->get();

                    $activeSvcs = [];
                    $inactiveSvcs = [];

                    foreach ($services as $s) {
                        $pName = $s->product_name ?: 'Hosting Service';
                        $dom = $s->domain ?: '(No domain)';
                        $due = ($s->nextduedate && $s->nextduedate !== '0000-00-00') ? "Due: {$s->nextduedate}" : '';
                        $cycle = $s->billingcycle ? "[{$s->billingcycle}]" : '';
                        $retiredNote = !empty($s->product_retired) ? ' [Grandfathered/Retired Plan]' : '';

                        $nsInfo = '';
                        if ($includeNameservers && !empty($s->nameserver1)) {
                            $nsList = array_filter([$s->nameserver1, $s->nameserver2, $s->nameserver3 ?? null, $s->nameserver4 ?? null]);
                            $nsInfo = ' | Nameservers: ' . implode(', ', $nsList);
                            if (!empty($s->server_hostname)) {
                                $nsInfo .= " (Server: {$s->server_hostname})";
                            }
                        }

                        $line = "- Service #{$s->id}: {$pName}{$retiredNote} | Domain: {$dom} | Status: {$s->domainstatus} {$cycle} {$due}{$nsInfo}";

                        if (strcasecmp((string) $s->domainstatus, 'Active') === 0) {
                            $activeSvcs[] = $line;
                        } else {
                            $inactiveSvcs[] = $line;
                        }
                    }

                    $svcText = "CLIENT ACTIVE SERVICES (Eligible for Support & Technical Guidance):\n"
                        . (!empty($activeSvcs) ? implode("\n", $activeSvcs) : "No currently active services.");
                    if (!empty($inactiveSvcs)) {
                        $svcText .= "\n\nCLIENT INACTIVE / SUSPENDED / PENDING SERVICES (Direct client to pay unpaid invoices or wait for provisioning; do NOT debug tech issues):\n"
                            . implode("\n", $inactiveSvcs);
                    }
                    $sections[] = $svcText;
                } catch (\Throwable $e) {}
            }

            // 2. Hosting Addons
            if (self::isSourceEnabled('client_chat_ds_hosting_addons', true)
                && Capsule::schema()->hasTable('tblhostingaddons')
                && Capsule::schema()->hasTable('tbladdons')
            ) {
                try {
                    $addons = Capsule::table('tblhostingaddons as ha')
                        ->join('tblhosting as h', 'h.id', '=', 'ha.hostingid')
                        ->join('tbladdons as a', 'a.id', '=', 'ha.addonid')
                        ->where('h.userid', $clientId)
                        ->select([
                            'a.name as addon_name',
                            'h.domain as service_domain',
                            'ha.status as addon_status',
                            'ha.billingcycle',
                            'ha.nextduedate'
                        ])
                        ->orderBy('ha.id', 'desc')
                        ->limit(8)
                        ->get();

                    if (self::hasItems($addons)) {
                        $lines = ["ACTIVE HOSTING ADDONS & FEATURES:"];
                        foreach ($addons as $ad) {
                            $dom = $ad->service_domain ? " for {$ad->service_domain}" : '';
                            $due = ($ad->nextduedate && $ad->nextduedate !== '0000-00-00') ? " (Next Due: {$ad->nextduedate})" : '';
                            $lines[] = "- Addon: {$ad->addon_name}{$dom} | Status: {$ad->addon_status} [{$ad->billingcycle}]{$due}";
                        }
                        $sections[] = implode("\n", $lines);
                    }
                } catch (\Throwable $e) {}
            }

            // 3. Configurable Resource Allocations (RAM, CPU, Disk)
            if (self::isSourceEnabled('client_chat_ds_config_options', true)
                && Capsule::schema()->hasTable('tblhostingconfigoptions')
                && Capsule::schema()->hasTable('tblproductconfigoptions')
                && Capsule::schema()->hasTable('tblproductconfigoptionssub')
            ) {
                try {
                    $cfgOptions = Capsule::table('tblhostingconfigoptions as hco')
                        ->join('tblhosting as h', 'h.id', '=', 'hco.relid')
                        ->join('tblproductconfigoptions as pco', 'pco.id', '=', 'hco.configid')
                        ->join('tblproductconfigoptionssub as pcos', 'pcos.id', '=', 'hco.optionid')
                        ->where('h.userid', $clientId)
                        ->where('h.domainstatus', 'Active')
                        ->select([
                            'h.id as service_id',
                            'h.domain as service_domain',
                            'pco.optionname as option_title',
                            'pcos.optionname as selected_value'
                        ])
                        ->orderBy('h.id', 'desc')
                        ->limit(10)
                        ->get();

                    if (self::hasItems($cfgOptions)) {
                        $lines = ["CONFIGURED SERVER / SERVICE RESOURCES:"];
                        foreach ($cfgOptions as $opt) {
                            $optName = preg_replace('/\|.*/', '', $opt->option_title);
                            $optVal = preg_replace('/\|.*/', '', $opt->selected_value);
                            $dom = $opt->service_domain ? " ({$opt->service_domain})" : " (Service #{$opt->service_id})";
                            $lines[] = "- {$optName}: {$optVal}{$dom}";
                        }
                        $sections[] = implode("\n", $lines);
                    }
                } catch (\Throwable $e) {}
            }

            // 4. SSL Certificates
            if (self::isSourceEnabled('client_chat_ds_ssl_orders', true)) {
                $sslTable = Capsule::schema()->hasTable('tblsslorders') ? 'tblsslorders' : (Capsule::schema()->hasTable('tblssl') ? 'tblssl' : null);
                if ($sslTable) {
                    try {
                        $sslOrders = Capsule::table($sslTable)
                            ->where('userid', $clientId)
                            ->orderBy('id', 'desc')
                            ->limit(4)
                            ->get();

                        if (self::hasItems($sslOrders)) {
                            $lines = ["ACTIVE SSL CERTIFICATES:"];
                            foreach ($sslOrders as $ssl) {
                                $cType = !empty($ssl->certtype) ? $ssl->certtype : 'SSL Certificate';
                                $st = !empty($ssl->status) ? $ssl->status : 'Active';
                                $lines[] = "- SSL: {$cType} | Status: {$st}";
                            }
                            $sections[] = implode("\n", $lines);
                        }
                    } catch (\Throwable $e) {}
                }
            }

            // 5. Registered Domains & Features
            if (self::isSourceEnabled('client_chat_ds_domains', true) && Capsule::schema()->hasTable('tbldomains')) {
                try {
                    $checkAddons = self::isSourceEnabled('client_chat_ds_domain_addons', true);
                    $fields = ['id', 'domain', 'status', 'expirydate', 'donotrenew'];
                    if ($checkAddons && Capsule::schema()->hasColumn('tbldomains', 'idprotection')) {
                        $fields[] = 'idprotection';
                    }
                    if ($checkAddons && Capsule::schema()->hasColumn('tbldomains', 'dnsmanagement')) {
                        $fields[] = 'dnsmanagement';
                    }
                    if ($checkAddons && Capsule::schema()->hasColumn('tbldomains', 'emailforwarding')) {
                        $fields[] = 'emailforwarding';
                    }

                    $domains = Capsule::table('tbldomains')
                        ->where('userid', $clientId)
                        ->orderBy('id', 'desc')
                        ->limit(10)
                        ->get($fields);

                    $activeDoms = [];
                    $inactiveDoms = [];

                    foreach ($domains as $d) {
                        $exp = ($d->expirydate && $d->expirydate !== '0000-00-00') ? "Expires: {$d->expirydate}" : '';
                        $renew = $d->donotrenew ? '[Auto-Renew Off]' : '[Auto-Renew On]';

                        $addons = [];
                        if (!empty($d->idprotection)) $addons[] = 'WHOIS Privacy';
                        if (!empty($d->dnsmanagement)) $addons[] = 'DNS Mgmt';
                        if (!empty($d->emailforwarding)) $addons[] = 'Email Forwarding';
                        $addonStr = !empty($addons) ? ' (' . implode(', ', $addons) . ' Active)' : '';

                        $line = "- Domain: {$d->domain} | Status: {$d->status} {$renew} {$exp}{$addonStr}";

                        if (strcasecmp((string) $d->status, 'Active') === 0) {
                            $activeDoms[] = $line;
                        } else {
                            $inactiveDoms[] = $line;
                        }
                    }

                    $domText = "CLIENT ACTIVE DOMAINS (Eligible for DNS Management Assistance):\n"
                        . (!empty($activeDoms) ? implode("\n", $activeDoms) : "No active domains registered.");
                    if (!empty($inactiveDoms)) {
                        $domText .= "\n\nCLIENT INACTIVE / EXPIRED / PENDING DOMAINS (Direct to domain renewal):\n"
                            . implode("\n", $inactiveDoms);
                    }
                    $sections[] = $domText;
                } catch (\Throwable $e) {}
            }

            // 6. Invoices
            if (self::isSourceEnabled('client_chat_ds_invoices', true) && Capsule::schema()->hasTable('tblinvoices')) {
                try {
                    $invoices = Capsule::table('tblinvoices')
                        ->where('userid', $clientId)
                        ->orderBy('id', 'desc')
                        ->limit(5)
                        ->get(['id', 'invoicenum', 'total', 'status', 'duedate']);

                    $invLines = [];
                    foreach ($invoices as $inv) {
                        $num = !empty($inv->invoicenum) ? $inv->invoicenum : '#' . $inv->id;
                        $amt = self::formatMoney($inv->total, $currency);
                        $invLines[] = "- Invoice {$num}: Total {$amt} | Status: {$inv->status} | Due: {$inv->duedate}";
                    }

                    $sections[] = "RECENT INVOICES (Read-Only):\n"
                        . (!empty($invLines) ? implode("\n", $invLines) : "No recent invoices.");
                } catch (\Throwable $e) {}
            }

            // 7. Support Tickets
            if (self::isSourceEnabled('client_chat_ds_tickets', true) && Capsule::schema()->hasTable('tbltickets')) {
                try {
                    $tickets = Capsule::table('tbltickets')
                        ->where('userid', $clientId)
                        ->orderBy('id', 'desc')
                        ->limit(5)
                        ->get(['id', 'tid', 'title', 'status', 'lastreply']);

                    $tktLines = [];
                    foreach ($tickets as $t) {
                        $tktLines[] = "- Ticket #{$t->tid}: {$t->title} | Status: {$t->status} | Last Activity: {$t->lastreply}";
                    }

                    $sections[] = "RECENT TICKETS (Read-Only):\n"
                        . (!empty($tktLines) ? implode("\n", $tktLines) : "No recent tickets.");
                } catch (\Throwable $e) {}
            }

            // 8. Open Sales Quotes & Estimates
            if (self::isSourceEnabled('client_chat_ds_quotes', true) && Capsule::schema()->hasTable('tblquotes')) {
                try {
                    $quotes = Capsule::table('tblquotes')
                        ->where('userid', $clientId)
                        ->whereIn('stage', ['Delivered', 'On Hold', 'Accepted'])
                        ->orderBy('id', 'desc')
                        ->limit(3)
                        ->get(['id', 'subject', 'stage', 'validuntil', 'total']);

                    if (self::hasItems($quotes)) {
                        $qLines = ["ACTIVE FORMAL SALES QUOTES:"];
                        foreach ($quotes as $q) {
                            $amt = self::formatMoney($q->total, $currency);
                            $valid = ($q->validuntil && $q->validuntil !== '0000-00-00') ? " (Valid until: {$q->validuntil})" : '';
                            $qLines[] = "- Quote #{$q->id}: {$q->subject} | Stage: {$q->stage} | Total: {$amt}{$valid}";
                        }
                        $sections[] = implode("\n", $qLines);
                    }
                } catch (\Throwable $e) {}
            }

            return implode("\n\n", $sections);
        } catch (\Throwable $e) {
            ModuleLogger::error('client_chat', "Client scope generation error: " . $e->getMessage());
            return "Client account information could not be retrieved.";
        }
    }

    /**
     * Build the public and presales scope summary.
     * Safely accessible to BOTH unauthenticated visitors and logged-in clients.
     */
    public static function buildPublicScope(): string
    {
        $sections = [];

        // 1. Active Network & Server Incidents
        if (self::isSourceEnabled('client_chat_ds_network_issues', true) && Capsule::schema()->hasTable('tblnetworkissues')) {
            try {
                $issues = Capsule::table('tblnetworkissues')
                    ->whereIn('status', ['Open', 'In Progress'])
                    ->orderBy('id', 'desc')
                    ->limit(3)
                    ->get(['id', 'title', 'status', 'priority', 'description']);

                if (self::hasItems($issues)) {
                    $netLines = ["ACTIVE NETWORK & SERVER INCIDENTS:"];
                    foreach ($issues as $ni) {
                        $desc = mb_substr(strip_tags($ni->description), 0, 160);
                        $netLines[] = "- Incident: {$ni->title} [Status: {$ni->status}, Priority: {$ni->priority}] - {$desc}";
                    }
                    $sections[] = implode("\n", $netLines);
                }
            } catch (\Throwable $e) {}
        }

        // 2. Official Public Announcements & Maintenance Notices
        if (self::isSourceEnabled('client_chat_ds_announcements', true) && Capsule::schema()->hasTable('tblannouncements')) {
            try {
                $announcements = Capsule::table('tblannouncements')
                    ->where('published', 1)
                    ->orderBy('date', 'desc')
                    ->limit(3)
                    ->get(['id', 'date', 'title', 'announcement']);

                if (self::hasItems($announcements)) {
                    $annLines = ["LATEST PUBLIC ANNOUNCEMENTS:"];
                    foreach ($announcements as $ann) {
                        $text = mb_substr(strip_tags($ann->announcement), 0, 150);
                        $annLines[] = "- [{$ann->date}] {$ann->title}: {$text}";
                    }
                    $sections[] = implode("\n", $annLines);
                }
            } catch (\Throwable $e) {}
        }

        // 3. Active Public Promotional Coupons (Presales Conversions)
        if (self::isSourceEnabled('client_chat_ds_promotions', true) && Capsule::schema()->hasTable('tblpromotions')) {
            try {
                $today = date('Y-m-d');
                $promos = Capsule::table('tblpromotions')
                    ->where('showonorder', 1)
                    ->where(function ($q) use ($today) {
                        $q->where('expirationdate', '0000-00-00')
                          ->orWhere('expirationdate', '>=', $today)
                          ->orWhereNull('expirationdate');
                    })
                    ->where(function ($q) {
                        $q->where('maxuses', 0)
                          ->orWhereRaw('uses < maxuses');
                    })
                    ->orderBy('id', 'desc')
                    ->limit(4)
                    ->get(['code', 'type', 'value', 'recurring', 'notes', 'expirationdate']);

                if (self::hasItems($promos)) {
                    $promoLines = ["OFFICIAL PUBLIC PROMOTIONAL CODES (Share with presales visitors asking for discounts):"];
                    foreach ($promos as $pr) {
                        $val = (strcasecmp((string) $pr->type, 'Percentage') === 0) ? "{$pr->value}% OFF" : "\${$pr->value} OFF";
                        $rec = !empty($pr->recurring) ? " (Recurring discount)" : " (First payment)";
                        $noteStr = !empty($pr->notes) ? " - " . strip_tags($pr->notes) : "";
                        $promoLines[] = "- Promo Code: '{$pr->code}' — Gives {$val}{$rec}{$noteStr}";
                    }
                    $sections[] = implode("\n", $promoLines);
                }
            } catch (\Throwable $e) {}
        }

        // 4. Accepted Payment Gateways
        if (self::isSourceEnabled('client_chat_ds_payment_gateways', true) && Capsule::schema()->hasTable('tblpaymentgateways')) {
            try {
                $gateways = Capsule::table('tblpaymentgateways')
                    ->where('setting', 'name')
                    ->pluck('value');

                if (self::hasItems($gateways)) {
                    $gatewayArr = is_array($gateways) ? $gateways : (method_exists($gateways, 'toArray') ? $gateways->toArray() : (array) $gateways);
                    $gatewayList = implode(', ', $gatewayArr);
                    $sections[] = "ACCEPTED PAYMENT METHODS:\n- Available checkout gateways: {$gatewayList}";
                }
            } catch (\Throwable $e) {}
        }

        // 5. Support Department Routing & SLAs
        if (self::isSourceEnabled('client_chat_ds_departments', true) && Capsule::schema()->hasTable('tblticketdepartments')) {
            try {
                $depts = Capsule::table('tblticketdepartments')
                    ->where('hidden', 0)
                    ->orderBy('order', 'asc')
                    ->get(['id', 'name', 'description']);

                if (self::hasItems($depts)) {
                    $deptLines = ["SUPPORT DEPARTMENTS & ESCALATION ROUTING:"];
                    foreach ($depts as $d) {
                        $desc = !empty($d->description) ? " — " . strip_tags($d->description) : "";
                        $deptLines[] = "- Department: {$d->name}{$desc}";
                    }
                    $sections[] = implode("\n", $deptLines);
                }
            } catch (\Throwable $e) {}
        }

        return !empty($sections) ? implode("\n\n", $sections) : "";
    }
}
