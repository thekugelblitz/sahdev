<?php

use WHMCS\Database\Capsule;

/**
 * Sahdev AI - AJAX Endpoint
 * Secured entry point for analyzing tickets.
 */

// Initialize WHMCS securely if not already initialized
if (!defined("WHMCS")) {
    require_once dirname(__DIR__, 3) . '/init.php';
}

// Always ensure JSON output for this endpoint
header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

// Check if admin is logged in securely
$adminId = $_SESSION['adminid'] ?? null;

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$isClientChatAction = in_array($action, [
    'client_chat_init', 'client_chat_message', 'client_chat_escalate',
    'client_chat_get_history', 'client_chat_load_session', 'client_chat_new_session',
    'client_chat_poll', 'client_chat_kb_search'
], true);

if (!$adminId && !$isClientChatAction) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Please login as admin.']);
    exit;
}

// ── Dedicated Client-Side Live Chat Handler ──────────────────────────────────
// Full tenant isolation: Zero admin privilege, zero mutating WHMCS tools or SafeOps,
// strictly scoped to the authenticated client's read-only profile.
if ($isClientChatAction) {
    try {
        require_once __DIR__ . '/lib/ModuleLogger.php';
        require_once __DIR__ . '/lib/SchemaManager.php';
        require_once __DIR__ . '/lib/AIProviderInterface.php';
        require_once __DIR__ . '/lib/GoogleAIProvider.php';
        require_once __DIR__ . '/lib/LMStudioAIProvider.php';
        require_once __DIR__ . '/lib/OpenRouterAIProvider.php';
        require_once __DIR__ . '/lib/ChatService.php';

        \Sahdev\Lib\SchemaManager::ensureChatSessionsTable();
        \Sahdev\Lib\SchemaManager::ensureChatMessagesTable();

        $settings = Capsule::table('tblsahdev_settings')->first();
        if (!$settings || empty($settings->client_chat_enabled)) {
            \Sahdev\Lib\ModuleLogger::warning('client_chat', 'Live chat request rejected: client_chat_enabled is disabled.');
            echo json_encode(['status' => 'error', 'message' => 'Live chat is currently unavailable.']);
            exit;
        }

        // ── Security Barrier: Origin & CSRF Validation for Mutating Actions ──
        if (in_array($action, ['client_chat_message', 'client_chat_escalate', 'client_chat_new_session'], true)) {
            $systemUrl = \Sahdev\Lib\ChatService::getWhmcsSystemUrl();
            $whmcsHost = !empty($systemUrl) ? parse_url($systemUrl, PHP_URL_HOST) : ($_SERVER['HTTP_HOST'] ?? '');
            
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $requestHost = '';
            if (!empty($origin)) {
                $requestHost = parse_url($origin, PHP_URL_HOST);
            } elseif (!empty($referer)) {
                $requestHost = parse_url($referer, PHP_URL_HOST);
            }

            if (!empty($whmcsHost) && !empty($requestHost) && strcasecmp($whmcsHost, $requestHost) !== 0) {
                \Sahdev\Lib\ModuleLogger::warning('client_chat_security', "Cross-origin live chat request blocked from [{$requestHost}] (expected [{$whmcsHost}])");
                header('HTTP/1.1 403 Forbidden');
                echo json_encode(['status' => 'error', 'message' => 'Cross-origin requests are forbidden.']);
                exit;
            }
        }

        $clientId = !empty($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
        if (!empty($settings->client_chat_require_auth) && (!$clientId || $clientId <= 0)) {
            \Sahdev\Lib\ModuleLogger::info('client_chat', 'Live chat request rejected: user authentication required.');
            echo json_encode(['status' => 'error', 'message' => 'Please log in to your account to use live chat.']);
            exit;
        }

        // ── Secure Visitor Token Extraction & Sanitization ───────────────────
        $rawVisitorToken = trim((string) ($_REQUEST['visitor_token'] ?? ''));
        $visitorToken = '';
        if (!empty($rawVisitorToken) && preg_match('/^[a-zA-Z0-9_\-]{16,64}$/', $rawVisitorToken)) {
            $visitorToken = $rawVisitorToken;
        } else {
            $visitorToken = bin2hex(random_bytes(16));
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if ($action === 'client_chat_init') {
            $metadata = [
                'ip'         => $clientIp,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'page'       => $_REQUEST['page_url'] ?? '',
            ];

            if ($clientId > 0) {
                $client = Capsule::table('tblclients')->where('id', $clientId)->first();
                if ($client) {
                    $metadata['name']  = trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''));
                    $metadata['email'] = $client->email ?? '';
                }
            }

            $sessionUuid = trim((string) ($_REQUEST['session_uuid'] ?? ''));
            $session = \Sahdev\Lib\ChatService::getOrCreateClientSession($visitorToken, $clientId, $metadata, $sessionUuid);
            $messages = \Sahdev\Lib\ChatService::getSessionMessages((int) $session['id'], 50);
            $greeting = !empty($settings->client_chat_welcome_message)
                ? $settings->client_chat_welcome_message
                : (!empty($settings->client_chat_greeting)
                    ? $settings->client_chat_greeting
                    : "Hi there! 👋 Need help with your hosting, domains, or billing? Chat with our AI assistant or open a ticket anytime.");

            \Sahdev\Lib\ModuleLogger::info('client_chat', "Live chat initialized for session {$session['session_uuid']} (visitor: " . substr($visitorToken, 0, 8) . "..., client: " . ($clientId ?: 'guest') . ")");

            $quotaStatus = \Sahdev\Lib\ChatService::checkClientChatLimits($visitorToken, $clientId, (int) $session['id']);
            $maxMsgChars = (int) \Sahdev\Lib\ChatService::getChatSetting('client_chat_max_msg_chars', 1000);
            $starterChips = \Sahdev\Lib\ChatService::getStarterPrompts($clientId);
            $csatEnabled = (bool) \Sahdev\Lib\ChatService::getChatSetting('client_chat_csat_enabled', 1);
            $soundEnabled = (bool) \Sahdev\Lib\ChatService::getChatSetting('client_chat_sound_enabled', 1);

            echo json_encode([
                'status'        => 'success',
                'visitor_token' => $visitorToken,
                'session_uuid'  => $session['session_uuid'],
                'greeting'      => $greeting,
                'messages'      => $messages,
                'is_logged_in'  => ($clientId > 0),
                'client_name'   => $metadata['name'] ?? null,
                'status_chat'   => $session['status'] ?? 'active',
                'limit_status'  => $quotaStatus,
                'max_msg_chars' => $maxMsgChars,
                'starter_chips' => $starterChips,
                'csat_enabled'  => $csatEnabled,
                'sound_enabled' => $soundEnabled,
            ]);
            exit;
        }

        if ($action === 'client_chat_get_history') {
            $history = \Sahdev\Lib\ChatService::getClientSessionList($visitorToken, $clientId);
            echo json_encode([
                'status'  => 'success',
                'history' => $history,
            ]);
            exit;
        }

        if ($action === 'client_chat_load_session') {
            $sessionUuid = trim((string) ($_REQUEST['session_uuid'] ?? ''));
            $res = \Sahdev\Lib\ChatService::loadSessionMessages($sessionUuid, $visitorToken, $clientId);
            echo json_encode(array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res));
            exit;
        }

        if ($action === 'client_chat_new_session') {
            $metadata = [
                'ip'         => $clientIp,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'page'       => $_REQUEST['page_url'] ?? '',
            ];

            if ($clientId > 0) {
                $client = Capsule::table('tblclients')->where('id', $clientId)->first();
                if ($client) {
                    $metadata['name']  = trim(($client->firstname ?? '') . ' ' . ($client->lastname ?? ''));
                    $metadata['email'] = $client->email ?? '';
                }
            }

            $session = \Sahdev\Lib\ChatService::startNewClientSession($visitorToken, $clientId, $metadata);
            $greeting = !empty($settings->client_chat_welcome_message)
                ? $settings->client_chat_welcome_message
                : (!empty($settings->client_chat_greeting)
                    ? $settings->client_chat_greeting
                    : "Hi there! 👋 Need help with your hosting, domains, or billing? Chat with our AI assistant or open a ticket anytime.");

            \Sahdev\Lib\ModuleLogger::info('client_chat', "New chat thread created for session {$session['session_uuid']} (client: " . ($clientId ?: 'guest') . ")");

            $quotaStatus = \Sahdev\Lib\ChatService::checkClientChatLimits($visitorToken, $clientId, (int) ($session['id'] ?? 0));
            $maxMsgChars = (int) \Sahdev\Lib\ChatService::getChatSetting('client_chat_max_msg_chars', 1000);
            $starterChips = \Sahdev\Lib\ChatService::getStarterPrompts($clientId);
            $csatEnabled = (bool) \Sahdev\Lib\ChatService::getChatSetting('client_chat_csat_enabled', 1);
            $soundEnabled = (bool) \Sahdev\Lib\ChatService::getChatSetting('client_chat_sound_enabled', 1);

            echo json_encode([
                'status'        => 'success',
                'visitor_token' => $visitorToken,
                'session_uuid'  => $session['session_uuid'],
                'greeting'      => $greeting,
                'messages'      => [],
                'is_logged_in'  => ($clientId > 0),
                'limit_status'  => $quotaStatus,
                'max_msg_chars' => $maxMsgChars,
                'starter_chips' => $starterChips,
                'csat_enabled'  => $csatEnabled,
                'sound_enabled' => $soundEnabled,
            ]);
            exit;
        }

        if ($action === 'client_chat_message') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('HTTP/1.1 405 Method Not Allowed');
                echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method. POST required.']);
                exit;
            }

            // ── Active Rate Limiting: 10 messages per minute per IP / visitor ─
            $rateKey = 'msg_' . md5($clientIp . '_' . $visitorToken);
            $rl = \Sahdev\Lib\ChatService::checkRateLimit($rateKey, 'message', 10, 60);
            if (!$rl['allowed']) {
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . $rl['retry_after']);
                echo json_encode([
                    'status'  => 'error',
                    'message' => "You are sending messages too quickly. Please wait {$rl['retry_after']} seconds before sending another message."
                ]);
                exit;
            }

            $messageText = html_entity_decode(trim((string) ($_POST['message'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $sessionUuid = trim((string) ($_POST['session_uuid'] ?? ''));

            if (empty($messageText)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']);
                exit;
            }

            $preview = strlen($messageText) > 60 ? substr($messageText, 0, 60) . '...' : $messageText;
            \Sahdev\Lib\ModuleLogger::info('client_chat', "Received user message: \"{$preview}\" (visitor: " . substr($visitorToken, 0, 8) . "...)");

            $res = \Sahdev\Lib\ChatService::handleClientMessage($visitorToken, $messageText, $clientId, $sessionUuid);
            echo json_encode(array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res));
            exit;
        }

        if ($action === 'client_chat_poll') {
            $sessionUuid = trim((string) ($_REQUEST['session_uuid'] ?? ''));
            $afterId = (int) ($_REQUEST['after_id'] ?? 0);

            if (empty($sessionUuid)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing session_uuid.']);
                exit;
            }

            // ── Active Rate Limiting: Max 30 polls per minute per session/IP ─
            $rateKey = 'poll_' . md5($clientIp . '_' . $sessionUuid);
            $rl = \Sahdev\Lib\ChatService::checkRateLimit($rateKey, 'poll', 30, 60);
            if (!$rl['allowed']) {
                header('HTTP/1.1 429 Too Many Requests');
                echo json_encode(['status' => 'error', 'message' => 'Polling rate exceeded.']);
                exit;
            }

            $res = \Sahdev\Lib\ChatService::pollSessionMessages($sessionUuid, $visitorToken, $clientId, $afterId);
            echo json_encode(array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res));
            exit;
        }

        if ($action === 'client_chat_escalate') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('HTTP/1.1 405 Method Not Allowed');
                echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method. POST required.']);
                exit;
            }

            $sessionUuid = trim((string) ($_POST['session_uuid'] ?? ''));
            if (empty($sessionUuid)) {
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['status' => 'error', 'message' => 'Missing session_uuid.']);
                exit;
            }

            // ── Active Rate Limiting: Max 3 ticket escalations per hour per session/IP ─
            $rateKey = 'esc_' . md5($clientIp . '_' . $sessionUuid);
            $rl = \Sahdev\Lib\ChatService::checkRateLimit($rateKey, 'escalate', 3, 3600);
            if (!$rl['allowed']) {
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . $rl['retry_after']);
                echo json_encode([
                    'status'  => 'error',
                    'message' => "Ticket escalation rate limit reached. Please wait before creating another support ticket."
                ]);
                exit;
            }

            $customName = trim((string) ($_POST['name'] ?? ($_POST['client_name'] ?? '')));
            $customEmail = trim((string) ($_POST['email'] ?? ($_POST['client_email'] ?? '')));

            \Sahdev\Lib\ModuleLogger::info('client_chat', "Escalating session {$sessionUuid} to support ticket (client: " . ($clientId ?: 'guest') . ", email: " . ($customEmail ?: 'n/a') . ")");

            $res = \Sahdev\Lib\ChatService::escalateChatToTicket($sessionUuid, $clientId, $visitorToken, 'Support', $customName, $customEmail);
            echo json_encode(array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res));
            exit;
        }

        if ($action === 'client_chat_kb_search') {
            $query = trim((string) ($_REQUEST['query'] ?? ''));
            // Rate limiting: max 60 KB queries per minute per IP
            $rateKey = 'kb_' . md5($clientIp . '_' . $visitorToken);
            $rl = \Sahdev\Lib\ChatService::checkRateLimit($rateKey, 'kb_search', 60, 60);
            if (!$rl['allowed']) {
                header('HTTP/1.1 429 Too Many Requests');
                echo json_encode(['status' => 'error', 'message' => 'Knowledgebase search rate limit exceeded.']);
                exit;
            }

            $res = \Sahdev\Lib\ChatService::getClientKnowledgeBaseArticles($query, 10);
            echo json_encode(array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res));
            exit;
        }

        if ($action === 'client_chat_rate_message') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('HTTP/1.1 405 Method Not Allowed');
                echo json_encode(['status' => 'error', 'message' => 'POST required.']);
                exit;
            }

            // Rate limit: 20 rating events per minute per IP
            $rateKey = 'rate_' . md5($clientIp . '_' . $visitorToken);
            $rl = \Sahdev\Lib\ChatService::checkRateLimit($rateKey, 'rate_msg', 20, 60);
            if (!$rl['allowed']) {
                header('HTTP/1.1 429 Too Many Requests');
                echo json_encode(['status' => 'error', 'message' => 'Rate limit exceeded.']);
                exit;
            }

            $messageId = (int) ($_POST['message_id'] ?? 0);
            $rating = (int) ($_POST['rating'] ?? 0);
            $feedback = trim((string) ($_POST['feedback'] ?? ''));

            if ($messageId <= 0 || !in_array($rating, [1, -1, 0], true)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
                exit;
            }

            $res = \Sahdev\Lib\ChatService::rateChatMessage($messageId, $rating, $feedback, $visitorToken, $clientId);
            echo json_encode(array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res));
            exit;
        }
    } catch (\Throwable $e) {
        \Sahdev\Lib\ModuleLogger::error('client_chat', "Fatal live chat endpoint error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode([
            'status'  => 'error',
            'message' => 'Live chat service encountered a temporary issue. Please try again or open a support ticket.'
        ]);
        exit;
    }
}

// Actions allowed via GET (no ticket/POST needed)
$getAllowedActions = [
    'get_analytics_period', 'get_header_server_widget', 'server_sso',
    'copilot_stream', 'get_metrics_data', 'client_chat_init',
    'client_chat_get_history', 'client_chat_load_session', 'client_chat_poll'
];
$isGetAllowed = in_array($action, $getAllowedActions, true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$isGetAllowed) {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method. POST required.']);
    exit;
}

$allowedActions = [
    'get_analytics_period', 'get_open_payload', 'analyze_open_context', 'get_payload', 'save_response',
    'get_rewrite_payload', 'rewrite_reply', 'auto_analyze', 'analyze_ticket',
    'generate_summary', 'get_summary', 'delete_summary',
    'generate_historical_context', 'get_historical_context', 'delete_historical_context',
    'score_reply', 'delete_audit_entries', 'search_canned_responses', 'generate_canned_template',
    'save_canned_response', 'save_kb_article', 'get_analytics', 'get_ticket_insights',
    'trigger_cron_run', 'get_insights_queue', 'analyze_single_insight', 'test_whmcs_cron_http',
    'run_tools_for_ticket', 'get_tools_ticket_status', 'run_tools_queue', 'get_tools_operations',
    'run_manual_tool', 'autopilot_test_run', 'set_ui_theme', 'get_header_server_widget',
    'server_sso',
    // Sahdev 3.2 Organization Assistant: Copilot, Safe Ops, Chat, Metrics
    'copilot_send_message', 'copilot_stream', 'copilot_execute_op', 'copilot_rollback_op',
    'chat_takeover', 'fetch_openrouter_models', 'get_metrics_data',
    'client_chat_init', 'client_chat_message', 'client_chat_escalate',
    'client_chat_get_history', 'client_chat_load_session', 'client_chat_new_session',
    'client_chat_poll'
];
if (!in_array($action, $allowedActions, true)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['status' => 'error', 'message' => 'Unsupported action.']);
    exit;
}

if ($action === 'server_sso') {
    require_once __DIR__ . '/lib/PermissionService.php';
    if (!\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_SERVER_ACCESS)) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:-apple-system,sans-serif;text-align:center;padding:50px;color:#333;}h2{color:#e53e3e;}</style></head><body><h2>Access Denied</h2><p>Your WHMCS admin role does not have permission to access server control panels.</p><p><a href="javascript:window.close();" style="color:#3182ce;">Close Window</a></p></body></html>';
        exit;
    }

    $serverId = (int) ($_REQUEST['server_id'] ?? ($_GET['server_id'] ?? 0));
    require_once __DIR__ . '/lib/ServerTelemetryService.php';
    \Sahdev\Lib\ServerTelemetryService::performServerSso($serverId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isGetAllowed && !$isClientChatAction) {
    $requestToken = (string) ($_REQUEST['token'] ?? '');
    if ($requestToken !== '' && strpos($requestToken, '<') !== false) {
        // Some callers may accidentally pass the full generate_token("form") HTML.
        if (preg_match('/name=["\']token["\'][^>]*value=["\']([^"\']+)["\']/i', $requestToken, $m)) {
            $requestToken = (string) ($m[1] ?? '');
        } elseif (preg_match('/value=["\']([^"\']+)["\']/i', $requestToken, $m)) {
            $requestToken = (string) ($m[1] ?? '');
        }
    }
    $candidateTokens = [];
    if (!empty($_SESSION['token'])) {
        $candidateTokens[] = (string) $_SESSION['token'];
    }
    if (!empty($_SESSION['tkval'])) {
        $candidateTokens[] = (string) $_SESSION['tkval'];
    }
    if (function_exists('generate_token')) {
        try {
            $generatedToken = (string) generate_token('plain');
            if ($generatedToken !== '') {
                $candidateTokens[] = $generatedToken;
            }
        } catch (\Throwable $e) {
            // Ignore token helper failures and rely on session candidates.
        }
    }
    $candidateTokens = array_values(array_unique(array_filter($candidateTokens, static function ($v) {
        return is_string($v) && $v !== '';
    })));

    $isTokenValid = false;
    foreach ($candidateTokens as $candidate) {
        if (hash_equals($candidate, $requestToken)) {
            $isTokenValid = true;
            break;
        }
    }

    if ($requestToken === '' || !$isTokenValid) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh the page and try again.']);
        exit;
    }
}

$ticketId = (int) ($_REQUEST['ticket_id'] ?? 0);
$userId = (int) ($_POST['userid'] ?? 0);
$tone = strip_tags($_POST['tone'] ?? '');
$instruction = strip_tags($_POST['instruction'] ?? '');
$intensity = (int) ($_POST['intensity'] ?? 3);
$intent = strip_tags($_POST['intent'] ?? 'AUTO');
$technicalContext = $_POST['technical_context'] ?? '';

// Inject dive intensity context if higher than normal
if ($intensity > 3) {
    $intensityLabels = [
        4 => "Deep Dive (Be highly rigorous and exhaustive)",
        5 => "Maximum Intensity (Leave absolutely no stone unturned, hyper-detailed and rigorous analysis)"
    ];
    $label = $intensityLabels[$intensity] ?? "Deep rigorous focus";
    $intensityContext = "DIVE INTENSITY RULE: The admin has requested an intensity level of {$intensity}/5. {$label}. Please ensure your ROOT_CAUSE and INTERNAL_ACTION_PLAN are profoundly detailed and rigorous.";
    $instruction = empty($instruction) ? $intensityContext : $instruction . "\n\n" . $intensityContext;
}

    $ticketNotRequiredActions = [
        'search_canned_responses', 'generate_canned_template', 'save_canned_response', 'save_kb_article', 'delete_audit_entries',
        'get_analytics', 'get_ticket_insights', 'trigger_cron_run', 'get_insights_queue', 'analyze_single_insight',
        'test_whmcs_cron_http', 'run_tools_queue', 'get_tools_operations',
        'get_open_payload', 'analyze_open_context',
        'autopilot_test_run', 'set_ui_theme', 'get_header_server_widget',
        'copilot_send_message', 'copilot_stream', 'copilot_execute_op', 'copilot_rollback_op',
        'chat_takeover', 'fetch_openrouter_models', 'get_metrics_data',
        'client_chat_init', 'client_chat_message', 'client_chat_escalate',
        'client_chat_get_history', 'client_chat_load_session', 'client_chat_new_session',
        'client_chat_poll'
    ];
    if (!$ticketId && !in_array($action, $ticketNotRequiredActions) && !$isGetAllowed) {
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['status' => 'error', 'message' => 'Missing Ticket ID.']);
        exit;
    }

    // Role-Based Access Control (RBAC) Permission Enforcement
    require_once __DIR__ . '/lib/PermissionService.php';
    \Sahdev\Lib\PermissionService::ensureSchema();

    $actionPermissionMap = [
        'get_header_server_widget'    => \Sahdev\Lib\PermissionService::PERM_TELEMETRY_VIEW,
        'analyze_ticket'              => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_payload'                 => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'auto_analyze'                => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_open_payload'            => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'analyze_open_context'        => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'save_response'               => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'score_reply'                 => \Sahdev\Lib\PermissionService::PERM_ANALYTICS_VIEW,
        'get_rewrite_payload'         => \Sahdev\Lib\PermissionService::PERM_REWRITE_REPLY,
        'rewrite_reply'               => \Sahdev\Lib\PermissionService::PERM_REWRITE_REPLY,
        'generate_summary'            => \Sahdev\Lib\PermissionService::PERM_SUMMARIZER,
        'get_summary'                 => \Sahdev\Lib\PermissionService::PERM_SUMMARIZER,
        'delete_summary'              => \Sahdev\Lib\PermissionService::PERM_SUMMARIZER,
        'generate_historical_context' => \Sahdev\Lib\PermissionService::PERM_HISTORICAL_CTX,
        'get_historical_context'      => \Sahdev\Lib\PermissionService::PERM_HISTORICAL_CTX,
        'delete_historical_context'   => \Sahdev\Lib\PermissionService::PERM_HISTORICAL_CTX,
        'search_canned_responses'     => \Sahdev\Lib\PermissionService::PERM_CANNED_KB,
        'generate_canned_template'    => \Sahdev\Lib\PermissionService::PERM_KNOWLEDGE_MANAGE,
        'save_canned_response'        => \Sahdev\Lib\PermissionService::PERM_KNOWLEDGE_MANAGE,
        'save_kb_article'             => \Sahdev\Lib\PermissionService::PERM_KNOWLEDGE_MANAGE,
        'run_tools_for_ticket'        => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'get_tools_ticket_status'     => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'run_tools_queue'             => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'get_tools_operations'        => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'run_manual_tool'             => \Sahdev\Lib\PermissionService::PERM_TOOLS_EXECUTE,
        'autopilot_test_run'          => \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE,
        'trigger_cron_run'            => \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE,
        'test_whmcs_cron_http'        => \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE,
        'analyze_single_insight'      => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_ticket_insights'         => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_insights_queue'          => \Sahdev\Lib\PermissionService::PERM_TICKET_AI,
        'get_analytics'               => \Sahdev\Lib\PermissionService::PERM_ANALYTICS_VIEW,
        'get_analytics_period'        => \Sahdev\Lib\PermissionService::PERM_ANALYTICS_VIEW,
        'delete_audit_entries'        => \Sahdev\Lib\PermissionService::PERM_AUDIT_MANAGE,
        'copilot_send_message'        => \Sahdev\Lib\PermissionService::PERM_COPILOT_USE,
        'copilot_stream'              => \Sahdev\Lib\PermissionService::PERM_COPILOT_USE,
        'copilot_execute_op'          => \Sahdev\Lib\PermissionService::PERM_OPS_EXECUTE,
        'copilot_rollback_op'         => \Sahdev\Lib\PermissionService::PERM_OPS_ROLLBACK,
        'chat_takeover'               => \Sahdev\Lib\PermissionService::PERM_CLIENT_CHAT_MANAGE,
        'fetch_openrouter_models'     => \Sahdev\Lib\PermissionService::PERM_SETTINGS_MANAGE,
        'get_metrics_data'            => \Sahdev\Lib\PermissionService::PERM_METRICS_VIEW,
    ];

    if (isset($actionPermissionMap[$action]) && $adminId) {
        $requiredPerm = $actionPermissionMap[$action];
        if (!\Sahdev\Lib\PermissionService::hasPermission((int) $adminId, $requiredPerm)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode([
                'status' => 'error',
                'message' => "Access Denied: Your WHMCS admin role does not have permission for '{$requiredPerm}'."
            ]);
            exit;
        }
    }

try {
    require_once __DIR__ . '/lib/AIProviderInterface.php';
    require_once __DIR__ . '/lib/GoogleAIProvider.php';
    require_once __DIR__ . '/lib/LMStudioAIProvider.php';
    require_once __DIR__ . '/lib/OpenRouterAIProvider.php';
    require_once __DIR__ . '/lib/TicketDataExtractor.php';
    require_once __DIR__ . '/lib/AdminPreferences.php';
    require_once __DIR__ . '/lib/AIController.php';
    require_once __DIR__ . '/lib/SchemaManager.php';
    require_once __DIR__ . '/lib/SafeOpsService.php';
    require_once __DIR__ . '/lib/ChatService.php';
    require_once __DIR__ . '/lib/MetricsIntelligenceService.php';
    require_once __DIR__ . '/modules/ToolsExecution/ToolsExecutionService.php';

    $forceRegenerate = !empty($_POST['force_regenerate']) && $_POST['force_regenerate'] === 'true';
    $forceFallback = !empty($_POST['force_fallback']) && $_POST['force_fallback'] === 'true';
    $useSummaryRaw = $_POST['use_summary'] ?? '1';
    $useSummary = ($useSummaryRaw === '1' || $useSummaryRaw === 'true' || $useSummaryRaw === 'on' || $useSummaryRaw === true);
    
    $includeHistoryRaw = $_POST['include_historical_context'] ?? '0';
    $includeHistory = ($includeHistoryRaw === '1' || $includeHistoryRaw === 'true' || $includeHistoryRaw === 'on' || $includeHistoryRaw === true);
    $includeToolsRaw = $_POST['include_tools_context'] ?? '1';
    $includeTools = ($includeToolsRaw === '1' || $includeToolsRaw === 'true' || $includeToolsRaw === 'on' || $includeToolsRaw === true);
    $includeAdminNotesRaw = $_POST['include_admin_notes'] ?? '0';
    $includeAdminNotes = ($includeAdminNotesRaw === '1' || $includeAdminNotesRaw === 'true' || $includeAdminNotesRaw === 'on' || $includeAdminNotesRaw === true);

    $postOverrideProviderId = (int) ($_POST['override_provider_id'] ?? 0);
    $postOverrideProviderId = $postOverrideProviderId > 0 ? $postOverrideProviderId : null;

    // Auto-migration for overwrites without reactivation, specifically for AJAX calls
    try {
        \WHMCS\Database\Capsule::table('tblsahdev_providers')->first();
        \WHMCS\Database\Capsule::table('tblsahdev_settings')->select('primary_provider_id')->first();
        \WHMCS\Database\Capsule::table('tblsahdev_client_context_cache')->first();
    } catch (\Exception $e) {
        require_once __DIR__ . '/sahdev.php';
        if (function_exists('sahdev_activate')) {
            sahdev_activate();
        }
    }

    \Sahdev\Lib\AdminPreferences::ensureSchema();
    $settingsRow = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
    $settingsArray = $settingsRow ? (array) $settingsRow : [];

    $overrideProviderId = \Sahdev\Lib\AdminPreferences::mergeEffectiveOverride(
        $postOverrideProviderId,
        (int) $adminId,
        $settingsArray
    );

    $skipPrefGuardActions = [
        'copilot_send_message', 'copilot_stream', 'copilot_execute_op', 'copilot_rollback_op',
        'chat_takeover', 'fetch_openrouter_models', 'get_metrics_data',
        'client_chat_init', 'client_chat_message', 'client_chat_escalate',
        'client_chat_get_history', 'client_chat_load_session', 'client_chat_new_session'
    ];
    $actionMap = \Sahdev\Lib\AdminPreferences::actionFeatureMap();
    if (!in_array($action, \Sahdev\Lib\AdminPreferences::unguardedActions(), true) && !in_array($action, $skipPrefGuardActions, true)) {
        $featureKey = $actionMap[$action] ?? \Sahdev\Lib\AdminPreferences::FEATURE_TICKET_AI;
        try {
            \Sahdev\Lib\AdminPreferences::assertFeatureOrThrow($featureKey, (int) $adminId, $settingsRow);
        } catch (\RuntimeException $e) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    $controller = new \Sahdev\Lib\AIController($ticketId, $adminId);

    if ($action === 'get_analytics_period') {
        // AJAX: return period-filtered KPI data for the analytics dashboard
        require_once __DIR__ . '/controllers/AdminController.php';
        $vars = ['modulelink' => 'addonmodules.php?module=sahdev'];
        $adminCtrl = new \Sahdev\Controllers\AdminController($vars);
        echo $adminCtrl->analytics_data();
        exit;
    } elseif ($action === 'get_open_payload') {
        if ($userId <= 0) {
            throw new \Exception('Missing or invalid userid.');
        }
        $response = $controller->getOpenContextPayload($userId, $tone, $instruction, $forceRegenerate, $intent, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'analyze_open_context') {
        if ($userId <= 0) {
            throw new \Exception('Missing or invalid userid.');
        }
        $response = $controller->getOpenContextAnalysis($userId, $tone, $instruction, $forceRegenerate, false, $intent, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'get_payload') {
        $response = $controller->getPayload($tone, $instruction, $forceRegenerate, $intent, $useSummary, $includeHistory, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'save_response') {
        $hashSignature = $_POST['hash_signature'] ?? '';
        $aiResponseRaw = $_POST['ai_response'] ?? '{}';
        $tokenUsage = (int) ($_POST['token_usage'] ?? 0);
        $execTime = (int) ($_POST['exec_time'] ?? 0);
        $tokenDetails = $_POST['token_details'] ?? [];
        if (!is_array($tokenDetails)) {
            $tokenDetails = json_decode($tokenDetails, true) ?: [];
        }

        $aiResponseStr = trim($aiResponseRaw);
        // Decode base64 if it's not starting with a JSON brace
        if (!empty($aiResponseStr) && !str_starts_with($aiResponseStr, '{') && !str_starts_with($aiResponseStr, '[')) {
            $decoded = base64_decode($aiResponseStr);
            if ($decoded !== false) {
                $aiResponseStr = $decoded;
            }
        }

        $aiResponse = json_decode($aiResponseStr, true);
        if (!$aiResponse) {
            $err = json_last_error_msg();
            throw new \Exception("Invalid JSON response payload provided. Error: {$err} | Raw: " . substr($aiResponseStr, 0, 800));
        }

        $response = $controller->saveResponse($hashSignature, $aiResponse, $tokenUsage, $execTime, $tokenDetails);
    } elseif ($action === 'get_rewrite_payload') {
        // Returns provider info + rewrite prompt so browser can call LM Studio directly
        $draftText = $_POST['draft_text'] ?? '';
        $response = $controller->getRewritePayload($draftText, $tone ?: 'Professional', $instruction);
    } elseif ($action === 'rewrite_reply') {
        // Server-side rewrite — used for Google providers only
        $draftText = $_POST['draft_text'] ?? '';
        $response = $controller->rewriteReply($draftText, $tone ?: 'Professional', $instruction);
    } elseif ($action === 'auto_analyze') {
        // Feature: Auto-load AI Snapshot on ticket page load (always cached-first, no rate limit penalty on hit)
        $response = $controller->getAnalysis($tone, $instruction, false, false, $intent, $useSummary, $includeHistory, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    } elseif ($action === 'generate_summary') {
        // Feature: AI Ticket Summarizer — generate and save a condensed summary
        $response = $controller->generateSummary();
    } elseif ($action === 'get_summary') {
        // Feature: AI Ticket Summarizer — fetch existing summary for a ticket
        $summary = $controller->getSummary();
        $response = $summary
            ? ['status' => 'success', 'summary' => $summary]
            : ['status' => 'not_found', 'summary' => null];
    } elseif ($action === 'delete_summary') {
        // Feature: AI Ticket Summarizer — delete saved summary
        $response = $controller->deleteSummary();
    } elseif ($action === 'generate_historical_context') {
        $limit = (int) ($_POST['limit'] ?? 7);
        $response = $controller->generateHistoricalContext($limit);
    } elseif ($action === 'get_historical_context') {
        $context = $controller->getHistoricalContext();
        $response = $context
            ? ['status' => 'success', 'historical_context' => $context]
            : ['status' => 'not_found', 'historical_context' => null];
    } elseif ($action === 'delete_historical_context') {
        $response = $controller->deleteHistoricalContext();
    } elseif ($action === 'score_reply') {
        // Feature: Response Quality Scorer
        $replyText = $_POST['reply_text'] ?? '';
        $isAiGenerated = !empty($_POST['is_ai_generated']) && $_POST['is_ai_generated'] === 'true';
        $response = $controller->scoreReply($replyText, $isAiGenerated);
    } elseif ($action === 'delete_audit_entries') {
        // Feature: AI Audit Trail — delete entries older than X days via AJAX
        $days = (int) ($_POST['days'] ?? 30);
        if ($days > 0) {
            $cutoffDate = \Carbon\Carbon::now()->subDays($days);
            $deleted = \WHMCS\Database\Capsule::table('tblsahdev_audit_trail')
                ->where('created_at', '<', $cutoffDate)
                ->delete();
            $response = ['status' => 'success', 'message' => "Deleted {$deleted} audit entries.", 'deleted_count' => $deleted];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid days parameter.'];
        }
    } elseif ($action === 'search_canned_responses') {
        // Feature: Canned Response Generator — search combinations of tables
        $query = $_POST['query'] ?? '';
        $response = $controller->searchCannedResponses($query);
    } elseif ($action === 'generate_canned_template') {
        // Feature: Canned Response Generator — turn a draft into a general template
        $draftText = $_POST['draft_text'] ?? '';
        $response = $controller->generateCannedTemplate($draftText);
    } elseif ($action === 'save_canned_response') {
        // Feature: Canned Response Generator — save generated template into the DB
        $title = $_POST['title'] ?? '';
        $templateText = $_POST['template_text'] ?? '';
        $response = $controller->saveCannedResponse($title, $templateText);
    } elseif ($action === 'save_kb_article') {
        // Feature: Canned Response Generator — save generated template as a KB article
        $title = $_POST['title'] ?? '';
        $templateText = $_POST['template_text'] ?? '';
        $response = $controller->saveKbArticle($title, $templateText);
    } elseif ($action === 'get_analytics') {
        // Feature 8: AI Performance Analytics
        $response = $controller->getAnalyticsData();
    } elseif ($action === 'get_ticket_insights') {
        // Ticket Insights: fetch bulk sentiment/urgency data for ticket list badges
        $rawIds = $_POST['ticket_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = json_decode($rawIds, true) ?: [];
        }
        $rawTids = $_POST['ticket_tids'] ?? [];
        if (!is_array($rawTids)) {
            $rawTids = json_decode($rawTids, true) ?: [];
        }

        $ticketIds = array_map('intval', array_filter($rawIds));
        $maskToId  = [];

        if (!empty($rawTids)) {
            $masks = array_unique(array_filter(array_map('trim', array_map('strval', $rawTids))));
            if (!empty($masks)) {
                $pairs = \WHMCS\Database\Capsule::table('tbltickets')
                    ->whereIn('tid', $masks)
                    ->get(['id', 'tid']);
                foreach ($pairs as $p) {
                    $maskToId[(string) $p->tid] = (int) $p->id;
                    $ticketIds[]               = (int) $p->id;
                }
            }
        }

        $ticketIds = array_values(array_unique(array_filter($ticketIds)));

        if (empty($ticketIds)) {
            $response = [
                'status'     => 'success',
                'insights'   => [],
                'mask_to_id' => new \stdClass(),
            ];
        } else {
            require_once __DIR__ . '/lib/WhmcsTicketTagHelper.php';

            $selectCols = [
                'ticket_id', 'score', 'label', 'urgency', 'client_tone',
                'ticket_summary', 'admin_reply_count', 'last_admin_name', 'analyzed_at',
            ];
            if (\WHMCS\Database\Capsule::schema()->hasColumn('tblsahdev_sentiment', 'ai_tags_json')) {
                $selectCols[] = 'ai_tags_json';
            }

            $rows = \WHMCS\Database\Capsule::table('tblsahdev_sentiment')
                ->whereIn('ticket_id', $ticketIds)
                ->get($selectCols);

            $needBulk = [];
            $insights = [];
            foreach ($rows as $row) {
                $tid = (int) $row->ticket_id;
                $tags = [];
                if (!empty($row->ai_tags_json)) {
                    $decoded = json_decode($row->ai_tags_json, true);
                    if (is_array($decoded)) {
                        $tags = array_values(array_filter($decoded));
                    }
                }
                if ($tags === []) {
                    $needBulk[] = $tid;
                }

                $insights[$tid] = [
                    'sentiment_score'    => (int) $row->score,
                    'sentiment_label'    => $row->label,
                    'urgency'            => $row->urgency,
                    'client_tone'        => $row->client_tone,
                    'ticket_summary'     => $row->ticket_summary,
                    'admin_reply_count'  => (int) $row->admin_reply_count,
                    'last_admin_name'    => $row->last_admin_name,
                    'analyzed_at'        => $row->analyzed_at,
                    'tags'               => $tags,
                ];
            }

            $needBulk = array_values(array_unique($needBulk));
            if ($needBulk !== []) {
                $bulk = \Sahdev\Lib\WhmcsTicketTagHelper::getTagsForTickets($needBulk);
                foreach ($needBulk as $tid) {
                    if (!empty($insights[$tid]['tags']) || !isset($insights[$tid])) {
                        continue;
                    }
                    if (!empty($bulk[$tid])) {
                        $insights[$tid]['tags'] = $bulk[$tid];
                    }
                }
            }
            $response = [
                'status'     => 'success',
                'insights'   => $insights,
                'mask_to_id' => empty($maskToId) ? new \stdClass() : $maskToId,
            ];
        }
    } elseif ($action === 'get_insights_queue') {
        // Returns the list of ticket IDs that need analysis (used by the incremental manual trigger)
        require_once __DIR__ . '/lib/AIProviderInterface.php';
        require_once __DIR__ . '/lib/GoogleAIProvider.php';
        require_once __DIR__ . '/lib/LMStudioAIProvider.php';
        require_once __DIR__ . '/lib/ReplicateAIProvider.php';
        require_once __DIR__ . '/lib/TicketDataExtractor.php';
        require_once __DIR__ . '/lib/AIController.php';
        require_once __DIR__ . '/lib/CronProcessor.php';

        $processor = new \Sahdev\Lib\CronProcessor();
        $queue     = $processor->getQueue();
        $response  = ['status' => 'success', 'queue' => $queue, 'total' => count($queue)];

    } elseif ($action === 'analyze_single_insight') {
        // Processes ONE ticket for insights — called repeatedly by the incremental JS loop
        $singleTicketId = (int) ($_POST['ticket_id'] ?? 0);
        if (!$singleTicketId) {
            $response = ['status' => 'error', 'message' => 'Missing ticket_id'];
        } else {
            require_once __DIR__ . '/lib/AIProviderInterface.php';
            require_once __DIR__ . '/lib/GoogleAIProvider.php';
            require_once __DIR__ . '/lib/LMStudioAIProvider.php';
            require_once __DIR__ . '/lib/ReplicateAIProvider.php';
            require_once __DIR__ . '/lib/TicketDataExtractor.php';
            require_once __DIR__ . '/lib/AIController.php';
            require_once __DIR__ . '/lib/CronProcessor.php';

            // Give a single ticket plenty of time (Replicate can be slow)
            @set_time_limit(180);
            @ignore_user_abort(true);

            $processor = new \Sahdev\Lib\CronProcessor();
            try {
                $processor->analyzeTicket($singleTicketId);
                $response = ['status' => 'success', 'ticket_id' => $singleTicketId];
            } catch (\Throwable $e) {
                $response = ['status' => 'error', 'ticket_id' => $singleTicketId, 'message' => $e->getMessage()];
            }
        }

    } elseif ($action === 'test_whmcs_cron_http') {
        // Admin-only: GET the configured WHMCS cron.php over HTTP for debugging (may run full WHMCS cron).
        require_once __DIR__ . '/lib/CronUrlHelper.php';

        $posted = trim((string) ($_POST['test_url'] ?? ''));
        $row    = \WHMCS\Database\Capsule::table('tblsahdev_settings')->first();
        $saved  = trim((string) ($row->insights_cron_http_url ?? ''));
        $url    = $posted !== '' ? $posted : \Sahdev\Lib\CronUrlHelper::effectiveHttpUrl($saved ?: null);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $response = [
                'status'  => 'error',
                'message' => 'No valid HTTP(S) URL. Enter a full URL (include https:// and any token query string WHMCS requires), or save a default under Ticket Insights settings.',
            ];
        } else {
            $t0 = microtime(true);
            $body = false;
            $code = 0;
            $err  = '';

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_CONNECTTIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT      => 'SahdevAddon/WHMCS-Cron-Test',
                ]);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = (string) curl_error($ch);
                curl_close($ch);
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'timeout'         => 120,
                        'follow_location' => 1,
                        'user_agent'      => 'SahdevAddon/WHMCS-Cron-Test',
                    ],
                    'ssl'  => [
                        'verify_peer'      => true,
                        'verify_peer_name' => true,
                    ],
                ]);
                $body = @file_get_contents($url, false, $ctx);
                if ($body === false) {
                    $err = 'file_get_contents failed (enable curl or check allow_url_fopen)';
                } else {
                    $code = 200;
                    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                        $code = (int) $m[1];
                    }
                }
            }

            $ms      = (int) round((microtime(true) - $t0) * 1000);
            $rawLen  = is_string($body) ? strlen($body) : 0;
            $preview = is_string($body) ? mb_substr(trim(strip_tags($body)), 0, 2000) : '';

            $response = [
                'status'       => ($err !== '' && $body === false) ? 'error' : 'success',
                'message'      => $err !== '' ? $err : 'Request finished.',
                'url_tested'   => $url,
                'http_code'    => $code,
                'duration_ms'  => $ms,
                'bytes'        => $rawLen,
                'body_preview' => $preview,
                'curl_error'   => $err,
            ];
        }

    } elseif ($action === 'trigger_cron_run') {
        // Manually trigger full background cron suite from admin UI
        @set_time_limit(600);
        @ignore_user_abort(true);
        require_once __DIR__ . '/lib/AIProviderInterface.php';
        require_once __DIR__ . '/lib/GoogleAIProvider.php';
        require_once __DIR__ . '/lib/LMStudioAIProvider.php';
        require_once __DIR__ . '/lib/ReplicateAIProvider.php';
        require_once __DIR__ . '/lib/TicketDataExtractor.php';
        require_once __DIR__ . '/lib/AIController.php';
        require_once __DIR__ . '/lib/CronProcessor.php';
        require_once __DIR__ . '/lib/ServerTelemetryService.php';
        require_once __DIR__ . '/lib/IncidentDetectionService.php';
        require_once __DIR__ . '/modules/ToolsExecution/ToolsExecutionService.php';

        $cronSummary = [
            'telemetry' => null,
            'incidents' => null,
            'insights'  => null,
            'tools'     => null,
            'errors'    => [],
        ];

        try {
            $cronSummary['telemetry'] = \Sahdev\Lib\ServerTelemetryService::pollActiveServers(true);
        } catch (\Throwable $te) {
            $cronSummary['errors'][] = 'Telemetry error: ' . $te->getMessage();
        }

        try {
            $cronSummary['incidents'] = \Sahdev\Lib\IncidentDetectionService::evaluateClusters();
        } catch (\Throwable $ie) {
            $cronSummary['errors'][] = 'Incident clustering error: ' . $ie->getMessage();
        }

        try {
            $processor = new \Sahdev\Lib\CronProcessor();
            $cronSummary['insights'] = $processor->run(true);
        } catch (\Throwable $pe) {
            $cronSummary['errors'][] = 'Insights error: ' . $pe->getMessage();
        }

        try {
            $cronSummary['tools'] = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runCron(true);
        } catch (\Throwable $txe) {
            $cronSummary['errors'][] = 'Tools execution error: ' . $txe->getMessage();
        }

        // Record successful heartbeat after manual run
        try {
            \WHMCS\Database\Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'last_cron_success' => \Carbon\Carbon::now(),
                'updated_at'        => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {}

        $insightsResult = $cronSummary['insights'] ?? [];
        $analyzed = $insightsResult['analyzed'] ?? 0;
        $found = $insightsResult['tickets_found'] ?? 0;
        $telemetryResult = $cronSummary['telemetry'] ?? [];
        $polledServers = $telemetryResult['polled'] ?? 0;

        $msgParts = [];
        if ($polledServers > 0) {
            $msgParts[] = "Polled {$polledServers} server(s)";
        }
        if ($analyzed > 0) {
            $msgParts[] = "analyzed {$analyzed} of {$found} ticket(s)";
        } else {
            $msgParts[] = "0 tickets queued";
        }

        $msg = "Cron run complete! " . implode(', ', $msgParts) . ".";
        if (!empty($cronSummary['errors'])) {
            $msg .= ' Warnings: ' . implode(' | ', $cronSummary['errors']);
        }

        $response = ['status' => 'success', 'message' => $msg, 'result' => $cronSummary];
    } elseif ($action === 'run_tools_for_ticket') {
        $runTicketId = (int) ($_POST['ticket_id'] ?? 0);
        $force = !empty($_POST['force']) && $_POST['force'] === '1';
        if ($runTicketId <= 0) {
            $response = ['status' => 'error', 'message' => 'Missing ticket_id'];
        } else {
            $result = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runTicket($runTicketId, (int) $adminId, $force);
            $summary = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::getLatestRunSummary($runTicketId);
            $response = ['status' => 'success', 'result' => $result, 'summary' => $summary];
        }
    } elseif ($action === 'get_tools_ticket_status') {
        $runTicketId = (int) ($_POST['ticket_id'] ?? 0);
        if ($runTicketId <= 0) {
            $response = ['status' => 'error', 'message' => 'Missing ticket_id'];
        } else {
            $summary = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::getLatestRunSummary($runTicketId);
            $response = ['status' => 'success', 'summary' => $summary];
        }
    } elseif ($action === 'run_tools_queue') {
        $result = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runCron(true);
        $response = ['status' => 'success', 'result' => $result];
    } elseif ($action === 'get_tools_operations') {
        $ops = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::listOperations();
        $smart = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::inferSmartValues((int) $ticketId, (int) $adminId);
        $response = ['status' => 'success', 'operations' => $ops, 'smart' => $smart];
    } elseif ($action === 'run_manual_tool') {
        $runTicketId = (int) ($_POST['ticket_id'] ?? 0);
        $method = (string) ($_POST['method'] ?? 'GET');
        $path = html_entity_decode((string) ($_POST['path'] ?? ''), ENT_QUOTES);
        $pathParamsRaw = $_POST['path_params'] ?? '{}';
        if (is_string($pathParamsRaw)) $pathParamsRaw = html_entity_decode($pathParamsRaw, ENT_QUOTES);
        $queryRaw = $_POST['query'] ?? '{}';
        if (is_string($queryRaw)) $queryRaw = html_entity_decode($queryRaw, ENT_QUOTES);
        $bodyRaw = $_POST['body'] ?? '{}';
        if (is_string($bodyRaw)) $bodyRaw = html_entity_decode($bodyRaw, ENT_QUOTES);

        $pathParams = is_array($pathParamsRaw) ? $pathParamsRaw : (json_decode($pathParamsRaw, true) ?: []);
        $query = is_array($queryRaw) ? $queryRaw : (json_decode($queryRaw, true) ?: []);
        $body = is_array($bodyRaw) ? $bodyRaw : (json_decode($bodyRaw, true) ?: []);

        // Debug trace: Log what the backend received
        try {
            \WHMCS\Database\Capsule::table('tblsahdev_module_logs')->insert([
                'level' => 'debug',
                'source' => 'AJAX::run_manual_tool',
                'message' => sprintf('Manual Tool Request Trace: ticket=%d, path=%s, params=%s', $runTicketId, $path, (string)$pathParamsRaw),
                'ticket_id' => $runTicketId,
                'created_at' => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {}

        $manual = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::runManualTool($runTicketId, (int) $adminId, $method, $path, $pathParams, $query, $body);
        $summary = \Sahdev\Modules\ToolsExecution\ToolsExecutionService::getLatestRunSummary($runTicketId);
        $response = ['status' => 'success', 'result' => $manual, 'summary' => $summary];
    } elseif ($action === 'autopilot_test_run') {
        // ── Manual autopilot test for a specific ticket ──────────────────────
        require_once __DIR__ . '/lib/AutopilotProcessor.php';
        require_once __DIR__ . '/lib/WhmcsTicketTagHelper.php';
        require_once __DIR__ . '/lib/CronProcessor.php';

        $forceTicketId = (int) ($_POST['force_ticket_id'] ?? 0);
        if ($forceTicketId <= 0) {
            $response = ['status' => 'error', 'message' => 'No ticket ID provided for test run.'];
        } else {
            // Build provider the same way CronProcessor does
            $taskPrimary = \Sahdev\Lib\TaskProviderResolver::resolveProviderId(
                \Sahdev\Lib\TaskProviderResolver::TASK_AUTOPILOT,
                null,
                $settingsArray
            );
            $apProvRow = \WHMCS\Database\Capsule::table('tblsahdev_providers')
                ->where('id', $taskPrimary)
                ->where('is_active', 1)
                ->first();
            if (!$apProvRow) {
                $apProvRow = \WHMCS\Database\Capsule::table('tblsahdev_providers')
                    ->where('id', $settingsArray['primary_provider_id'])
                    ->first();
            }
            $provSettings = $apProvRow ? array_merge($settingsArray, (array) $apProvRow) : $settingsArray;
            $provType = strtolower($apProvRow->provider_type ?? 'google');
            $apiKey = !empty($apProvRow->api_key) ? decrypt($apProvRow->api_key) : '';

            switch ($provType) {
                case 'lmstudio': $prov = new \Sahdev\Lib\LMStudioAIProvider($apProvRow->api_url ?? '', $apiKey); break;
                case 'replicate': $prov = new \Sahdev\Lib\ReplicateAIProvider($apProvRow->api_url ?? '', $apiKey); break;
                default: $prov = new \Sahdev\Lib\GoogleAIProvider($apiKey);
            }

            $bypassSafety = !empty($_POST['bypass_safety']) && $_POST['bypass_safety'] === '1';
            $autopilot = new \Sahdev\Lib\AutopilotProcessor($settingsArray, $prov, null);
            $response = $autopilot->runForceTicket($forceTicketId, $bypassSafety);
        }
    } elseif ($action === 'set_ui_theme') {
        // Quick-switch UI theme preference (classic / remastered)
        $theme = strtolower(trim((string) ($_POST['theme'] ?? '')));
        $allowed = [\Sahdev\Lib\AdminPreferences::THEME_CLASSIC, \Sahdev\Lib\AdminPreferences::THEME_REMASTERED];
        if (!in_array($theme, $allowed, true)) {
            $response = ['status' => 'error', 'message' => 'Invalid theme value.'];
        } else {
            $prefs = \Sahdev\Lib\AdminPreferences::load((int) $adminId);
            $prefs['ui_theme'] = $theme;
            \Sahdev\Lib\AdminPreferences::save((int) $adminId, $prefs);
            $response = ['status' => 'success', 'theme' => $theme];
        }
    } elseif ($action === 'get_header_server_widget') {
        require_once __DIR__ . '/lib/ServerTelemetryService.php';

        $pollNow = !empty($_REQUEST['poll_now']) && $_REQUEST['poll_now'] == '1';
        try {
            // If pollNow is true, force poll all servers immediately.
            // Otherwise, pollActiveServers(false) automatically checks and polls any stale/unpolled servers respecting the cache TTL!
            \Sahdev\Lib\ServerTelemetryService::pollActiveServers($pollNow);
        } catch (\Throwable $e) {}

        // Determine context server (from service_id or ticket_id)
        $contextServiceId = (int) ($_REQUEST['service_id'] ?? 0);
        $contextTicketId = (int) ($_REQUEST['ticket_id'] ?? 0);
        $contextServerId = 0;
        $contextAccountUsername = '';
        $contextAccountData = null;

        if ($contextServiceId > 0) {
            $hRow = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $contextServiceId)->first();
            if ($hRow) {
                $contextServerId = (int) ($hRow->server ?? 0);
                $contextAccountUsername = (string) ($hRow->username ?? '');
            }
        } elseif ($contextTicketId > 0) {
            $ticketRow = \WHMCS\Database\Capsule::table('tbltickets')->where('id', $contextTicketId)->first();
            if ($ticketRow && !empty($ticketRow->userid)) {
                $hRow = \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('userid', (int) $ticketRow->userid)
                    ->whereIn('domainstatus', ['Active', 'Suspended'])
                    ->orderBy('id', 'desc')
                    ->first();
                if ($hRow) {
                    $contextServerId = (int) ($hRow->server ?? 0);
                    $contextAccountUsername = (string) ($hRow->username ?? '');
                }
            }
        }

        // Fetch active incidents
        $activeIncidents = \WHMCS\Database\Capsule::table('tblsahdev_incidents')
            ->whereIn('status', ['Active', 'Investigating', 'Monitoring'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($inc) {
                return [
                    'id' => (int) $inc->id,
                    'incident_num' => (string) $inc->incident_num,
                    'title' => (string) $inc->title,
                    'severity' => (string) $inc->severity,
                    'status' => (string) $inc->status,
                    'server_id' => (int) ($inc->server_id ?? 0),
                    'server_name' => (string) ($inc->server_name ?? ''),
                    'root_cause' => (string) ($inc->root_cause_summary ?? ''),
                    'detected_at' => (string) $inc->detected_at,
                ];
            })
            ->toArray();

        // Ensure schema is fully migrated before querying
        require_once __DIR__ . '/lib/ServerTelemetryService.php';
        \Sahdev\Lib\ServerTelemetryService::ensureSchema();

        if (!empty($_GET['poll_now'])) {
            try {
                \Sahdev\Lib\ServerTelemetryService::pollActiveServers(true);
            } catch (\Throwable $e) {}
        }

        // Fetch all servers with fallback protection
        try {
            $serversDb = \WHMCS\Database\Capsule::table('tblservers as s')
                ->leftJoin('tblsahdev_server_telemetry as st', 's.id', '=', 'st.server_id')
                ->where('s.disabled', 0)
                ->select(
                    's.id as server_id',
                    's.name as server_name',
                    's.hostname',
                    's.ipaddress',
                    's.type as server_type',
                    's.username',
                    \WHMCS\Database\Capsule::raw('COALESCE(st.server_role, "auto") as server_role'),
                    \WHMCS\Database\Capsule::raw('COALESCE(st.is_monitored, 1) as is_monitored'),
                    'st.server_load',
                    'st.is_reachable',
                    'st.reachability_error',
                    'st.accounts_data_json',
                    'st.server_stats_json',
                    'st.last_polled_at'
                )
                ->orderBy('s.name', 'asc')
                ->get();
        } catch (\Throwable $dbEx) {
            \Sahdev\Lib\ServerTelemetryService::ensureSchema();
            $serversDb = \WHMCS\Database\Capsule::table('tblservers as s')
                ->leftJoin('tblsahdev_server_telemetry as st', 's.id', '=', 'st.server_id')
                ->where('s.disabled', 0)
                ->select(
                    's.id as server_id',
                    's.name as server_name',
                    's.hostname',
                    's.ipaddress',
                    's.type as server_type',
                    's.username',
                    'st.server_load',
                    'st.is_reachable',
                    'st.reachability_error',
                    'st.accounts_data_json',
                    'st.server_stats_json',
                    'st.last_polled_at'
                )
                ->orderBy('s.name', 'asc')
                ->get();
        }

        $serverList = [];
        $totalOutagesCount = 0;
        $totalWarningsCount = 0;
        $reachableCount = 0;
        $monitoredCount = 0;
        $canAccessServer = \Sahdev\Lib\PermissionService::hasPermission((int) $adminId, \Sahdev\Lib\PermissionService::PERM_SERVER_ACCESS);

        foreach ($serversDb as $srv) {
            $sId = (int) $srv->server_id;
            $isMon = !isset($srv->is_monitored) || (int) $srv->is_monitored === 1;
            $isReachable = !empty($srv->is_reachable);
            if ($isMon) $monitoredCount++;
            if ($isReachable) $reachableCount++;

            $statsPayload = !empty($srv->server_stats_json) ? json_decode($srv->server_stats_json, true) : [];
            $serviceOutages = $statsPayload['flagged_service_outages'] ?? [];
            $systemWarnings = $statsPayload['flagged_system_warnings'] ?? [];
            $accountNotices = $statsPayload['flagged_account_notices'] ?? [];

            // Backward compatibility for old payloads
            if (empty($serviceOutages) && empty($systemWarnings) && !empty($statsPayload['flagged_items'])) {
                foreach ($statsPayload['flagged_items'] as $fi) {
                    if (strpos($fi, 'Account') !== false) {
                        $accountNotices[] = $fi;
                    } elseif (strpos($fi, 'DOWN') !== false || strpos($fi, 'unreachable') !== false || strpos($fi, 'Critical') !== false) {
                        $serviceOutages[] = $fi;
                    } else {
                        $systemWarnings[] = $fi;
                    }
                }
            }

            if (!$isReachable) {
                $totalOutagesCount++;
            } else {
                $totalOutagesCount += count($serviceOutages);
                $totalWarningsCount += count($systemWarnings);
            }

            // Role detection
            $curRole = (string) ($srv->server_role ?? 'auto');
            if ($curRole === 'auto' || empty($curRole)) {
                $curRole = \Sahdev\Lib\ServerTelemetryService::detectServerRole($srv);
            }

            // Check if context account is on this server
            $accountInfo = null;
            if ($sId === $contextServerId && $contextAccountUsername !== '') {
                $accounts = !empty($srv->accounts_data_json) ? json_decode($srv->accounts_data_json, true) : [];
                if (is_array($accounts)) {
                    foreach ($accounts as $acct) {
                        if (strtolower($acct['user'] ?? '') === strtolower($contextAccountUsername)) {
                            $accountInfo = $acct;
                            break;
                        }
                    }
                }
            }

            $serverItem = [
                'server_id' => $sId,
                'server_name' => (string) ($srv->server_name ?: 'Server #' . $sId),
                'server_host' => (string) ($srv->hostname ?: $srv->ipaddress),
                'server_type' => (string) ($srv->server_type ?: 'cpanel'),
                'server_role' => $curRole,
                'is_monitored' => $isMon,
                'is_reachable' => $isReachable,
                'reachability_error' => (string) ($srv->reachability_error ?? ''),
                'server_load' => (string) ($srv->server_load ?? 'N/A'),
                'last_polled_at' => !empty($srv->last_polled_at) ? substr((string) $srv->last_polled_at, 0, 16) : 'Never',
                'service_outages' => $serviceOutages,
                'system_warnings' => $systemWarnings,
                'account_notices_count' => count($accountNotices),
                'access_url' => $canAccessServer ? \Sahdev\Lib\ServerTelemetryService::getServerAccessUrl($sId) : '',
                'is_context_pinned' => ($sId === $contextServerId),
                'context_account' => $accountInfo,
            ];

            if ($sId === $contextServerId) {
                // Pin context server to the very top
                array_unshift($serverList, $serverItem);
            } else {
                $serverList[] = $serverItem;
            }
        }

        $overallStatus = 'healthy';
        if ($totalOutagesCount > 0 || count($activeIncidents) > 0 || $reachableCount < $monitoredCount) {
            $overallStatus = 'critical';
        } elseif ($totalWarningsCount > 0) {
            $overallStatus = 'warning';
        }

        $response = [
            'status' => 'success',
            'summary' => [
                'total_servers' => count($serversDb),
                'monitored_servers' => $monitoredCount,
                'reachable_servers' => $reachableCount,
                'total_outages' => $totalOutagesCount,
                'total_warnings' => $totalWarningsCount,
                'active_incidents_count' => count($activeIncidents),
                'overall_status' => $overallStatus,
            ],
            'context_server_id' => $contextServerId,
            'servers' => $serverList,
            'active_incidents' => $activeIncidents,
        ];
    } elseif ($action === 'copilot_send_message') {
        $sessionUuid = trim((string) ($_POST['session_uuid'] ?? ''));
        $userMessageText = trim((string) ($_POST['message'] ?? ''));
        $pageContextRaw = $_POST['page_context'] ?? [];
        $pageContext = is_array($pageContextRaw) ? $pageContextRaw : (json_decode((string) $pageContextRaw, true) ?: []);

        if (empty($userMessageText)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty.']);
            exit;
        }

        $result = \Sahdev\Lib\ChatService::handleAdminMessage((int) $adminId, $sessionUuid, $userMessageText, $pageContext);
        $response = array_merge(['status' => ($result['success'] ?? false) ? 'success' : 'error'], $result);
    } elseif ($action === 'copilot_stream') {
        // Real-time SSE streaming
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        while (ob_get_level()) {
            ob_end_clean();
        }

        $sessionUuid = trim((string) ($_REQUEST['session_uuid'] ?? ''));
        $userMessageText = trim((string) ($_REQUEST['message'] ?? ''));
        $pageContextRaw = $_REQUEST['page_context'] ?? [];
        $pageContext = is_array($pageContextRaw) ? $pageContextRaw : (json_decode((string) $pageContextRaw, true) ?: []);

        if (empty($userMessageText)) {
            echo "data: " . json_encode(['error' => 'Message cannot be empty.', 'done' => true]) . "\n\n";
            exit;
        }

        \Sahdev\Lib\ChatService::streamAdminMessage((int) $adminId, $sessionUuid, $userMessageText, $pageContext, function ($chunk, $meta = []) {
            $payload = json_encode(['chunk' => $chunk, 'meta' => $meta]);
            echo "data: {$payload}\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        });

        echo "data: [DONE]\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        exit;
    } elseif ($action === 'copilot_execute_op') {
        $actionKey = trim((string) ($_POST['action_key'] ?? ''));
        $paramsRaw = $_POST['params'] ?? [];
        $params = is_array($paramsRaw) ? $paramsRaw : (json_decode((string) $paramsRaw, true) ?: []);
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $adminPassword = isset($_POST['admin_password']) ? (string) $_POST['admin_password'] : null;

        if (empty($actionKey)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Missing action_key.']);
            exit;
        }

        $res = \Sahdev\Lib\SafeOpsService::executeConfirmedOperation($actionKey, $params, (int) $adminId, $sessionId, $adminPassword);
        $response = array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res);
    } elseif ($action === 'copilot_rollback_op') {
        $journalId = (int) ($_POST['journal_id'] ?? 0);
        if ($journalId <= 0) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Missing journal_id.']);
            exit;
        }

        $res = \Sahdev\Lib\SafeOpsService::rollbackOperation($journalId, (int) $adminId);
        $response = array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res);
    } elseif ($action === 'chat_takeover') {
        $sessionUuid = trim((string) ($_POST['session_uuid'] ?? ''));
        if (empty($sessionUuid)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['status' => 'error', 'message' => 'Missing session_uuid.']);
            exit;
        }

        $res = \Sahdev\Lib\ChatService::takeoverSession($sessionUuid, (int) $adminId);
        $response = array_merge(['status' => ($res['success'] ?? false) ? 'success' : 'error'], $res);
    } elseif ($action === 'fetch_openrouter_models') {
        $apiKey = trim((string) ($_POST['api_key'] ?? ''));
        if (empty($apiKey)) {
            $apiKey = (string) Capsule::table('tblsahdev_providers')
                ->where('provider_type', 'openrouter')
                ->value('api_key');
        }

        $models = \Sahdev\Lib\OpenRouterAIProvider::fetchModelsFromApi($apiKey);
        $response = [
            'status' => 'success',
            'models' => $models,
            'count'  => count($models),
        ];
    } elseif ($action === 'get_metrics_data') {
        $forceRecalculate = !empty($_REQUEST['force_recalculate']) && ($_REQUEST['force_recalculate'] === 'true' || $_REQUEST['force_recalculate'] === '1');
        $metrics = \Sahdev\Lib\MetricsIntelligenceService::getMetricsSnapshot($forceRecalculate);
        $response = [
            'status'  => 'success',
            'metrics' => $metrics,
        ];
    } else {
        // Default analyze_ticket (server-side generation)
        $response = $controller->getAnalysis($tone, $instruction, $forceRegenerate, $forceFallback, $intent, $useSummary, $includeHistory, $technicalContext, $overrideProviderId, $includeTools, $includeAdminNotes);
    }

    // Clean any prior output to prevent malformed JSON
    if (ob_get_length() !== false) {
        ob_clean();
    }

    echo json_encode($response);

} catch (\Throwable $e) {
    if (ob_get_length() !== false) {
        ob_clean();
    }

    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'message' => 'Sahdev AI Error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
    ]);
}

exit;
