<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/SchemaManager.php';
require_once __DIR__ . '/ChatService.php';
require_once __DIR__ . '/ModuleLogger.php';

/**
 * MobileApiService
 *
 * Dedicated backend service powering the Sahdev Mobile Support App (Android/Flutter).
 * Supports token authentication, one-tap QR pairing, queue polling, live typing sneak peeks,
 * AI Co-Pilot drafting, and WHMCS client context cards.
 */
class MobileApiService
{
    /**
     * Authenticate WHMCS Admin credentials and return a secure Bearer token.
     */
    public static function authenticate(string $username, string $password, ?string $deviceName = 'Android Staff Phone'): array
    {
        SchemaManager::ensureMobileTokensTable();

        $username = trim($username);
        if (empty($username) || empty($password)) {
            return ['status' => 'error', 'message' => 'Username and password are required.'];
        }

        $admin = Capsule::table('tbladmins')
            ->where('username', $username)
            ->first();

        if (!$admin) {
            return ['status' => 'error', 'message' => 'Invalid admin username or password.'];
        }

        if (!empty($admin->disabled)) {
            return ['status' => 'error', 'message' => 'This administrator account is disabled.'];
        }

        // Verify password using standard password_verify (WHMCS stores bcrypt/argon2 hashes)
        $passwordValid = false;
        if (password_verify($password, $admin->password)) {
            $passwordValid = true;
        } elseif (class_exists('\WHMCS\Auth\Admin') && method_exists('\WHMCS\Auth\Admin', 'verifyPassword')) {
            try {
                $passwordValid = \WHMCS\Auth\Admin::verifyPassword($password, $admin->password);
            } catch (\Throwable $e) {
                $passwordValid = false;
            }
        }

        if (!$passwordValid) {
            return ['status' => 'error', 'message' => 'Invalid admin username or password.'];
        }

        // Generate 64-character cryptographically secure token
        $token = 'sdvm_' . bin2hex(random_bytes(30));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        Capsule::table('tblsahdev_mobile_tokens')->insert([
            'admin_id'       => (int) $admin->id,
            'token'          => $token,
            'device_name'    => $deviceName ?: 'Android Staff Device',
            'last_ip'        => $ip,
            'last_active_at' => Carbon::now(),
            'created_at'     => Carbon::now(),
            'updated_at'     => Carbon::now(),
        ]);

        return [
            'status' => 'success',
            'token'  => $token,
            'admin'  => [
                'id'       => (int) $admin->id,
                'username' => $admin->username,
                'name'     => trim($admin->firstname . ' ' . $admin->lastname),
                'email'    => $admin->email,
            ],
        ];
    }

    /**
     * Generate a short-lived QR pairing token for 1-tap desktop console linking.
     */
    public static function generateQrPairingToken(int $adminId): array
    {
        SchemaManager::ensureMobileTokensTable();

        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first(['id', 'username', 'firstname', 'lastname']);
        if (!$admin) {
            return ['status' => 'error', 'message' => 'Admin not found.'];
        }

        $pairingCode = 'sdv_pair_' . bin2hex(random_bytes(16));
        $expiresAt = Carbon::now()->addMinutes(10);
        $tempToken = 'temp_' . bin2hex(random_bytes(24));

        $id = Capsule::table('tblsahdev_mobile_tokens')->insertGetId([
            'admin_id'        => $adminId,
            'token'           => $tempToken,
            'qr_pairing_code' => $pairingCode,
            'qr_expires_at'   => $expiresAt,
            'device_name'     => 'Pending QR Scan',
            'created_at'      => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ]);

        // Discover base URL for WHMCS
        $whmcsUrl = rtrim((string) Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value'), '/');
        if (empty($whmcsUrl)) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $whmcsUrl = $proto . $host;
        }

        $pairingPayload = [
            'type'        => 'sahdev_mobile_pair',
            'url'         => $whmcsUrl,
            'code'        => $pairingCode,
            'admin_name'  => trim($admin->firstname . ' ' . $admin->lastname),
            'expires_at'  => $expiresAt->toIso8601String(),
        ];

        return [
            'status'          => 'success',
            'token_id'        => (int) $id,
            'pairing_code'    => $pairingCode,
            'pairing_payload' => json_encode($pairingPayload),
            'expires_in_secs' => 600,
        ];
    }

    /**
     * Verify QR pairing code scanned by the mobile phone and exchange for permanent token.
     */
    public static function verifyQrPairingToken(string $pairingCode, ?string $deviceName = 'Android Staff Device'): array
    {
        SchemaManager::ensureMobileTokensTable();

        $pairingCode = trim($pairingCode);
        if (empty($pairingCode)) {
            return ['status' => 'error', 'message' => 'Pairing code is required.'];
        }

        $row = Capsule::table('tblsahdev_mobile_tokens')
            ->where('qr_pairing_code', $pairingCode)
            ->where('is_revoked', 0)
            ->first();

        if (!$row) {
            return ['status' => 'error', 'message' => 'Invalid pairing code.'];
        }

        if ($row->qr_expires_at && Carbon::parse($row->qr_expires_at)->isPast()) {
            return ['status' => 'error', 'message' => 'Pairing code has expired. Please refresh the QR code on your computer.'];
        }

        $admin = Capsule::table('tbladmins')->where('id', $row->admin_id)->first();
        if (!$admin || !empty($admin->disabled)) {
            return ['status' => 'error', 'message' => 'Administrator account not authorized.'];
        }

        $permanentToken = 'sdvm_' . bin2hex(random_bytes(30));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        Capsule::table('tblsahdev_mobile_tokens')
            ->where('id', $row->id)
            ->update([
                'token'           => $permanentToken,
                'qr_pairing_code' => null,
                'qr_expires_at'   => null,
                'device_name'     => $deviceName ?: 'Android Staff Device',
                'last_ip'         => $ip,
                'last_active_at'  => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ]);

        return [
            'status' => 'success',
            'token'  => $permanentToken,
            'admin'  => [
                'id'       => (int) $admin->id,
                'username' => $admin->username,
                'name'     => trim($admin->firstname . ' ' . $admin->lastname),
                'email'    => $admin->email,
            ],
        ];
    }

    /**
     * Validate an incoming Bearer or request token and return the authenticated admin.
     */
    public static function validateToken(?string $token): ?object
    {
        if (empty($token)) {
            return null;
        }

        SchemaManager::ensureMobileTokensTable();

        $tokenRow = Capsule::table('tblsahdev_mobile_tokens')
            ->where('token', $token)
            ->where('is_revoked', 0)
            ->first();

        if (!$tokenRow) {
            return null;
        }

        if (!empty($tokenRow->expires_at) && Carbon::parse($tokenRow->expires_at)->isPast()) {
            return null;
        }

        $admin = Capsule::table('tbladmins')
            ->where('id', $tokenRow->admin_id)
            ->where('disabled', 0)
            ->first();

        if (!$admin) {
            return null;
        }

        // Touch last_active timestamp
        Capsule::table('tblsahdev_mobile_tokens')
            ->where('id', $tokenRow->id)
            ->update([
                'last_active_at' => Carbon::now(),
                'last_ip'        => $_SERVER['REMOTE_ADDR'] ?? $tokenRow->last_ip,
            ]);

        $admin->mobile_token_id = $tokenRow->id;
        $admin->device_name = $tokenRow->device_name;
        return $admin;
    }

    /**
     * Poll the visitor live chat queue with urgent summon detection and sneak-peek keystrokes.
     */
    public static function pollQueue(int $adminId, string $filter = 'all', int $afterMessageId = 0): array
    {
        ChatService::ensureUtf8mb4Connection();
        ChatService::checkTakeoverTimeouts();
        ChatService::adminHeartbeat($adminId);

        // Calculate urgent summons
        $urgentSummonsCount = Capsule::table('tblsahdev_chat_sessions')
            ->where('session_type', 'client_livechat')
            ->where('summon_status', 'requested')
            ->whereNotIn('status', ['closed', 'escalated_ticket'])
            ->count();

        // Check if there is an active summon within the last 5 minutes that needs an alert sound
        $activeSummonAlert = Capsule::table('tblsahdev_chat_sessions')
            ->where('session_type', 'client_livechat')
            ->where('summon_status', 'requested')
            ->whereNotIn('status', ['closed', 'escalated_ticket'])
            ->where('last_message_at', '>=', Carbon::now()->subMinutes(5))
            ->exists();

        $q = Capsule::table('tblsahdev_chat_sessions')
            ->where('session_type', 'client_livechat');

        if ($filter === 'summoned') {
            $q->where('summon_status', 'requested')
              ->whereNotIn('status', ['closed', 'escalated_ticket']);
        } elseif ($filter === 'active') {
            $q->where('status', 'active');
        } elseif ($filter === 'taken_over') {
            $q->where('status', 'taken_over');
        } elseif ($filter === 'my_chats') {
            $q->where('assigned_admin_id', $adminId)
              ->whereNotIn('status', ['closed', 'escalated_ticket']);
        } elseif ($filter === 'closed') {
            $q->whereIn('status', ['closed', 'escalated_ticket']);
        } else {
            // 'all': Show open chats
            $q->whereNotIn('status', ['closed', 'escalated_ticket']);
        }

        $sessions = $q->orderByRaw("CASE WHEN summon_status = 'requested' THEN 0 WHEN status = 'taken_over' THEN 1 ELSE 2 END")
            ->orderBy('last_message_at', 'desc')
            ->limit(50)
            ->get();

        $now = Carbon::now();
        $sessionList = [];

        foreach ($sessions as $s) {
            $clientName = 'Guest Visitor';
            $clientEmail = '';
            $isWhmcsClient = ($s->client_id > 0);

            if ($isWhmcsClient) {
                $cl = Capsule::table('tblclients')->where('id', $s->client_id)->first(['firstname', 'lastname', 'email']);
                if ($cl) {
                    $clientName = trim($cl->firstname . ' ' . $cl->lastname);
                    $clientEmail = $cl->email;
                }
            } else {
                $meta = json_decode($s->metadata_json ?? '', true) ?: [];
                if (!empty($meta['name'])) $clientName = $meta['name'];
                if (!empty($meta['email'])) $clientEmail = $meta['email'];
            }

            // Typing preview sneak-peek
            $isTyping = false;
            $typingPreview = $s->typing_preview ?? '';
            if (!empty($s->typing_at)) {
                $typingAt = Carbon::parse($s->typing_at);
                if ($typingAt->diffInSeconds($now) <= 7 && !empty($typingPreview)) {
                    $isTyping = true;
                }
            }

            // Last message
            $lastMsg = Capsule::table('tblsahdev_chat_messages')
                ->where('session_id', $s->id)
                ->orderBy('id', 'desc')
                ->first(['id', 'sender_type', 'sender_name', 'message_text', 'created_at']);

            $unreadCount = 0;
            if ($lastMsg && $lastMsg->sender_type === 'client') {
                $unreadCount = Capsule::table('tblsahdev_chat_messages')
                    ->where('session_id', $s->id)
                    ->where('sender_type', 'client')
                    ->where('created_at', '>=', Carbon::parse($s->updated_at)->subMinutes(15))
                    ->count();
            }

            $sessionList[] = [
                'id'                => (int) $s->id,
                'uuid'              => $s->session_uuid,
                'title'             => $s->title ?: ('Chat with ' . $clientName),
                'status'            => $s->status,
                'summon_status'     => $s->summon_status,
                'assigned_admin_id' => (int) $s->assigned_admin_id,
                'is_my_chat'        => ((int) $s->assigned_admin_id === $adminId),
                'client'            => [
                    'id'            => (int) $s->client_id,
                    'name'          => $clientName,
                    'email'         => $clientEmail,
                    'is_registered' => $isWhmcsClient,
                ],
                'source'            => [
                    'domain'        => $s->source_domain ?: 'WHMCS',
                    'page'          => $s->source_page ?: '/',
                    'ip'            => $s->ip_address,
                ],
                'typing'            => [
                    'is_typing'     => $isTyping,
                    'preview'       => $isTyping ? $typingPreview : '',
                ],
                'last_message'      => $lastMsg ? [
                    'id'          => (int) $lastMsg->id,
                    'sender_type' => $lastMsg->sender_type,
                    'sender_name' => $lastMsg->sender_name,
                    'text'        => ChatService::safeDisplayText($lastMsg->message_text),
                    'created_at'  => $lastMsg->created_at,
                    'time_ago'    => Carbon::parse($lastMsg->created_at)->diffForHumans(),
                ] : null,
                'unread_count'      => max(0, $unreadCount),
                'last_message_at'   => $s->last_message_at,
                'created_at'        => $s->created_at,
            ];
        }

        return [
            'status'               => 'success',
            'sessions'             => $sessionList,
            'urgent_summons_count' => $urgentSummonsCount,
            'should_alert'         => $activeSummonAlert,
            'polled_at'            => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Fetch formatted chat messages for a specific session.
     */
    public static function getChatHistory(int $sessionId, int $limit = 60): array
    {
        ChatService::ensureUtf8mb4Connection();

        $session = Capsule::table('tblsahdev_chat_sessions')
            ->where('id', $sessionId)
            ->first();

        if (!$session) {
            return ['status' => 'error', 'message' => 'Chat session not found.'];
        }

        $rawMessages = Capsule::table('tblsahdev_chat_messages')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        $messages = [];
        foreach ($rawMessages as $m) {
            $isStaff = ($m->sender_type === 'admin' || $m->sender_type === 'staff');
            $isAi = ($m->sender_type === 'ai' || $m->sender_type === 'assistant');
            $isClient = ($m->sender_type === 'client' || $m->sender_type === 'user');
            $isSystem = ($m->sender_type === 'system' || $m->sender_type === 'event');

            $messages[] = [
                'id'          => (int) $m->id,
                'session_id'  => (int) $m->session_id,
                'sender_type' => $m->sender_type,
                'sender_name' => $m->sender_name,
                'text'        => ChatService::safeDisplayText($m->message_text),
                'is_staff'    => $isStaff,
                'is_ai'       => $isAi,
                'is_client'   => $isClient,
                'is_system'   => $isSystem,
                'created_at'  => $m->created_at,
                'time_format' => Carbon::parse($m->created_at)->format('g:i A'),
            ];
        }

        // Live typing sneak-peek state
        $isTyping = false;
        $typingPreview = $session->typing_preview ?? '';
        if (!empty($session->typing_at)) {
            if (Carbon::parse($session->typing_at)->diffInSeconds(Carbon::now()) <= 7 && !empty($typingPreview)) {
                $isTyping = true;
            }
        }

        return [
            'status'         => 'success',
            'session_id'     => (int) $session->id,
            'session_uuid'   => $session->session_uuid,
            'session_status' => $session->status,
            'summon_status'  => $session->summon_status,
            'assigned_admin' => (int) $session->assigned_admin_id,
            'typing'         => [
                'is_typing' => $isTyping,
                'preview'   => $isTyping ? $typingPreview : '',
            ],
            'messages'       => $messages,
        ];
    }

    /**
     * Send message as human staff member and mark session as taken over.
     */
    public static function sendMessage(int $sessionId, int $adminId, string $text): array
    {
        ChatService::ensureUtf8mb4Connection();

        $text = trim($text);
        if (empty($text)) {
            return ['status' => 'error', 'message' => 'Message text cannot be empty.'];
        }

        $session = Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->first();
        if (!$session) {
            return ['status' => 'error', 'message' => 'Session not found.'];
        }

        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first(['firstname', 'lastname']);
        $adminName = $admin ? trim($admin->firstname . ' ' . $admin->lastname) : 'Support Staff';

        $safeText = ChatService::safeStorageText($text);

        $msgId = Capsule::table('tblsahdev_chat_messages')->insertGetId([
            'session_id'   => $sessionId,
            'sender_type'  => 'admin',
            'sender_name'  => $adminName,
            'message_text' => $safeText,
            'created_at'   => Carbon::now(),
            'updated_at'   => Carbon::now(),
        ]);

        // Automatically set session to taken_over and assign to this admin if not already
        Capsule::table('tblsahdev_chat_sessions')
            ->where('id', $sessionId)
            ->update([
                'status'            => 'taken_over',
                'summon_status'     => 'handled',
                'assigned_admin_id' => $adminId,
                'typing_preview'    => null,
                'last_message_at'   => Carbon::now(),
                'updated_at'        => Carbon::now(),
            ]);

        return [
            'status'  => 'success',
            'message' => [
                'id'          => $msgId,
                'session_id'  => $sessionId,
                'sender_type' => 'admin',
                'sender_name' => $adminName,
                'text'        => $text,
                'is_staff'    => true,
                'created_at'  => Carbon::now()->toIso8601String(),
                'time_format' => Carbon::now()->format('g:i A'),
            ],
        ];
    }

    /**
     * Take over conversation from AI, or release back to AI autopilot.
     */
    public static function takeoverSession(int $sessionId, int $adminId, bool $takeover): array
    {
        ChatService::ensureUtf8mb4Connection();

        $session = Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->first();
        if (!$session) {
            return ['status' => 'error', 'message' => 'Session not found.'];
        }

        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first(['firstname', 'lastname']);
        $adminName = $admin ? trim($admin->firstname . ' ' . $admin->lastname) : 'Support Staff';

        if ($takeover) {
            Capsule::table('tblsahdev_chat_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status'            => 'taken_over',
                    'summon_status'     => 'handled',
                    'assigned_admin_id' => $adminId,
                    'updated_at'        => Carbon::now(),
                ]);

            Capsule::table('tblsahdev_chat_messages')->insert([
                'session_id'   => $sessionId,
                'sender_type'  => 'system',
                'sender_name'  => 'System',
                'message_text' => "{$adminName} has joined the conversation and taken over support.",
                'created_at'   => Carbon::now(),
                'updated_at'   => Carbon::now(),
            ]);
        } else {
            Capsule::table('tblsahdev_chat_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status'            => 'active',
                    'summon_status'     => 'none',
                    'assigned_admin_id' => 0,
                    'updated_at'        => Carbon::now(),
                ]);

            Capsule::table('tblsahdev_chat_messages')->insert([
                'session_id'   => $sessionId,
                'sender_type'  => 'system',
                'sender_name'  => 'System',
                'message_text' => "Conversation released back to Autonomous AI Assistant.",
                'created_at'   => Carbon::now(),
                'updated_at'   => Carbon::now(),
            ]);
        }

        return [
            'status'         => 'success',
            'session_status' => $takeover ? 'taken_over' : 'active',
            'is_taken_over'  => $takeover,
        ];
    }

    /**
     * Generate an AI Co-Pilot reply suggestion for staff to review and send with 1 tap.
     */
    public static function generateAiSuggestion(int $sessionId): array
    {
        ChatService::ensureUtf8mb4Connection();

        $session = Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->first();
        if (!$session) {
            return ['status' => 'error', 'message' => 'Session not found.'];
        }

        // Fetch recent messages for context
        $messages = Capsule::table('tblsahdev_chat_messages')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            return ['status' => 'error', 'message' => 'No messages in conversation to analyze.'];
        }

        $conversationText = "";
        foreach ($messages as $m) {
            $conversationText .= "{$m->sender_type} ({$m->sender_name}): {$m->message_text}\n";
        }

        $prompt = "You are Sahdev Support Co-Pilot assisting a human staff agent. Review the following live chat conversation with a web hosting client:\n\n"
            . $conversationText . "\n\n"
            . "Draft a professional, helpful, and concise reply that the staff agent can send immediately to resolve the customer's query. Return ONLY the suggested reply text without quotes or meta commentary.";

        try {
            require_once __DIR__ . '/GoogleAIProvider.php';
            require_once __DIR__ . '/OpenRouterAIProvider.php';

            $settings = Capsule::table('tblsahdev_settings')->first();
            $providerType = $settings->ai_provider ?? 'gemini';

            $reply = "";
            if ($providerType === 'openrouter') {
                $provider = new OpenRouterAIProvider();
                $reply = $provider->generateText($prompt);
            } else {
                $provider = new GoogleAIProvider();
                $reply = $provider->generateText($prompt);
            }

            $cleanReply = trim(str_replace(['"', '`'], '', $reply));
            if (empty($cleanReply)) {
                $cleanReply = "Hello! I am reviewing your request and will have an update for you in just a moment.";
            }

            return [
                'status'     => 'success',
                'suggestion' => $cleanReply,
            ];
        } catch (\Throwable $e) {
            return [
                'status'     => 'success',
                'suggestion' => "Thank you for holding. I've checked the details on your account and am resolving this for you now.",
            ];
        }
    }

    /**
     * Get WHMCS client profile details, hosting services, unpaid invoices, and open tickets.
     */
    public static function getClientDetails(int $clientId, ?int $sessionId = null): array
    {
        if ($clientId <= 0 && $sessionId > 0) {
            $session = Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->first();
            if ($session) {
                $clientId = (int) $session->client_id;
            }
        }

        if ($clientId > 0) {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if (!$client) {
                return ['status' => 'error', 'message' => 'Client not found.'];
            }

            // Active services
            $services = Capsule::table('tblhosting')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.userid', $clientId)
                ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
                ->select([
                    'tblhosting.id',
                    'tblhosting.domain',
                    'tblhosting.domainstatus',
                    'tblhosting.billingcycle',
                    'tblhosting.nextduedate',
                    'tblproducts.name as product_name',
                ])
                ->limit(10)
                ->get();

            // Unpaid invoices
            $unpaidInvoices = Capsule::table('tblinvoices')
                ->where('userid', $clientId)
                ->where('status', 'Unpaid')
                ->select(['id', 'invoicenum', 'total', 'duedate'])
                ->get();

            $unpaidTotal = $unpaidInvoices->sum('total');

            // Open tickets count
            $openTicketsCount = Capsule::table('tbltickets')
                ->where('userid', $clientId)
                ->whereIn('status', ['Open', 'Customer-Reply', 'In Progress'])
                ->count();

            return [
                'status'         => 'success',
                'is_registered'  => true,
                'client'         => [
                    'id'         => (int) $client->id,
                    'name'       => trim($client->firstname . ' ' . $client->lastname),
                    'company'    => $client->companyname ?: 'Individual',
                    'email'      => $client->email,
                    'status'     => $client->status,
                    'created_at' => Carbon::parse($client->datecreated)->format('M d, Y'),
                ],
                'summary'        => [
                    'services_count'     => $services->count(),
                    'unpaid_invoices'    => $unpaidInvoices->count(),
                    'unpaid_total'       => number_format((float) $unpaidTotal, 2),
                    'open_tickets_count' => $openTicketsCount,
                ],
                'services'       => $services,
                'invoices'       => $unpaidInvoices,
            ];
        }

        // Guest visitor profile from session metadata
        $meta = [];
        $domain = 'WHMCS';
        $page = '/';
        $ip = '';
        if ($sessionId > 0) {
            $session = Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->first();
            if ($session) {
                $meta = json_decode($session->metadata_json ?? '', true) ?: [];
                $domain = $session->source_domain ?: 'WHMCS';
                $page = $session->source_page ?: '/';
                $ip = $session->ip_address ?: '';
            }
        }

        return [
            'status'        => 'success',
            'is_registered' => false,
            'client'        => [
                'id'         => 0,
                'name'       => $meta['name'] ?? 'Guest Visitor',
                'company'    => 'Website Visitor',
                'email'      => $meta['email'] ?? 'Not provided',
                'status'     => 'Guest',
                'created_at' => 'Today',
            ],
            'summary'       => [
                'services_count'     => 0,
                'unpaid_invoices'    => 0,
                'unpaid_total'       => '0.00',
                'open_tickets_count' => 0,
            ],
            'visitor_meta'  => [
                'ip'            => $ip,
                'source_domain' => $domain,
                'source_page'   => $page,
                'user_agent'    => $meta['user_agent'] ?? 'Mobile / Web Browser',
            ],
            'services'      => [],
            'invoices'      => [],
        ];
    }

    /**
     * Pre-saved quick canned responses for common support scenarios.
     */
    public static function getCannedResponses(): array
    {
        return [
            'status'    => 'success',
            'responses' => [
                [
                    'id'       => 1,
                    'title'    => 'Standard Greeting',
                    'shortcut' => '/hi',
                    'text'     => 'Hello! Thank you for reaching out to support. How may I assist you today?',
                ],
                [
                    'id'       => 2,
                    'title'    => 'Investigating Account',
                    'shortcut' => '/wait',
                    'text'     => 'I am reviewing your account and service configuration right now. Please allow me just 1-2 minutes.',
                ],
                [
                    'id'       => 3,
                    'title'    => 'DNS & Propagation',
                    'shortcut' => '/dns',
                    'text'     => 'DNS changes typically take 1 to 24 hours to propagate globally. You can monitor the status at whatsmydns.net.',
                ],
                [
                    'id'       => 4,
                    'title'    => 'Ticket Escalation',
                    'shortcut' => '/escalate',
                    'text'     => 'I have opened a priority ticket with our engineering team for this. You will receive an email update shortly.',
                ],
                [
                    'id'       => 5,
                    'title'    => 'Closing & Follow-up',
                    'shortcut' => '/bye',
                    'text'     => 'Is there anything else I can help you with today? Thank you for choosing us!',
                ],
            ],
        ];
    }

    /**
     * Revoke a mobile token on logout.
     */
    public static function revokeToken(string $token): array
    {
        SchemaManager::ensureMobileTokensTable();

        Capsule::table('tblsahdev_mobile_tokens')
            ->where('token', $token)
            ->update(['is_revoked' => 1, 'updated_at' => Carbon::now()]);

        return ['status' => 'success', 'message' => 'Logged out successfully.'];
    }

    /**
     * Check if a pending QR code has been scanned and verified by the mobile phone.
     */
    public static function checkQrStatus(int $tokenId, int $adminId): array
    {
        SchemaManager::ensureMobileTokensTable();

        if ($tokenId <= 0) {
            return ['status' => 'error', 'message' => 'Invalid token ID.'];
        }

        $row = Capsule::table('tblsahdev_mobile_tokens')
            ->where('id', $tokenId)
            ->where('admin_id', $adminId)
            ->first();

        if (!$row) {
            return ['status' => 'not_found', 'message' => 'Token not found.'];
        }

        if ($row->is_revoked) {
            return ['status' => 'revoked', 'message' => 'Pairing session was revoked.'];
        }

        // Successfully paired if permanent token assigned and device name updated
        if (strpos($row->token, 'temp_') !== 0 && !empty($row->device_name) && $row->device_name !== 'Pending QR Scan') {
            return [
                'status'      => 'paired',
                'device_name' => $row->device_name,
                'last_ip'     => $row->last_ip ?: 'Unknown',
                'paired_at'   => $row->updated_at ? Carbon::parse($row->updated_at)->toIso8601String() : Carbon::now()->toIso8601String(),
            ];
        }

        if ($row->qr_expires_at && Carbon::parse($row->qr_expires_at)->isPast()) {
            return ['status' => 'expired', 'message' => 'Pairing code has expired.'];
        }

        return ['status' => 'pending'];
    }

    /**
     * Revoke a mobile token by its primary key ID.
     */
    public static function revokeTokenById(int $tokenId, int $adminId, bool $isSuperAdmin = false): bool
    {
        SchemaManager::ensureMobileTokensTable();

        $query = Capsule::table('tblsahdev_mobile_tokens')->where('id', $tokenId);
        if (!$isSuperAdmin) {
            $query->where('admin_id', $adminId);
        }

        $affected = $query->update([
            'is_revoked' => 1,
            'updated_at' => Carbon::now(),
        ]);

        return $affected > 0;
    }

    /**
     * List all paired devices for an admin or all admins.
     */
    public static function getPairedDevices(int $adminId, bool $isSuperAdmin = false): array
    {
        SchemaManager::ensureMobileTokensTable();

        $query = Capsule::table('tblsahdev_mobile_tokens as mt')
            ->leftJoin('tbladmins as a', 'a.id', '=', 'mt.admin_id')
            ->select([
                'mt.id',
                'mt.admin_id',
                'mt.device_name',
                'mt.device_id',
                'mt.last_ip',
                'mt.last_active_at',
                'mt.is_revoked',
                'mt.created_at',
                'mt.updated_at',
                'a.firstname',
                'a.lastname',
                'a.username',
            ])
            ->where('mt.token', 'not like', 'temp_%'); // Only real authenticated tokens

        if (!$isSuperAdmin) {
            $query->where('mt.admin_id', $adminId);
        }

        $rows = $query->orderBy('mt.last_active_at', 'desc')->get();

        $devices = [];
        foreach ($rows as $r) {
            $adminName = trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? ''));
            if (empty($adminName)) {
                $adminName = $r->username ?? ('Admin #' . $r->admin_id);
            }

            $devices[] = [
                'id'             => (int) $r->id,
                'admin_id'       => (int) $r->admin_id,
                'admin_name'     => $adminName,
                'device_name'    => $r->device_name ?: 'Android Device',
                'last_ip'        => $r->last_ip ?: '—',
                'last_active_at' => $r->last_active_at,
                'created_at'     => $r->created_at,
                'is_revoked'     => (bool) $r->is_revoked,
            ];
        }

        return $devices;
    }
}
