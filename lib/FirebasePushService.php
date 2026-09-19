<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

require_once __DIR__ . '/SchemaManager.php';
require_once __DIR__ . '/ModuleLogger.php';

/**
 * FirebasePushService
 *
 * Direct Firebase Cloud Messaging (FCM HTTP v1) push notification service for Sahdev Mobile.
 * Authenticates directly with Google via RS256 JWT OAuth 2.0 without external library dependencies,
 * dispatches high-priority pushes to wake Android devices in background/killed states,
 * automatically prunes dead tokens, and supports future cloud relay gateways.
 */
class FirebasePushService
{
    private static ?string $cachedAccessToken = null;
    private static int $cachedTokenExpiresAt = 0;

    /**
     * Clean and decode HTML entities and slashes from JSON strings (fixes WHMCS $_POST entity encoding).
     */
    public static function cleanJsonString(string $input): string
    {
        $input = trim($input);
        if (empty($input)) {
            return '';
        }

        // Decode HTML entities if doubly or triply encoded by WHMCS (e.g. &quot;, &amp;quot;)
        $decoded = $input;
        $prev = '';
        $maxPasses = 3;
        while ($prev !== $decoded && $maxPasses-- > 0) {
            $prev = $decoded;
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $test = json_decode($decoded, true);
        if (is_array($test)) {
            return $decoded;
        }

        if (strpos($decoded, '\"') !== false) {
            $stripped = stripslashes($decoded);
            if (is_array(json_decode($stripped, true))) {
                return $stripped;
            }
        }

        return trim($decoded);
    }

    /**
     * Get Firebase settings from database.
     */
    public static function getSettings(): array
    {
        SchemaManager::ensureAll();
        $settings = Capsule::table('tblsahdev_settings')->first();
        if (!$settings) {
            return [];
        }

        $rawDbJson = (string) ($settings->firebase_service_account_json ?? '');
        $serviceAccountJson = self::cleanJsonString($rawDbJson);

        // Self-heal: If database contained &quot; entities, update DB with clean JSON automatically
        if (!empty($rawDbJson) && strpos($rawDbJson, '&quot;') !== false && !empty($serviceAccountJson)) {
            try {
                Capsule::table('tblsahdev_settings')
                    ->where('id', $settings->id ?? 1)
                    ->update(['firebase_service_account_json' => $serviceAccountJson]);
            } catch (\Throwable $e) {}
        }

        $serviceAccount = [];
        if (!empty($serviceAccountJson)) {
            $decoded = json_decode($serviceAccountJson, true);
            if (is_array($decoded)) {
                $serviceAccount = $decoded;
            }
        }

        $projectId = !empty($settings->firebase_project_id)
            ? (string) $settings->firebase_project_id
            : ($serviceAccount['project_id'] ?? '');

        return [
            'enabled'               => !empty($settings->firebase_enabled),
            'project_id'            => $projectId,
            'client_email'          => $serviceAccount['client_email'] ?? '',
            'private_key'           => $serviceAccount['private_key'] ?? '',
            'raw_json'              => $serviceAccountJson,
            'gateway_url'           => (string) ($settings->firebase_gateway_url ?? ''),
            'notify_summons'        => isset($settings->firebase_notify_summons) ? (bool)$settings->firebase_notify_summons : true,
            'notify_chat_messages'  => isset($settings->firebase_notify_chat_messages) ? (bool)$settings->firebase_notify_chat_messages : true,
            'notify_tickets'        => isset($settings->firebase_notify_tickets) ? (bool)$settings->firebase_notify_tickets : true,
            'notify_system_alerts'  => isset($settings->firebase_notify_system_alerts) ? (bool)$settings->firebase_notify_system_alerts : true,
        ];
    }

    /**
     * Check if Firebase push is properly configured and enabled.
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        if (!$settings['enabled']) {
            return false;
        }

        if (!empty($settings['gateway_url'])) {
            return true;
        }

        return !empty($settings['project_id']) && !empty($settings['client_email']) && !empty($settings['private_key']);
    }

    /**
     * Obtain a valid Google OAuth 2.0 Bearer token for FCM HTTP v1 API.
     * Uses RS256 JWT self-signing with openssl.
     */
    public static function getAccessToken(): ?string
    {
        $now = time();
        if (self::$cachedAccessToken && self::$cachedTokenExpiresAt > ($now + 120)) {
            return self::$cachedAccessToken;
        }

        $settings = self::getSettings();
        $clientEmail = $settings['client_email'];
        $privateKey = $settings['private_key'];

        if (empty($clientEmail) || empty($privateKey)) {
            ModuleLogger::error('firebase', 'Missing client_email or private_key in Firebase Service Account credentials.');
            return null;
        }

        // Standard Google OAuth 2.0 JWT Bearer Grant
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $issuedAt = $now;
        $expiresAt = $issuedAt + 3600; // 1 hour

        $payload = [
            'iss'   => $clientEmail,
            'sub'   => $clientEmail,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $issuedAt,
            'exp'   => $expiresAt,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ];

        $encodedHeader = self::base64UrlEncode(json_encode($header));
        $encodedPayload = self::base64UrlEncode(json_encode($payload));
        $signingInput = $encodedHeader . '.' . $encodedPayload;

        $signature = '';
        $success = @openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            ModuleLogger::error('firebase', 'Failed to sign Google OAuth JWT with provided private key. Verify RSA private key format.');
            return null;
        }

        $jwt = $signingInput . '.' . self::base64UrlEncode($signature);

        // Exchange JWT for Bearer access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            ModuleLogger::error('firebase', "Failed to obtain Google OAuth access token. HTTP {$httpCode}: {$response} - {$curlError}");
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            ModuleLogger::error('firebase', "Malformed OAuth response from Google: {$response}");
            return null;
        }

        self::$cachedAccessToken = (string) $data['access_token'];
        self::$cachedTokenExpiresAt = $now + (int) ($data['expires_in'] ?? 3600);

        return self::$cachedAccessToken;
    }

    /**
     * Dispatch an FCM notification to a specific device registration token.
     *
     * @param string $deviceToken FCM registration token
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Custom key-value data payload
     * @param string $channelId Android notification channel ID
     * @param string $priority 'high' or 'normal'
     * @return array Result ['success' => bool, 'status' => string, 'error' => ?string]
     */
    public static function sendToDevice(
        string $deviceToken,
        string $title,
        string $body,
        array $data = [],
        string $channelId = 'sahdev_messages_channel',
        string $priority = 'high'
    ): array {
        $settings = self::getSettings();
        if (!$settings['enabled']) {
            return ['success' => false, 'error' => 'Firebase notifications are disabled in settings.'];
        }

        // Ensure string-only values in data map for FCM compliance
        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
        }
        $stringData['title'] = $title;
        $stringData['body'] = $body;
        $stringData['channel_id'] = $channelId;
        $stringData['timestamp'] = (string)time();

        // 1. Cloud Relay Gateway route (if configured)
        if (!empty($settings['gateway_url'])) {
            return self::sendViaGateway($settings['gateway_url'], $deviceToken, $title, $body, $stringData, $channelId);
        }

        // 2. Direct FCM HTTP v1 route
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            return ['success' => false, 'error' => 'Could not obtain Google OAuth access token. Check credentials.'];
        }

        $projectId = $settings['project_id'];
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $messagePayload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'data' => $stringData,
                'android' => [
                    'priority' => $priority,
                    'ttl'      => '86400s',
                    'notification' => [
                        'channel_id'              => $channelId,
                        'priority'                => ($priority === 'high') ? 'PRIORITY_MAX' : 'PRIORITY_DEFAULT',
                        'default_sound'           => true,
                        'default_vibrate_timings' => true,
                        'click_action'            => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messagePayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}",
            'Content-Type: application/json; charset=UTF-8',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            ModuleLogger::error('firebase', "CURL error sending push to device: {$curlError}");
            return ['success' => false, 'error' => $curlError];
        }

        $resDecoded = json_decode($response, true);

        if ($httpCode === 200) {
            return ['success' => true, 'data' => $resDecoded];
        }

        // Stale token cleanup: If FCM reports token unregistered or not found, prune it
        $errorCode = $resDecoded['error']['details'][0]['errorCode'] ?? ($resDecoded['error']['status'] ?? '');
        if ($httpCode === 404 || in_array($errorCode, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true)) {
            ModuleLogger::info('firebase', "Pruning expired or unregistered device token: " . substr($deviceToken, 0, 16) . '...');
            Capsule::table('tblsahdev_mobile_fcm_tokens')
                ->where('fcm_token', $deviceToken)
                ->update(['is_active' => 0, 'updated_at' => Carbon::now()]);
        }

        $errMsg = $resDecoded['error']['message'] ?? "HTTP {$httpCode}: {$response}";
        ModuleLogger::error('firebase', "FCM dispatch error: {$errMsg}");
        return ['success' => false, 'error' => $errMsg, 'http_code' => $httpCode];
    }

    /**
     * Dispatch push via a centralized cloud push relay gateway.
     */
    private static function sendViaGateway(
        string $gatewayUrl,
        string $deviceToken,
        string $title,
        string $body,
        array $stringData,
        string $channelId
    ): array {
        $payload = [
            'device_token' => $deviceToken,
            'title'        => $title,
            'body'         => $body,
            'data'         => $stringData,
            'channel_id'   => $channelId,
        ];

        $ch = curl_init($gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        return [
            'success' => ($httpCode === 200 && ($decoded['success'] ?? false)),
            'data'    => $decoded,
            'error'   => ($httpCode !== 200) ? "Gateway error HTTP {$httpCode}" : null,
        ];
    }

    /**
     * Dispatch notification to a specific admin's registered devices.
     */
    public static function sendToAdmin(int $adminId, string $title, string $body, array $data = [], string $channelId = 'sahdev_messages_channel'): int
    {
        if (!self::isConfigured()) {
            return 0;
        }

        $tokens = Capsule::table('tblsahdev_mobile_fcm_tokens')
            ->where('admin_id', $adminId)
            ->where('is_active', 1)
            ->pluck('fcm_token');

        $sentCount = 0;
        foreach ($tokens as $token) {
            $res = self::sendToDevice($token, $title, $body, $data, $channelId);
            if ($res['success']) {
                $sentCount++;
            }
        }

        return $sentCount;
    }

    /**
     * Dispatch notification to all active staff devices.
     */
    public static function sendToAllStaff(
        string $title,
        string $body,
        array $data = [],
        string $channelId = 'sahdev_messages_channel',
        ?int $departmentId = null
    ): int {
        if (!self::isConfigured()) {
            return 0;
        }

        $query = Capsule::table('tblsahdev_mobile_fcm_tokens')
            ->where('is_active', 1);

        // Department filtering for tickets if specified
        if ($departmentId !== null && $departmentId > 0) {
            // Find admins with department permission
            $adminIds = Capsule::table('tbladmins')
                ->where('disabled', 0)
                ->pluck('id')
                ->toArray();

            // In WHMCS, supportdepts can be comma-separated or staff assigned
            $filteredAdminIds = [];
            foreach ($adminIds as $aId) {
                $adm = Capsule::table('tbladmins')->where('id', $aId)->first();
                if ($adm) {
                    $depts = explode(',', (string)($adm->supportdepts ?? ''));
                    if (empty($adm->supportdepts) || in_array((string)$departmentId, $depts, true)) {
                        $filteredAdminIds[] = $aId;
                    }
                }
            }

            if (!empty($filteredAdminIds)) {
                $query->whereIn('admin_id', $filteredAdminIds);
            }
        }

        $tokens = $query->pluck('fcm_token');
        $sentCount = 0;
        foreach ($tokens as $token) {
            $res = self::sendToDevice($token, $title, $body, $data, $channelId);
            if ($res['success']) {
                $sentCount++;
            }
        }

        return $sentCount;
    }

    /**
     * Dispatch high-priority urgent summon alert to all staff phones.
     */
    public static function sendSummonAlert(int $sessionId, string $clientName, string $domain): int
    {
        $settings = self::getSettings();
        if (!$settings['notify_summons']) {
            return 0;
        }

        $title = "🚨 Human Support Summoned!";
        $body = "{$clientName} is waiting for a live agent on {$domain}";

        $data = [
            'event_type'    => 'summon',
            'session_id'    => (string)$sessionId,
            'client_name'   => $clientName,
            'domain'        => $domain,
            'urgent'        => 'true',
            'sound'         => 'alarm',
        ];

        return self::sendToAllStaff($title, $body, $data, 'sahdev_summon_channel');
    }

    /**
     * Dispatch alert for an incoming visitor message.
     */
    public static function sendChatMessageAlert(
        int $sessionId,
        string $senderName,
        string $messageText,
        ?int $assignedAdminId = null
    ): int {
        $settings = self::getSettings();
        if (!$settings['notify_chat_messages']) {
            return 0;
        }

        $cleanText = mb_substr(strip_tags($messageText), 0, 120);
        $title = "💬 Message from {$senderName}";
        $body = $cleanText;

        $data = [
            'event_type'    => 'chat_message',
            'session_id'    => (string)$sessionId,
            'sender_name'   => $senderName,
            'message_text'  => $cleanText,
        ];

        if ($assignedAdminId !== null && $assignedAdminId > 0) {
            return self::sendToAdmin($assignedAdminId, $title, $body, $data, 'sahdev_messages_channel');
        }

        return self::sendToAllStaff($title, $body, $data, 'sahdev_messages_channel');
    }

    /**
     * Dispatch alert for a new WHMCS ticket or client reply.
     */
    public static function sendTicketAlert(
        int $ticketId,
        string $subject,
        string $clientName = '',
        string $actionType = 'opened',
        ?int $departmentId = null
    ): int {
        $settings = self::getSettings();
        if (!$settings['notify_tickets']) {
            return 0;
        }

        $title = ($actionType === 'reply')
            ? "📩 Ticket Reply: #{$ticketId}"
            : "🎫 New Ticket: #{$ticketId}";

        $body = !empty($clientName) ? "{$clientName}: {$subject}" : $subject;

        $data = [
            'event_type'    => 'ticket',
            'ticket_id'     => (string)$ticketId,
            'action_type'   => $actionType,
            'subject'       => $subject,
        ];

        return self::sendToAllStaff($title, $body, $data, 'sahdev_tickets_channel', $departmentId);
    }

    /**
     * Dispatch autonomous AI or system critical alert.
     */
    public static function sendSystemAlert(string $title, string $message, string $level = 'warning'): int
    {
        $settings = self::getSettings();
        if (!$settings['notify_system_alerts']) {
            return 0;
        }

        $data = [
            'event_type' => 'system_alert',
            'level'      => $level,
        ];

        return self::sendToAllStaff("⚠️ {$title}", $message, $data, 'sahdev_system_channel');
    }

    /**
     * Helper to base64url encode a string.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
