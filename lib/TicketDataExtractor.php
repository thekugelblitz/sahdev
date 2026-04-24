<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/ClientAccountEnrichment.php';
require_once __DIR__ . '/ModuleLogger.php';

class TicketDataExtractor
{
    private $ticketId;
    private $adminId;

    // Limits to prevent massive memory usage
    private $maxMessages = 10;
    private $maxAttachmentChars = 5000;
    private $maxAttachmentSize = 1048576; // 1 MB (extracted text threshold)
    private $maxImages = 3; // Max number of images to process

    public function __construct(int $ticketId, int $adminId = null)
    {
        $this->ticketId = $ticketId;
        $this->adminId = $adminId;

        // Fetch dynamic limits from settings
        try {
            $settings = Capsule::table('tblsahdev_settings')->select('max_messages', 'max_attachment_chars', 'max_images')->first();
            if ($settings) {
                $this->maxMessages = (int) ($settings->max_messages ?? 10);
                $this->maxAttachmentChars = (int) ($settings->max_attachment_chars ?? 5000);
                $this->maxImages = (int) ($settings->max_images ?? 3);
            }
        } catch (\Exception $e) {
            // Fallback to defaults if columns are missing or db fails
        }
    }

    /**
     * Extracts full context securely for a specific ticket.
     * Uses query builder entirely (no raw SQL).
     *
     * @param bool $scrubPII Whether to run the compliance PII scrubber over the context data.
     * @param bool $includeAdminNotes Whether to include private ticket notes in the context.
     * @return array
     * @throws \Exception
     */
    public function getContext(bool $scrubPII = false, bool $includeAdminNotes = false): array
    {
        $context = [];

        // 1. Fetch main ticket data
        $ticket = Capsule::table('tbltickets')
            ->select('id', 'tid', 'did', 'userid', 'contactid', 'name', 'email', 'title', 'message', 'status', 'urgency', 'lastreply', 'date', 'service')
            ->where('id', $this->ticketId)
            ->first();

        if (!$ticket) {
            throw new \Exception("Ticket not found.");
        }

        $context['subject'] = $ticket->title;
        $context['priority'] = $ticket->urgency;
        $context['department'] = $this->getDepartmentName($ticket->did);
        $context['client_email'] = (string) $ticket->email;
        $context['userid'] = (int) $ticket->userid;
        $context['ticket_id'] = (int) $ticket->id;

        // 2. Fetch Client Info
        $context['client_name'] = $this->extractClientName($ticket);
        $context['services_summary'] = $this->buildServicesSummaryBlock($ticket, $scrubPII, null);

        // 3. Fetch Message History (Replies + Original Message)
        $context['messages'] = $this->extractMessages($ticket);

        // 4. Extract simple text from attachments (if any and if safe)
        $context['attachments_text'] = $this->extractAttachmentText($ticket);

        // 4.5. Extract images and URLs
        $context['attachments_images'] = $this->extractImageAttachments($ticket);
        $context['attachments_images'] = array_merge($context['attachments_images'], $this->extractImageUrls($ticket));
        
        // Cap the total images to maxImages
        if (count($context['attachments_images']) > $this->maxImages) {
            $context['attachments_images'] = array_slice($context['attachments_images'], 0, $this->maxImages);
        }

        // 5. Extract active admin's signature
        $context['admin_signature'] = $this->extractAdminSignature();

        // 5.5 Extract Admin Notes if requested
        if ($includeAdminNotes) {
            $context['admin_notes'] = $this->extractAdminNotes($this->ticketId);
            if ($scrubPII && $context['admin_notes']) {
                $context['admin_notes'] = $this->scrubPII($context['admin_notes']);
            }
        }

        // 6. Apply Compliance Mode (PII Scrubber) if requested
        if ($scrubPII) {
            $context['subject'] = $this->scrubPII($context['subject']);
            $context['client_name'] = $this->scrubPII($context['client_name']);
            // Scrub all messages
            if (isset($context['messages']) && is_array($context['messages'])) {
                foreach ($context['messages'] as &$msg) {
                    if (isset($msg['message'])) {
                        $msg['message'] = $this->scrubPII($msg['message']);
                    }
                }
            }
            if (isset($context['attachments_text'])) {
                $context['attachments_text'] = $this->scrubPII($context['attachments_text']);
            }
        }

        return $context;
    }

    /**
     * Hosting lines plus optional account enrichment; optional max length override (e.g. cron cap).
     */
    public function buildServicesSummaryForTicket(\stdClass $ticket, bool $scrubPII, ?int $maxCharsOverride = null): string
    {
        return $this->buildServicesSummaryBlock($ticket, $scrubPII, $maxCharsOverride);
    }

    /**
     * @param \stdClass $ticket Ticket row (needs id, userid)
     * @param int|null $maxCharsOverride When set, caps length after scrub (e.g. cron uses min(800, settings)).
     */
    private function buildServicesSummaryBlock(\stdClass $ticket, bool $scrubPII, ?int $maxCharsOverride = null): string
    {
        $settings = null;
        try {
            $settings = Capsule::table('tblsahdev_settings')->first();
        } catch (\Throwable $e) {
        }

        $userid = (int) $ticket->userid;
        $assocService = isset($ticket->service) ? (string)$ticket->service : '';
        $summary = $this->extractClientServices($userid, $assocService);

        if ($settings && !empty($settings->context_enrichment_enabled) && $userid > 0) {
            try {
                $opts = [
                    'context_enrichment_enabled' => true,
                    'context_enrichment_invoices' => !empty($settings->context_enrichment_invoices),
                    'context_enrichment_domains' => !empty($settings->context_enrichment_domains),
                    'context_enrichment_hosting' => !empty($settings->context_enrichment_hosting),
                    'context_enrichment_addons' => !empty($settings->context_enrichment_addons),
                    'context_enrichment_custom_fields' => !empty($settings->context_enrichment_custom_fields),
                    'context_enrichment_client_notes' => !empty($settings->context_enrichment_client_notes),
                    'context_enrichment_custom_field_allowlist' => $settings->context_enrichment_custom_field_allowlist ?? null,
                ];
                $extra = ClientAccountEnrichment::build($userid, $opts, (int) $ticket->id);
                if ($extra !== '') {
                    $summary = ($summary !== '' ? $summary . "\n\n" : '') . $extra;
                }
            } catch (\Throwable $e) {
                $tid = isset($ticket->id) ? (int) $ticket->id : null;
                ModuleLogger::warning(
                    'TicketDataExtractor.enrichment',
                    substr($e->getMessage(), 0, 500),
                    $tid
                );
            }
        }

        if ($scrubPII) {
            $summary = $this->scrubPII($summary);
        }

        $maxChars = 2500;
        if ($settings !== null && isset($settings->context_enrichment_max_chars)) {
            $maxChars = (int) $settings->context_enrichment_max_chars;
        }
        $maxChars = max(500, min(20000, $maxChars));
        if ($maxCharsOverride !== null) {
            $maxChars = max(300, min(20000, $maxCharsOverride));
        }

        if (strlen($summary) > $maxChars) {
            $summary = substr($summary, 0, $maxChars) . "\n[truncated]";
        }

        return $summary;
    }

    private function extractAdminSignature(): string
    {
        if (!$this->adminId) {
            return '';
        }
        
        $signature = Capsule::table('tbladmins')
            ->where('id', $this->adminId)
            ->value('signature');
            
        // Use basic formatting but strip unsafe tags
        return $signature ? trim(strip_tags($signature, '<br><p><a><b><strong><i><em>')) : '';
    }

    private function extractAdminNotes(int $ticketId): string
    {
        $notes = Capsule::table('tblticketnotes')
            ->where('ticketid', $ticketId)
            ->orderBy('date', 'asc')
            ->get();
            
        if ($notes->isEmpty()) return '';
        
        $output = [];
        foreach ($notes as $note) {
            $output[] = "Note by Admin (" . $note->admin . ") on " . $note->date . ":\n" . strip_tags($note->message);
        }
        return implode("\n\n", $output);
    }

    private function getDepartmentName($did): string
    {
        $dept = Capsule::table('tblticketdepartments')->where('id', $did)->value('name');
        return $dept ?: 'Unknown';
    }

    private function extractClientName($ticket): string
    {
        if ($ticket->userid) {
            $client = Capsule::table('tblclients')->where('id', $ticket->userid)->first();
            if ($client) {
                // Return firstname only for privacy, or full name if necessary internally
                return trim($client->firstname . ' ' . $client->lastname);
            }
        }

        return $ticket->name ?: 'Guest / Unregistered';
    }

    private function extractClientServices($userid, $assocService = ''): string
    {
        if (!$userid) {
            return '';
        }

        $summary = [];

        // Determine if we are filtering by a specific Hosting (S) or Domain (D)
        $targetHostId = 0;
        $targetDomainId = 0;
        
        if ($assocService !== '') {
            if (preg_match('/^S([0-9]+)$/i', $assocService, $match)) {
                $targetHostId = (int)$match[1];
            } elseif (preg_match('/^D([0-9]+)$/i', $assocService, $match)) {
                $targetDomainId = (int)$match[1];
            } elseif (is_numeric($assocService) && rtrim($assocService, '0..9') === '') {
                $targetHostId = (int)$assocService; // Legacy fallback
            }
        }

        // --- FETCH HOSTING SERVICES ---
        if ($targetDomainId === 0) { // Only fetch hosting if it's not strictly a domain selection
            $query = Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
                ->leftJoin('tblservers as s', 'h.server', '=', 's.id')
                ->where('h.userid', $userid)
                ->whereIn('h.domainstatus', ['Active', 'Suspended']);

            if ($targetHostId > 0) {
                $query->where('h.id', $targetHostId);
            }

            $services = $query->select(
                    'p.name as product_name',
                    'h.domain',
                    'h.dedicatedip',
                    'h.assignedips',
                    's.name as server_name',
                    's.ipaddress as server_ip',
                    's.hostname as server_host',
                    's.nameserver1', 's.nameserver2', 's.nameserver3', 's.nameserver4', 's.nameserver5'
                )
                ->orderBy('h.id', 'desc')
                ->get();

            foreach ($services as $service) {
                $ns = [];
                for ($i = 1; $i <= 5; $i++) {
                    $f = 'nameserver' . $i;
                    if (!empty($service->$f)) $ns[] = (string) $service->$f;
                }
                
                $ip = trim((string) $service->dedicatedip) ?: trim((string) $service->assignedips);
                if (!$ip) $ip = (string) $service->server_ip;

                $details = [];
                if (!empty($service->server_name)) $details[] = "Server: {$service->server_name}";
                if (!empty($service->server_host)) $details[] = "Hostname: {$service->server_host}";
                if ($ip) $details[] = "IP: {$ip}";
                if (!empty($ns)) $details[] = "MANDATORY_TARGET_NS: " . implode(', ', $ns);

                $detailStr = !empty($details) ? (" (" . implode(' | ', $details) . ")") : "";
                $summary[] = "- {$service->product_name}: {$service->domain}{$detailStr}";
            }
        }

        // --- FETCH DOMAIN SERVICES ---
        // Exclusively fetch the domain if requested, or if no target is specified (fetch all)
        if ($targetHostId === 0) { 
            try {
                if (Capsule::schema()->hasTable('tbldomains')) {
                    $dQuery = Capsule::table('tbldomains')
                        ->where('userid', $userid)
                        ->whereIn('status', ['Active', 'Suspended']);
                    
                    if ($targetDomainId > 0) {
                        $dQuery->where('id', $targetDomainId);
                    }
                    
                    $domains = $dQuery->select('domain', 'registrar')->get();
                    foreach ($domains as $d) {
                        $registrarStr = !empty($d->registrar) ? " (Registrar: {$d->registrar})" : "";
                        $summary[] = "- Domain: {$d->domain}{$registrarStr}";
                    }
                }
            } catch (\Throwable $e) {}
        }

        if (empty($summary)) {
            return 'No active services relevant to this ticket.';
        }

        return implode("\n", $summary);
    }

    /**
     * Build context for admin-side ticket open page where no ticket thread exists yet.
     */
    public function getOpenContextForUser(int $userId, bool $scrubPII = false, bool $includeAdminNotes = false): array
    {
        if ($userId <= 0) {
            throw new \Exception("Invalid client user id.");
        }

        $client = Capsule::table('tblclients')
            ->select('id', 'firstname', 'lastname', 'companyname', 'email')
            ->where('id', $userId)
            ->first();

        if (!$client) {
            throw new \Exception("Client not found.");
        }

        $fullName = trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''));
        $clientName = $fullName !== '' ? $fullName : ('Client #' . $userId);
        $companyName = trim((string) ($client->companyname ?? ''));

        $openInvoices = Capsule::table('tblinvoices')
            ->where('userid', $userId)
            ->whereIn('status', ['Unpaid', 'Payment Pending', 'Collections'])
            ->count();
        $overdueInvoices = Capsule::table('tblinvoices')
            ->where('userid', $userId)
            ->where('status', 'Unpaid')
            ->where('duedate', '<', date('Y-m-d'))
            ->count();

        $recentInvoices = Capsule::table('tblinvoices')
            ->where('userid', $userId)
            ->orderBy('date', 'desc')
            ->limit(5)
            ->select('id', 'status', 'total', 'date', 'duedate')
            ->get();

        $invoiceLines = [];
        foreach ($recentInvoices as $inv) {
            $invoiceLines[] = "- #{$inv->id} | {$inv->status} | {$inv->total} | Date: {$inv->date} | Due: {$inv->duedate}";
        }

        $servicesSummary = $this->extractClientServices($userId);

        $domainRows = Capsule::table('tbldomains')
            ->where('userid', $userId)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->select('domain', 'status', 'nextduedate')
            ->get();

        $domainSummary = [];
        foreach ($domainRows as $row) {
            $domainSummary[] = "- {$row->domain} ({$row->status}) next due: {$row->nextduedate}";
        }

        $contextText = "=== CLIENT ACCOUNT SNAPSHOT ===\n";
        $contextText .= "Client ID: {$userId}\n";
        $contextText .= "Client Name: {$clientName}\n";
        if ($companyName !== '') {
            $contextText .= "Company: {$companyName}\n";
        }
        $contextText .= "Open/Collection Invoices: {$openInvoices}\n";
        $contextText .= "Overdue Invoices: {$overdueInvoices}\n\n";

        $contextText .= "=== ACTIVE SERVICES ===\n";
        $contextText .= ($servicesSummary !== '' ? $servicesSummary : "No active services.") . "\n\n";

        $contextText .= "=== RECENT INVOICES ===\n";
        $contextText .= (!empty($invoiceLines) ? implode("\n", $invoiceLines) : "No recent invoices found.") . "\n\n";

        $contextText .= "=== RECENT DOMAINS ===\n";
        $contextText .= (!empty($domainSummary) ? implode("\n", $domainSummary) : "No domains found.") . "\n\n";

        $contextText .= "=== ADMIN REQUEST ===\n";
        $contextText .= "Use this account context plus admin instruction to craft a human support reply. There is no existing ticket thread in this mode.\n";

        $context = [
            'subject' => 'Admin Ticket Open Draft Context',
            'priority' => 'Medium',
            'department' => 'General Support',
            'client_name' => $clientName,
            'services_summary' => $servicesSummary,
            'attachments_text' => '',
            'attachments_images' => [],
            'messages' => [[
                'date' => date('Y-m-d H:i:s'),
                'message' => $contextText,
                'admin' => false,
            ]],
            'admin_signature' => $this->extractAdminSignature(),
        ];

        if ($scrubPII) {
            $context['client_name'] = $this->scrubPII($context['client_name']);
            $context['messages'][0]['message'] = $this->scrubPII($context['messages'][0]['message']);
        }

        return $context;
    }

    private function extractMessages($ticket): array
    {
        $messages = [];
        $maxMessageChars = 3000; // cap per message to avoid context overflow on small-context models

        // Let's add the original first
        $body = strip_tags($ticket->message);
        if (strlen($body) > $maxMessageChars) {
            $body = substr($body, 0, $maxMessageChars) . '...[truncated]';
        }
        $messages[] = [
            'date'    => $ticket->date,
            'message' => $body,
            'admin'   => false,
        ];

        // Fetch newest replies first, up to limit
        $replies = Capsule::table('tblticketreplies')
            ->where('tid', $ticket->id)
            ->orderBy('id', 'desc')
            ->limit($this->maxMessages - 1) // Leave room for initial message
            ->get();

        // Reverse so chronological for the prompt
        $replies = $replies->reverse();

        foreach ($replies as $reply) {
            $body = strip_tags($reply->message);
            if (strlen($body) > $maxMessageChars) {
                $body = substr($body, 0, $maxMessageChars) . '...[truncated]';
            }
            $messages[] = [
                'date'    => $reply->date,
                'message' => $body,
                'admin'   => !empty($reply->admin),
            ];
        }

        return $messages;
    }

    private function extractAttachmentText($ticket): string
    {
        // Simple heuristic: Only parse .txt or .log files up to 1MB.
        // For security, do not attempt to parse PDFs/BIN without safe libraries.
        // Fetch attachments associated with ticket
        global $customadminpath;

        $text = "";

        // Original ticket attachments
        $originalAttachments = Capsule::table('tbltickets')->where('id', $ticket->id)->value('attachment');
        $text .= $this->processRawAttachmentString($originalAttachments);

        // Reply attachments
        $repliesAttachments = Capsule::table('tblticketreplies')
            ->where('tid', $ticket->id)
            ->orderBy('id', 'desc')
            ->limit(3) // Only check latest 3 replies for attachments 
            ->pluck('attachment');

        foreach ($repliesAttachments as $attachmentString) {
            $text .= $this->processRawAttachmentString($attachmentString);
        }

        return trim($text);
    }

    private function processRawAttachmentString($attachmentString)
    {
        if (empty(trim($attachmentString)))
            return "";

        $text = "";
        $files = explode('|', $attachmentString);

        // Grab standard WHMCS attachments directory
        global $attachments_dir;
        
        $whmcsAttachmentsDir = '';

        // 1. Check Custom Override from Settings First
        $customDir = \WHMCS\Database\Capsule::table('tblsahdev_settings')->value('custom_attachments_dir');
        if (!empty(trim($customDir ?? ''))) {
            $whmcsAttachmentsDir = rtrim(trim($customDir), '/\\');
        }

        // 2. Check Global Variable
        if (empty($whmcsAttachmentsDir)) {
            $whmcsAttachmentsDir = $attachments_dir ?? '';
        }

        // 3. Check configuration.php
        if (empty($whmcsAttachmentsDir)) {
            $possibleConfig = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'configuration.php';
            if (file_exists($possibleConfig)) {
                include $possibleConfig;
                $whmcsAttachmentsDir = $attachments_dir ?? '';
            }
        }

        // 4. Check tblconfiguration
        if (empty($whmcsAttachmentsDir)) {
            $whmcsAttachmentsDir = \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'Attachments_Dir')->value('value');
        }

        // 5. Fallback generic path
        if (empty($whmcsAttachmentsDir)) {
            $whmcsAttachmentsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'attachments';
        }

        foreach ($files as $file) {
            if (empty($file))
                continue;

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, ['txt', 'log', 'csv', 'json', 'xml', 'yml', 'yaml'])) {
                $filePath = $this->resolveSafeAttachmentPath($whmcsAttachmentsDir, $file);

                if ($filePath && file_exists($filePath) && filesize($filePath) < $this->maxAttachmentSize) {
                    $content = @file_get_contents($filePath);
                    if ($content !== false) {
                        // Truncate if massive (safety net beyond filesize)
                        if (strlen($content) > $this->maxAttachmentChars) {
                            $content = substr($content, 0, $this->maxAttachmentChars) . "...(truncated)";
                        }
                        $text .= "Attachment [{$file}]:\n" . htmlentities($content) . "\n\n";
                    }
                }
            }
        }

        return $text;
    }

    private function extractImageAttachments($ticket): array
    {
        $images = [];
        global $attachments_dir;
        
        // Try multiple ways to get the WHMCS attachments dir
        $whmcsAttachmentsDir = '';

        // 1. Check Custom Override from Settings First
        $customDir = \WHMCS\Database\Capsule::table('tblsahdev_settings')->value('custom_attachments_dir');
        if (!empty(trim($customDir ?? ''))) {
            $whmcsAttachmentsDir = rtrim(trim($customDir), '/\\');
        }

        // 2. Check Global Variable
        if (empty($whmcsAttachmentsDir)) {
            $whmcsAttachmentsDir = $attachments_dir ?? '';
        }

        // 3. Check configuration.php
        if (empty($whmcsAttachmentsDir)) {
            $possibleConfig = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'configuration.php';
            if (file_exists($possibleConfig)) {
                include $possibleConfig;
                $whmcsAttachmentsDir = $attachments_dir ?? '';
            }
        }

        // 4. Check tblconfiguration
        if (empty($whmcsAttachmentsDir)) {
            $whmcsAttachmentsDir = \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'Attachments_Dir')->value('value');
        }

        // 5. Fallback generic path
        if (empty($whmcsAttachmentsDir)) {
            $whmcsAttachmentsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'attachments';
        }

        $allAttachments = [];
        
        $originalAttachments = Capsule::table('tbltickets')->where('id', $ticket->id)->value('attachment');
        if (!empty(trim($originalAttachments ?? ''))) {
            $allAttachments = array_merge($allAttachments, explode('|', $originalAttachments));
        }

        $repliesAttachments = Capsule::table('tblticketreplies')
            ->where('tid', $ticket->id)
            ->orderBy('id', 'desc')
            ->limit(3)
            ->pluck('attachment');

        foreach ($repliesAttachments as $attachmentString) {
            if (!empty(trim($attachmentString ?? ''))) {
                $allAttachments = array_merge($allAttachments, explode('|', $attachmentString));
            }
        }

        foreach ($allAttachments as $file) {
            if (empty($file) || count($images) >= $this->maxImages) {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                $filePath = $this->resolveSafeAttachmentPath($whmcsAttachmentsDir, $file);
                
                // Track missing files by appending info to context if we want, but instead let's just make sure path is right
                if ($filePath && file_exists($filePath) && filesize($filePath) < 5242880) { // 5MB limit for images
                    $content = @file_get_contents($filePath);
                    if ($content !== false) {
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->buffer($content);
                        if ($mimeType) {
                            $base64 = base64_encode($content);
                            $images[] = [
                                'url' => "data:{$mimeType};base64,{$base64}",
                                'source' => 'attachment: ' . $file
                            ];
                        }
                    }
                }
            }
        }

        return $images;
    }

    private function extractImageUrls($ticket): array
    {
        $images = [];
        $messagesText = $ticket->message . " ";
        
        $replies = Capsule::table('tblticketreplies')
            ->where('tid', $ticket->id)
            ->orderBy('id', 'desc')
            ->limit($this->maxMessages - 1)
            ->pluck('message');
            
        foreach ($replies as $reply) {
            $messagesText .= $reply . " ";
        }

        // Extract direct image URLs
        if (preg_match_all('/https?:\/\/[^\s"\'<>]+?\.(?:png|jpg|jpeg|gif|webp)(?:\?[^\s"\'<>]+)?/i', $messagesText, $matches)) {
            foreach (array_unique($matches[0]) as $url) {
                if (count($images) >= $this->maxImages) break;
                
                $imgData = $this->fetchUrlAsBase64($url);
                if ($imgData) {
                    $images[] = [
                        'url' => $imgData,
                        'source' => 'url: ' . $url
                    ];
                }
            }
        }

        // Extract prnt.sc links
        if (preg_match_all('/https?:\/\/prnt\.sc\/[a-zA-Z0-9_-]+/i', $messagesText, $matches)) {
            foreach (array_unique($matches[0]) as $prntScUrl) {
                if (count($images) >= $this->maxImages) break;
                
                if (!$this->isSafeRemoteUrl($prntScUrl)) {
                    continue;
                }
                $ctx = stream_context_create([
                    'http' => ['timeout' => 8, 'follow_location' => 0],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $html = @file_get_contents($prntScUrl, false, $ctx);
                if ($html && preg_match('/<img[^>]+(?:id="screenshot-image"|class="[^"]*screenshot-image[^"]*")[^>]+src="([^"]+)"/i', $html, $imgMatches)) {
                    $actualUrl = $imgMatches[1];
                    // Sometimes prnt.sc image URLs are relative to their own CDN or absolute imgur
                    if (strpos($actualUrl, 'http') !== 0 && strpos($actualUrl, '//') === 0) {
                        $actualUrl = 'https:' . $actualUrl;
                    } elseif (strpos($actualUrl, 'http') !== 0) {
                        continue; // Skip relative generic links not starting with //
                    }
                    $imgData = $this->fetchUrlAsBase64($actualUrl);
                    if ($imgData) {
                        $images[] = [
                            'url' => $imgData,
                            'source' => 'prnt.sc: ' . $prntScUrl
                        ];
                    }
                }
            }
        }

        return $images;
    }

    private function fetchUrlAsBase64(string $url): ?string
    {
        if (!$this->isSafeRemoteUrl($url)) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Pretend to be a browser to prevent 403 blocks from CDNs
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        
        $content = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content !== false && $httpCode === 200 && strpos($contentType, 'image/') !== false) {
            $base64 = base64_encode($content);
            return "data:{$contentType};base64,{$base64}";
        }
        
        return null;
    }

    private function resolveSafeAttachmentPath(string $baseDir, string $fileName): ?string
    {
        $fileName = trim($fileName);
        if ($fileName === '' || strpos($fileName, '..') !== false || preg_match('/[\\\\\\/]/', $fileName)) {
            return null;
        }
        $base = realpath(rtrim($baseDir, '/\\'));
        if ($base === false) {
            return null;
        }
        $candidate = $base . DIRECTORY_SEPARATOR . $fileName;
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return null;
        }
        return strpos($resolved, $base . DIRECTORY_SEPARATOR) === 0 ? $resolved : null;
    }

    private function isSafeRemoteUrl(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }
        $ip = gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        return true;
    }

    /**
     * Compliance Mode PII Scrubber
     * Redacts emails, credit cards, IPs, and common password patterns.
     */
    private function scrubPII(string $text): string
    {
        if (empty($text)) return $text;

        $settings = Capsule::table('tblsahdev_settings')->first();
        if (!$settings) return $text;

        // 1. Scrub Credit Cards (basic 13-16 digit matching)
        if (!isset($settings->scrub_cc) || !empty($settings->scrub_cc)) {
            $text = preg_replace('/\b(?:\d[ -]*?){13,16}\b/', '[REDACTED_CC]', $text);
        }

        // 2. Scrub Emails
        if (!isset($settings->scrub_emails) || !empty($settings->scrub_emails)) {
            $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[REDACTED_EMAIL]', $text);
        }

        // 2b. Phone numbers (heuristic; may false-positive on long numeric IDs — disable via scrub_phones)
        if (!isset($settings->scrub_phones) || !empty($settings->scrub_phones)) {
            // E.164-style: + then 8–15 digits (first digit after + not 0)
            $text = preg_replace('/\+[1-9]\d{7,14}(?=\D|\z)/', '[REDACTED_PHONE]', $text);
            // US/CA style: optional +1, area code, 7 more digits with common separators
            $text = preg_replace(
                '/(?<!\d)(?:\+?1[-.\s]{0,2})?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}(?!\d)/',
                '[REDACTED_PHONE]',
                $text
            );
        }

        // 3. Scrub IPv4 Addresses (naive but effective for logs)
        if (!isset($settings->scrub_ips) || !empty($settings->scrub_ips)) {
            $text = preg_replace('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', '[REDACTED_IP]', $text);
        }

        // 4. Scrub passwords (heuristic: "password: xxx", "pass: xxx")
        if (!isset($settings->scrub_passwords) || !empty($settings->scrub_passwords)) {
            $text = preg_replace('/(?i)(?:password|pass|pwd)\s*[:=]\s*([^\s\n\r]+)/', '$0 [REDACTED_PASSWORD]', $text);
        }

        return $text;
    }
}
