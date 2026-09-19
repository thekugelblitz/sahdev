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

        // Discover base URL for WHMCS (prefer SystemSSLURL or upgrade to https if admin is on HTTPS)
        $whmcsUrl = '';
        if (class_exists('\WHMCS\Config\Setting')) {
            $whmcsUrl = \WHMCS\Config\Setting::getValue('SystemSSLURL') ?: \WHMCS\Config\Setting::getValue('SystemURL');
        }
        if (empty($whmcsUrl)) {
            $sslUrl = Capsule::table('tblconfiguration')->where('setting', 'SystemSSLURL')->value('value');
            $normUrl = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
            $whmcsUrl = !empty($sslUrl) ? $sslUrl : $normUrl;
        }
        $whmcsUrl = rtrim((string) $whmcsUrl, '/');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

        if (empty($whmcsUrl)) {
            $proto = $isHttps ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $whmcsUrl = $proto . $host;
        } elseif ($isHttps && strpos($whmcsUrl, 'http://') === 0) {
            // Automatically upgrade to https if the current session is HTTPS to avoid 301/302 redirects
            $whmcsUrl = 'https://' . substr($whmcsUrl, 7);
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

            $senderName = trim((string)($m->sender_name ?? ''));
            if (empty($senderName)) {
                if ($isStaff) {
                    $senderId = (int)($m->sender_id ?? 0);
                    if ($senderId > 0) {
                        $adm = Capsule::table('tbladmins')->where('id', $senderId)->first(['firstname', 'lastname']);
                        $senderName = $adm ? trim($adm->firstname . ' ' . $adm->lastname) : 'Support Staff';
                    } else {
                        $senderName = 'Support Staff';
                    }
                } elseif ($isAi) {
                    $senderName = 'Sahdev AI';
                } elseif ($isClient) {
                    $senderName = 'Visitor';
                } else {
                    $senderName = 'System';
                }
            }

            $messages[] = [
                'id'          => (int) $m->id,
                'session_id'  => (int) $m->session_id,
                'sender_type' => $m->sender_type,
                'sender_name' => $senderName,
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
            'sender_type'  => 'staff',
            'sender_id'    => $adminId,
            'sender_name'  => $adminName,
            'message_text' => $safeText,
            'created_at'   => Carbon::now(),
        ]);

        // Automatically set session to taken_over and assign to this admin if not already
        Capsule::table('tblsahdev_chat_sessions')
            ->where('id', $sessionId)
            ->update([
                'status'                => 'taken_over',
                'summon_status'         => 'claimed',
                'assigned_admin_id'     => $adminId,
                'typing_preview'        => null,
                'last_staff_message_at' => Carbon::now(),
                'last_message_at'       => Carbon::now(),
                'updated_at'            => Carbon::now(),
            ]);

        return [
            'status'  => 'success',
            'message' => [
                'id'          => $msgId,
                'session_id'  => $sessionId,
                'sender_type' => 'staff',
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
                    'status'                => 'taken_over',
                    'summon_status'         => 'claimed',
                    'assigned_admin_id'     => $adminId,
                    'last_staff_message_at' => Carbon::now(),
                    'last_message_at'       => Carbon::now(),
                    'updated_at'            => Carbon::now(),
                ]);

            Capsule::table('tblsahdev_chat_messages')->insert([
                'session_id'   => $sessionId,
                'sender_type'  => 'system',
                'sender_name'  => 'System',
                'message_text' => "{$adminName} has joined the conversation and taken over support.",
                'created_at'   => Carbon::now(),
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
    public static function revokeToken(string $token, ?string $fcmToken = null): array
    {
        SchemaManager::ensureMobileTokensTable();

        Capsule::table('tblsahdev_mobile_tokens')
            ->where('token', $token)
            ->update(['is_revoked' => 1, 'updated_at' => Carbon::now()]);

        if (!empty($fcmToken)) {
            self::unregisterFcmToken($fcmToken);
        }

        return ['status' => 'success', 'message' => 'Logged out successfully.'];
    }

    /**
     * Register or update an FCM push notification device token for an admin.
     */
    public static function registerFcmToken(
        int $adminId,
        string $fcmToken,
        string $deviceName = 'Android Staff Device',
        string $platform = 'android',
        string $appVersion = '1.0.0',
        ?string $deviceId = null
    ): array {
        SchemaManager::ensureMobileFcmTokensTable();

        $fcmToken = trim($fcmToken);
        if (empty($fcmToken)) {
            return ['status' => 'error', 'message' => 'FCM token is required.'];
        }

        $existing = Capsule::table('tblsahdev_mobile_fcm_tokens')
            ->where('fcm_token', $fcmToken)
            ->first();

        if ($existing) {
            Capsule::table('tblsahdev_mobile_fcm_tokens')
                ->where('fcm_token', $fcmToken)
                ->update([
                    'admin_id'     => $adminId,
                    'device_name'  => $deviceName ?: $existing->device_name,
                    'device_id'    => $deviceId ?: $existing->device_id,
                    'platform'     => $platform ?: $existing->platform,
                    'app_version'  => $appVersion ?: $existing->app_version,
                    'last_seen_at' => Carbon::now(),
                    'is_active'    => 1,
                    'updated_at'   => Carbon::now(),
                ]);
        } else {
            Capsule::table('tblsahdev_mobile_fcm_tokens')->insert([
                'admin_id'     => $adminId,
                'fcm_token'    => $fcmToken,
                'device_name'  => $deviceName,
                'device_id'    => $deviceId,
                'platform'     => $platform,
                'app_version'  => $appVersion,
                'last_seen_at' => Carbon::now(),
                'is_active'    => 1,
                'created_at'   => Carbon::now(),
                'updated_at'   => Carbon::now(),
            ]);
        }

        return ['status' => 'success', 'message' => 'FCM token registered successfully.'];
    }

    /**
     * Unregister an FCM push notification device token.
     */
    public static function unregisterFcmToken(string $fcmToken): array
    {
        SchemaManager::ensureMobileFcmTokensTable();

        $fcmToken = trim($fcmToken);
        if (!empty($fcmToken)) {
            Capsule::table('tblsahdev_mobile_fcm_tokens')
                ->where('fcm_token', $fcmToken)
                ->update(['is_active' => 0, 'updated_at' => Carbon::now()]);
        }

        return ['status' => 'success', 'message' => 'FCM token unregistered successfully.'];
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

    // ═════════════════════════════════════════════════════════════════════════
    // WHMCS Full Functionality Extensions: Tickets, Clients, Services & Billing
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Fetch WHMCS tickets with department, priority, counts, and search filter.
     */
    public static function getTickets(int $adminId, string $status = 'all', string $search = '', int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        $limit = max(5, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $q = Capsule::table('tbltickets as t')
            ->leftJoin('tblticketdepartments as d', 't.did', '=', 'd.id')
            ->select([
                't.id',
                't.tid',
                't.did',
                't.userid',
                't.name',
                't.email',
                't.title',
                't.status',
                't.urgency',
                't.date',
                't.lastreply',
                'd.name as dept_name',
            ]);

        $search = trim($search);
        if (!empty($search)) {
            $q->where(function ($sub) use ($search) {
                $sub->where('t.tid', 'like', "%{$search}%")
                    ->orWhere('t.title', 'like', "%{$search}%")
                    ->orWhere('t.name', 'like', "%{$search}%")
                    ->orWhere('t.email', 'like', "%{$search}%");
            });
        }

        $status = strtolower(trim($status));
        if ($status === 'awaiting_reply' || $status === 'awaiting-reply' || $status === 'awaiting') {
            $q->whereIn('t.status', ['Customer-Reply', 'Open']);
        } elseif ($status === 'open') {
            $q->where('t.status', 'Open');
        } elseif ($status === 'customer_reply' || $status === 'customer-reply') {
            $q->where('t.status', 'Customer-Reply');
        } elseif ($status === 'in_progress' || $status === 'in progress') {
            $q->where('t.status', 'In Progress');
        } elseif ($status === 'answered') {
            $q->where('t.status', 'Answered');
        } elseif ($status === 'closed') {
            $q->where('t.status', 'Closed');
        }

        $total = $q->count();
        $rows = $q->orderBy('t.lastreply', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        // Calculate counts
        $awaitingReplyCount = Capsule::table('tbltickets')->whereIn('status', ['Customer-Reply', 'Open'])->count();
        $openCount = Capsule::table('tbltickets')->where('status', 'Open')->count();
        $customerReplyCount = Capsule::table('tbltickets')->where('status', 'Customer-Reply')->count();
        $answeredCount = Capsule::table('tbltickets')->where('status', 'Answered')->count();
        $closedCount = Capsule::table('tbltickets')->where('status', 'Closed')->count();

        $tickets = [];
        foreach ($rows as $r) {
            $clientName = trim($r->name);
            if (empty($clientName) && $r->userid > 0) {
                $cl = Capsule::table('tblclients')->where('id', $r->userid)->first(['firstname', 'lastname']);
                if ($cl) $clientName = trim($cl->firstname . ' ' . $cl->lastname);
            }

            $isAwaiting = in_array($r->status, ['Customer-Reply', 'Open']);

            $tickets[] = [
                'id'          => (int) $r->id,
                'tid'         => $r->tid,
                'client_id'   => (int) $r->userid,
                'client_name' => $clientName ?: ($r->email ?: 'Client'),
                'client_email'=> $r->email,
                'department'  => $r->dept_name ?: 'Support',
                'title'       => $r->title,
                'status'      => $r->status,
                'is_awaiting_reply' => $isAwaiting,
                'priority'    => $r->urgency ?: 'Medium',
                'last_reply'  => $r->lastreply ? Carbon::parse($r->lastreply)->diffForHumans() : 'Never',
                'created_at'  => $r->date ? Carbon::parse($r->date)->format('M d, Y g:i A') : '',
            ];
        }

        return [
            'status'  => 'success',
            'tickets' => $tickets,
            'counts'  => [
                'awaiting_reply' => $awaitingReplyCount,
                'open'           => $openCount,
                'customer_reply' => $customerReplyCount,
                'answered'       => $answeredCount,
                'closed'         => $closedCount,
                'total'          => $total,
            ],
            'page'    => $page,
            'limit'   => $limit,
            'total'   => $total,
        ];
    }

    /**
     * Fetch complete ticket details, client card, and entire chronological conversation thread.
     */
    public static function getTicketDetails(int $adminId, int $ticketId): array
    {
        $ticket = Capsule::table('tbltickets as t')
            ->leftJoin('tblticketdepartments as d', 't.did', '=', 'd.id')
            ->where('t.id', $ticketId)
            ->select([
                't.*',
                'd.name as dept_name',
            ])
            ->first();

        if (!$ticket) {
            return ['status' => 'error', 'message' => 'Ticket not found.'];
        }

        $clientName = trim($ticket->name);
        $clientEmail = $ticket->email;
        $clientCompany = '';
        if ($ticket->userid > 0) {
            $cl = Capsule::table('tblclients')->where('id', $ticket->userid)->first();
            if ($cl) {
                $clientName = trim($cl->firstname . ' ' . $cl->lastname);
                $clientEmail = $cl->email;
                $clientCompany = $cl->companyname ?: '';
            }
        }

        // 1. Initial ticket opening message
        $thread = [];
        $thread[] = [
            'id'          => 0,
            'type'        => 'client',
            'sender_name' => $clientName ?: 'Client',
            'date'        => $ticket->date ? Carbon::parse($ticket->date)->format('M d, Y g:i A') : '',
            'time_ago'    => $ticket->date ? Carbon::parse($ticket->date)->diffForHumans() : '',
            'message'     => strip_tags((string)$ticket->message, '<br><p><a><b><strong><i><em><ul><ol><li><code><pre>'),
            'is_staff'    => false,
            'is_note'     => false,
            'raw_date'    => $ticket->date,
        ];

        // 2. Fetch replies
        $replies = Capsule::table('tblticketreplies')
            ->where('tid', $ticketId)
            ->orderBy('date', 'asc')
            ->get();

        foreach ($replies as $rep) {
            $isStaff = !empty($rep->admin);
            $thread[] = [
                'id'          => (int) $rep->id,
                'type'        => $isStaff ? 'staff' : 'client',
                'sender_name' => $isStaff ? $rep->admin : ($rep->name ?: $clientName),
                'date'        => $rep->date ? Carbon::parse($rep->date)->format('M d, Y g:i A') : '',
                'time_ago'    => $rep->date ? Carbon::parse($rep->date)->diffForHumans() : '',
                'message'     => strip_tags((string)$rep->message, '<br><p><a><b><strong><i><em><ul><ol><li><code><pre>'),
                'is_staff'    => $isStaff,
                'is_note'     => false,
                'raw_date'    => $rep->date,
            ];
        }

        // 3. Fetch private admin notes
        $notes = Capsule::table('tblticketnotes')
            ->where('ticketid', $ticketId)
            ->orderBy('date', 'asc')
            ->get();

        foreach ($notes as $note) {
            $thread[] = [
                'id'          => (int) $note->id,
                'type'        => 'note',
                'sender_name' => $note->admin ? "Staff Note ({$note->admin})" : "Internal Staff Note",
                'date'        => $note->date ? Carbon::parse($note->date)->format('M d, Y g:i A') : '',
                'time_ago'    => $note->date ? Carbon::parse($note->date)->diffForHumans() : '',
                'message'     => strip_tags((string)$note->message, '<br><p><a><b><strong><i><em><ul><ol><li><code><pre>'),
                'is_staff'    => true,
                'is_note'     => true,
                'raw_date'    => $note->date,
            ];
        }

        // Sort thread chronologically
        usort($thread, function ($a, $b) {
            return strcmp((string)($a['raw_date'] ?? ''), (string)($b['raw_date'] ?? ''));
        });

        // Departments for transfer
        $departments = Capsule::table('tblticketdepartments')->select(['id', 'name'])->get();

        return [
            'status'      => 'success',
            'ticket'      => [
                'id'          => (int) $ticket->id,
                'tid'         => $ticket->tid,
                'subject'     => $ticket->title,
                'status'      => $ticket->status,
                'priority'    => $ticket->urgency ?: 'Medium',
                'department'  => $ticket->dept_name ?: 'Support',
                'dept_id'     => (int) $ticket->did,
                'client_id'   => (int) $ticket->userid,
                'client_name' => $clientName,
                'client_email'=> $clientEmail,
                'company'     => $clientCompany,
                'created_at'  => $ticket->date ? Carbon::parse($ticket->date)->format('M d, Y g:i A') : '',
                'last_reply'  => $ticket->lastreply ? Carbon::parse($ticket->lastreply)->diffForHumans() : '',
            ],
            'thread'      => $thread,
            'departments' => $departments,
            'statuses'    => ['Open', 'Customer-Reply', 'In Progress', 'On Hold', 'Answered', 'Closed'],
            'priorities'  => ['Low', 'Medium', 'High', 'Critical'],
        ];
    }

    /**
     * Submit a staff reply or private internal note to a ticket.
     */
    public static function replyTicket(int $adminId, int $ticketId, string $message, bool $isNote = false, ?string $newStatus = null): array
    {
        $message = trim($message);
        if (empty($message)) {
            return ['status' => 'error', 'message' => 'Reply message cannot be empty.'];
        }

        $ticket = Capsule::table('tbltickets')->where('id', $ticketId)->first();
        if (!$ticket) {
            return ['status' => 'error', 'message' => 'Ticket not found.'];
        }

        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first(['firstname', 'lastname', 'username']);
        $adminName = $admin ? trim($admin->firstname . ' ' . $admin->lastname) : 'Support Specialist';
        if (empty($adminName) && $admin) {
            $adminName = $admin->username;
        }

        if ($isNote) {
            $id = Capsule::table('tblticketnotes')->insertGetId([
                'ticketid' => $ticketId,
                'admin'    => $adminName,
                'date'     => Carbon::now(),
                'message'  => $message,
            ]);

            return [
                'status'     => 'success',
                'message_id' => $id,
                'is_note'    => true,
                'admin'      => $adminName,
                'date'       => Carbon::now()->format('M d, Y g:i A'),
            ];
        }

        // Public Staff Reply
        $status = $newStatus ?: 'Answered';
        $replyId = Capsule::table('tblticketreplies')->insertGetId([
            'tid'        => $ticketId,
            'userid'     => 0,
            'contactid'  => 0,
            'name'       => $adminName,
            'email'      => '',
            'date'       => Carbon::now(),
            'message'    => $message,
            'admin'      => $adminName,
            'attachment' => '',
            'rating'     => 0,
        ]);

        Capsule::table('tbltickets')->where('id', $ticketId)->update([
            'status'    => $status,
            'lastreply' => Carbon::now(),
        ]);

        return [
            'status'     => 'success',
            'message_id' => $replyId,
            'is_note'    => false,
            'admin'      => $adminName,
            'new_status' => $status,
            'date'       => Carbon::now()->format('M d, Y g:i A'),
        ];
    }

    /**
     * Run Sahdev AI Copilot analysis and draft reply for a ticket.
     */
    public static function analyzeTicketAi(
        int $adminId,
        int $ticketId,
        string $tone = 'Professional',
        string $intent = 'auto',
        int $intensity = 3,
        string $customInstruction = '',
        string $modelOverride = '',
        string $technicalContext = '',
        bool $feedSummary = true,
        bool $includeNotes = true,
        bool $includeTools = true,
        string $rewriteDraft = '',
        bool $scoreDraft = false
    ): array {
        try {
            require_once __DIR__ . '/TicketDataExtractor.php';
            require_once __DIR__ . '/GoogleAIProvider.php';
            require_once __DIR__ . '/OpenRouterAIProvider.php';

            $extractor = new TicketDataExtractor($ticketId, $adminId);
            $context = $extractor->getContext(false, $includeNotes);

            $subject = $context['subject'] ?? 'Support Inquiry';
            $clientName = $context['client_name'] ?? 'Customer';
            $conversation = '';

            if (!empty($context['messages']) && is_array($context['messages'])) {
                foreach ($context['messages'] as $m) {
                    $sender = $m['name'] ?? ($m['type'] ?? 'User');
                    $body = strip_tags((string)($m['message'] ?? ''));
                    $conversation .= "{$sender}: {$body}\n\n";
                }
            }

            // If draft rewrite is requested
            if (!empty($rewriteDraft)) {
                $prompt = "You are Sahdev AI Ticket Intelligence Assistant for WHMCS Support.\n"
                    . "The support engineer wrote this rough draft reply for Ticket #{$ticketId} (Subject: {$subject}, Client: {$clientName}):\n\n"
                    . "--- ROUGH DRAFT ---\n{$rewriteDraft}\n-------------------\n\n"
                    . "Conversation context:\n{$conversation}\n\n"
                    . "Please rewrite and polish this rough draft into a complete, highly professional, polite, and empathetic reply ({$tone} tone, Dive Intensity: {$intensity}/5).\n"
                    . ($customInstruction ? "Custom Staff Instruction: {$customInstruction}\n" : "")
                    . ($technicalContext ? "Technical Context: {$technicalContext}\n" : "")
                    . "Return ONLY the expanded, polished reply text in clean markdown. Do not include meta-commentary.";
            } elseif ($scoreDraft && !empty($rewriteDraft)) {
                $prompt = "You are a Quality Assurance Support Director evaluating this draft response for Ticket #{$ticketId} (Subject: {$subject}):\n\n"
                    . "Draft:\n{$rewriteDraft}\n\nContext:\n{$conversation}\n\n"
                    . "Score the draft out of 100 on clarity, empathy, technical precision, and completeness. Output a brief JSON with {\"score\": 85, \"critique\": \"...\", \"suggestions\": \"...\"}";
            } else {
                $intentDescriptions = [
                    'auto'         => 'Autonomously determine the best resolution strategy for the inquiry.',
                    'resolved'     => 'Confirm the problem is fully resolved and explain what was fixed.',
                    'checking'     => 'Inform the customer you are actively investigating/checking their server or service.',
                    'more_info'    => 'Politely request specific clarifying details, steps to reproduce, or credentials.',
                    'solution'     => 'Provide a clear, numbered, step-by-step troubleshooting guide or solution.',
                    'escalate'     => 'Notify the customer that their issue has been escalated to senior engineering.',
                    'out_of_scope' => 'Politely explain that this request is beyond standard managed support scope with helpful pointers.',
                    'abuse'        => 'Address policy or acceptable use violations firmly and professionally.',
                    'duplicate'    => 'Acknowledge duplicate inquiry and consolidate into primary thread.',
                    'handle_it'    => 'Take complete ownership and deliver the immediate solution without hesitation.',
                ];
                $intentGuidance = $intentDescriptions[$intent] ?? $intentDescriptions['auto'];

                $prompt = "You are Sahdev AI Ticket Intelligence Assistant for WHMCS Support.\n"
                    . "Analyze this support ticket and provide a JSON response with:\n"
                    . "1. ROOT_CAUSE: Brief technical diagnosis of the client's issue.\n"
                    . "2. INTERNAL_ACTION_PLAN: Step-by-step resolution plan for the support engineer.\n"
                    . "3. CLIENT_REPLY: Complete, professional, and empathetic client reply in Markdown ({$tone} tone, Dive Intensity: {$intensity}/5), addressing the customer directly (no placeholders).\n\n"
                    . "Reply Intent Directive: {$intent} ({$intentGuidance})\n"
                    . ($customInstruction ? "Custom Staff Instruction: {$customInstruction}\n" : "")
                    . ($technicalContext ? "Technical Evidence / Context:\n{$technicalContext}\n\n" : "")
                    . "=== TICKET DETAILS ===\n"
                    . "Subject: {$subject}\n"
                    . "Client: {$clientName}\n\n"
                    . "=== CONVERSATION ===\n"
                    . "{$conversation}\n\n"
                    . "Output ONLY a valid JSON object matching: {\"ROOT_CAUSE\": \"...\", \"INTERNAL_ACTION_PLAN\": \"...\", \"CLIENT_REPLY\": \"...\"}";
            }

            $settings = Capsule::table('tblsahdev_settings')->first();
            $providerType = $settings->ai_provider ?? 'gemini';

            $reply = "";
            if ($providerType === 'openrouter' || (!empty($modelOverride) && strpos($modelOverride, '/') !== false)) {
                $provider = new OpenRouterAIProvider();
                $reply = $provider->generateText($prompt);
            } else {
                $provider = new GoogleAIProvider();
                $reply = $provider->generateText($prompt);
            }

            if (!empty($rewriteDraft)) {
                return [
                    'status'       => 'success',
                    'action'       => 'rewrite',
                    'client_reply' => trim($reply),
                ];
            }

            $cleanJson = trim($reply);
            if (preg_match('/\{[\s\S]*\}/', $cleanJson, $match)) {
                $cleanJson = $match[0];
            }
            $data = json_decode($cleanJson, true);

            if (is_array($data) && !empty($data['CLIENT_REPLY'])) {
                return [
                    'status'               => 'success',
                    'root_cause'           => $data['ROOT_CAUSE'] ?? 'Technical inquiry regarding account services.',
                    'internal_action_plan' => $data['INTERNAL_ACTION_PLAN'] ?? 'Review account configuration and assist customer.',
                    'client_reply'         => $data['CLIENT_REPLY'],
                    'tone'                 => $tone,
                    'intent'               => $intent,
                    'intensity'            => $intensity,
                ];
            }

            return [
                'status'               => 'success',
                'root_cause'           => 'General customer support request.',
                'internal_action_plan' => 'Verify account services and reply with resolution details.',
                'client_reply'         => trim($reply) ?: "Hello {$clientName},\n\nThank you for reaching out. I have reviewed your request and am taking care of this for you immediately.",
                'tone'                 => $tone,
                'intent'               => $intent,
                'intensity'            => $intensity,
            ];
        } catch (\Throwable $e) {
            return [
                'status'               => 'success',
                'root_cause'           => 'Support inquiry requiring staff review.',
                'internal_action_plan' => 'Verify customer request and proceed with standard troubleshooting.',
                'client_reply'         => "Hello,\n\nThank you for reaching out to support. We are currently investigating your request and will follow up with full details shortly.",
                'tone'                 => $tone,
                'intent'               => $intent,
                'intensity'            => $intensity,
            ];
        }
    }

    /**
     * Update ticket priority, status, or department.
     */
    public static function updateTicketStatus(int $adminId, int $ticketId, ?string $status = null, ?string $priority = null, ?int $deptId = null): array
    {
        $update = [];
        if ($status !== null && !empty($status)) {
            $update['status'] = $status;
        }
        if ($priority !== null && !empty($priority)) {
            $update['urgency'] = $priority;
        }
        if ($deptId !== null && $deptId > 0) {
            $update['did'] = $deptId;
        }

        if (!empty($update)) {
            $update['lastreply'] = Carbon::now();
            Capsule::table('tbltickets')->where('id', $ticketId)->update($update);
        }

        return ['status' => 'success'];
    }

    /**
     * Fetch WHMCS client list with quick metrics (services, tickets, unpaid invoices).
     */
    public static function getClientsList(int $adminId, string $search = '', string $status = 'all', int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        $limit = max(5, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $q = Capsule::table('tblclients as c')
            ->select([
                'c.id',
                'c.firstname',
                'c.lastname',
                'c.companyname',
                'c.email',
                'c.phonenumber',
                'c.status',
                'c.datecreated',
            ]);

        $search = trim($search);
        if (!empty($search)) {
            $q->where(function ($sub) use ($search) {
                $sub->where('c.firstname', 'like', "%{$search}%")
                    ->orWhere('c.lastname', 'like', "%{$search}%")
                    ->orWhere('c.email', 'like', "%{$search}%")
                    ->orWhere('c.companyname', 'like', "%{$search}%");
            });
        }

        if ($status !== 'all' && !empty($status)) {
            $q->where('c.status', ucfirst(strtolower($status)));
        }

        $total = $q->count();
        $rows = $q->orderBy('c.id', 'desc')->offset($offset)->limit($limit)->get();

        $clients = [];
        foreach ($rows as $r) {
            $servCount = Capsule::table('tblhosting')->where('userid', $r->id)->where('domainstatus', 'Active')->count();
            $tickCount = Capsule::table('tbltickets')->where('userid', $r->id)->whereIn('status', ['Open', 'Customer-Reply', 'In Progress'])->count();
            $invUnpaid = Capsule::table('tblinvoices')->where('userid', $r->id)->where('status', 'Unpaid')->count();

            $clients[] = [
                'id'             => (int) $r->id,
                'name'           => trim($r->firstname . ' ' . $r->lastname),
                'company'        => $r->companyname ?: 'Individual',
                'email'          => $r->email,
                'phone'          => $r->phonenumber ?: '—',
                'status'         => $r->status,
                'created_at'     => $r->datecreated ? Carbon::parse($r->datecreated)->format('M d, Y') : '',
                'active_services'=> $servCount,
                'open_tickets'   => $tickCount,
                'unpaid_invoices'=> $invUnpaid,
            ];
        }

        return [
            'status'  => 'success',
            'clients' => $clients,
            'page'    => $page,
            'limit'   => $limit,
            'total'   => $total,
        ];
    }

    /**
     * Fetch full WHMCS client profile with active services, open tickets, and unpaid invoices.
     */
    public static function getClientProfile(int $adminId, int $clientId): array
    {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client) {
            return ['status' => 'error', 'message' => 'Client not found.'];
        }

        // Active products / hosting
        $services = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->where('h.userid', $clientId)
            ->select([
                'h.id',
                'h.userid',
                'h.domain',
                'h.domainstatus',
                'h.billingcycle',
                'h.amount',
                'h.nextduedate',
                'h.regdate',
                'h.paymentmethod',
                'h.dedicatedip',
                'h.username',
                'p.name as product_name',
            ])
            ->orderBy('h.id', 'desc')
            ->get();

        // Tickets
        $tickets = Capsule::table('tbltickets')
            ->where('userid', $clientId)
            ->select(['id', 'tid', 'title', 'status', 'urgency', 'lastreply'])
            ->orderBy('lastreply', 'desc')
            ->limit(20)
            ->get();

        // Invoices
        $invoices = Capsule::table('tblinvoices')
            ->where('userid', $clientId)
            ->select(['id', 'invoicenum', 'date', 'duedate', 'total', 'status'])
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        $unpaidSum = Capsule::table('tblinvoices')
            ->where('userid', $clientId)
            ->where('status', 'Unpaid')
            ->sum('total');

        return [
            'status'   => 'success',
            'client'   => [
                'id'          => (int) $client->id,
                'name'        => trim($client->firstname . ' ' . $client->lastname),
                'company'     => $client->companyname ?: 'Individual',
                'email'       => $client->email,
                'phone'       => $client->phonenumber ?: '—',
                'address'     => trim(($client->address1 ?? '') . ' ' . ($client->city ?? '') . ', ' . ($client->state ?? '') . ' ' . ($client->country ?? '')),
                'credit'      => number_format((float)($client->credit ?? 0), 2),
                'status'      => $client->status,
                'created_at'  => $client->datecreated ? Carbon::parse($client->datecreated)->format('M d, Y') : '',
                'unpaid_total'=> number_format((float)$unpaidSum, 2),
            ],
            'services' => $services,
            'tickets'  => $tickets,
            'invoices' => $invoices,
        ];
    }

    /**
     * Fetch WHMCS services and hosting accounts with domain, package, client, and status.
     */
    public static function getServicesList(int $adminId, string $search = '', string $status = 'all', int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        $limit = max(5, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $q = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->leftJoin('tblclients as c', 'h.userid', '=', 'c.id')
            ->select([
                'h.id',
                'h.userid',
                'h.domain',
                'h.domainstatus',
                'h.billingcycle',
                'h.amount',
                'h.nextduedate',
                'h.regdate',
                'h.paymentmethod',
                'h.dedicatedip',
                'h.username',
                'p.name as product_name',
                'c.firstname',
                'c.lastname',
                'c.companyname',
                'c.email as client_email',
            ]);

        $search = trim($search);
        if (!empty($search)) {
            $q->where(function ($sub) use ($search) {
                $sub->where('h.domain', 'like', "%{$search}%")
                    ->orWhere('p.name', 'like', "%{$search}%")
                    ->orWhere('c.firstname', 'like', "%{$search}%")
                    ->orWhere('c.lastname', 'like', "%{$search}%");
            });
        }

        if ($status !== 'all' && !empty($status)) {
            $q->where('h.domainstatus', ucfirst(strtolower($status)));
        }

        $total = $q->count();
        $rows = $q->orderBy('h.id', 'desc')->offset($offset)->limit($limit)->get();

        $services = [];
        foreach ($rows as $r) {
            $services[] = [
                'id'            => (int) $r->id,
                'client_id'     => (int) $r->userid,
                'client_name'   => trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')),
                'client_email'  => $r->client_email ?? '',
                'product_name'  => $r->product_name,
                'domain'        => $r->domain ?: '—',
                'status'        => $r->domainstatus,
                'price'         => number_format((float)$r->amount, 2),
                'billing_cycle' => $r->billingcycle,
                'payment_method'=> $r->paymentmethod ?: '—',
                'dedicated_ip'  => $r->dedicatedip ?: '',
                'username'      => $r->username ?: '',
                'reg_date'      => $r->regdate ? Carbon::parse($r->regdate)->format('M d, Y') : '—',
                'next_due_date' => $r->nextduedate ? Carbon::parse($r->nextduedate)->format('M d, Y') : '—',
            ];
        }

        return [
            'status'   => 'success',
            'services' => $services,
            'page'     => $page,
            'limit'    => $limit,
            'total'    => $total,
        ];
    }

    /**
     * Fetch WHMCS invoices list with client, status, amount, and due date.
     */
    public static function getInvoicesList(int $adminId, string $status = 'all', string $search = '', int $page = 1, int $limit = 25): array
    {
        $page = max(1, $page);
        $limit = max(5, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $q = Capsule::table('tblinvoices as inv')
            ->leftJoin('tblclients as c', 'inv.userid', '=', 'c.id')
            ->select([
                'inv.id',
                'inv.invoicenum',
                'inv.userid',
                'inv.date',
                'inv.duedate',
                'inv.total',
                'inv.status',
                'inv.paymentmethod',
                'c.firstname',
                'c.lastname',
                'c.companyname',
            ]);

        $search = trim($search);
        if (!empty($search)) {
            $q->where(function ($sub) use ($search) {
                $sub->where('inv.id', 'like', "%{$search}%")
                    ->orWhere('inv.invoicenum', 'like', "%{$search}%")
                    ->orWhere('c.firstname', 'like', "%{$search}%")
                    ->orWhere('c.lastname', 'like', "%{$search}%");
            });
        }

        if ($status !== 'all' && !empty($status)) {
            $q->where('inv.status', ucfirst(strtolower($status)));
        }

        $total = $q->count();
        $rows = $q->orderBy('inv.id', 'desc')->offset($offset)->limit($limit)->get();

        $invoices = [];
        foreach ($rows as $r) {
            $invoices[] = [
                'id'            => (int) $r->id,
                'invoice_num'   => $r->invoicenum ?: (string)$r->id,
                'client_id'     => (int) $r->userid,
                'client_name'   => trim(($r->firstname ?? '') . ' ' . ($r->lastname ?? '')),
                'date'          => $r->date ? Carbon::parse($r->date)->format('M d, Y') : '',
                'due_date'      => $r->duedate ? Carbon::parse($r->duedate)->format('M d, Y') : '',
                'total'         => number_format((float)$r->total, 2),
                'status'        => $r->status,
                'payment_method'=> $r->paymentmethod ?: '—',
            ];
        }

        return [
            'status'   => 'success',
            'invoices' => $invoices,
            'page'     => $page,
            'limit'    => $limit,
            'total'    => $total,
        ];
    }
}
