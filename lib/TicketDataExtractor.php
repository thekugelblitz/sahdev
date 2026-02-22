<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

class TicketDataExtractor
{
    private $adminId;

    // Limits to prevent massive memory usage
    private $maxMessages = 10;
    private $maxAttachmentSize = 1048576; // 1 MB (extracted text threshold)

    public function __construct(int $ticketId, int $adminId = null)
    {
        $this->ticketId = $ticketId;
        $this->adminId = $adminId;
    }

    /**
     * Extracts full context securely for a specific ticket.
     * Uses query builder entirely (no raw SQL).
     *
     * @return array
     * @throws \Exception
     */
    public function getContext(): array
    {
        $context = [];

        // 1. Fetch main ticket data
        $ticket = Capsule::table('tbltickets')
            ->select('id', 'tid', 'did', 'userid', 'contactid', 'name', 'email', 'title', 'message', 'status', 'urgency', 'lastreply', 'date')
            ->where('id', $this->ticketId)
            ->first();

        if (!$ticket) {
            throw new \Exception("Ticket not found.");
        }

        $context['subject'] = $ticket->title;
        $context['priority'] = $ticket->urgency;
        $context['department'] = $this->getDepartmentName($ticket->did);

        // 2. Fetch Client Info
        $context['client_name'] = $this->extractClientName($ticket);
        $context['services_summary'] = $this->extractClientServices($ticket->userid);

        // 3. Fetch Message History (Replies + Original Message)
        $context['messages'] = $this->extractMessages($ticket);

        // 4. Extract simple text from attachments (if any and if safe)
        $context['attachments_text'] = $this->extractAttachmentText($ticket);

        // 5. Extract active admin's signature
        $context['admin_signature'] = $this->extractAdminSignature();

        return $context;
    }

    private function extractAdminSignature(): string
    {
        if (!$this->adminId) {
            return '';
        }
        
        $signature = Capsule::table('tbladmins')
            ->where('id', $this->adminId)
            ->value('signature');
            
        return $signature ? trim(strip_tags($signature, '<br><p><a><b><strong><i><em>')) : '';
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

    private function extractClientServices($userid): string
    {
        if (!$userid)
            return '';

        // Get active hosting services linked to this user
        $services = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.userid', $userid)
            ->where('tblhosting.domainstatus', 'Active')
            ->select('tblproducts.name', 'tblhosting.domain', 'tblhosting.server')
            ->limit(5)
            ->get();

        if ($services->isEmpty())
            return 'No active services.';

        $summary = [];
        foreach ($services as $service) {
            $serverName = '';
            if ($service->server) {
                $server = Capsule::table('tblservers')->where('id', $service->server)->value('name');
                if ($server) {
                    $serverName = " (Server: $server)";
                }
            }
            $summary[] = "- {$service->name}: {$service->domain}{$serverName}";
        }

        return implode("\n", $summary);
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
        $whmcsAttachmentsDir = $attachments_dir ?? '';

        if (empty($whmcsAttachmentsDir)) {
            $whmcsAttachmentsDir = \WHMCS\Database\Capsule::table('tblconfiguration')->where('setting', 'Attachments_Dir')->value('value');
        }

        foreach ($files as $file) {
            if (empty($file))
                continue;

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, ['txt', 'log', 'csv', 'json'])) {
                $filePath = $whmcsAttachmentsDir . DIRECTORY_SEPARATOR . $file;

                if (file_exists($filePath) && filesize($filePath) < $this->maxAttachmentSize) {
                    $content = @file_get_contents($filePath);
                    if ($content !== false) {
                        // Truncate if massive (safety net beyond filesize)
                        $content = substr($content, 0, 5000) . "...(truncated)";
                        $text .= "Attachment [{$file}]:\n" . htmlentities($content) . "\n\n";
                    }
                }
            }
        }

        return $text;
    }
}
