<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use Carbon\Carbon;

/**
 * Class ChatService
 *
 * Hybrid Chat Orchestrator for:
 * 1. Admin Ops Copilot: Interactive command bar, safe WHMCS ops dispatcher, SSE streaming.
 * 2. Client Live Chat: Client-isolated support assistant, KB grounding, live takeover, 1-click ticket escalation.
 */
class ChatService
{
    /**
     * Get or create active Admin Copilot session.
     */
    public static function getOrCreateAdminSession(int $adminId, ?string $sessionUuid = null): array
    {
        SchemaManager::ensureChatSessionsTable();
        SchemaManager::ensureChatMessagesTable();

        if (!empty($sessionUuid)) {
            $session = Capsule::table('tblsahdev_chat_sessions')
                ->where('session_uuid', $sessionUuid)
                ->where('session_type', 'admin_copilot')
                ->where('admin_id', $adminId)
                ->first();

            if ($session) {
                return (array) $session;
            }
        }

        $newUuid = 'copilot_' . bin2hex(random_bytes(16));
        $id = Capsule::table('tblsahdev_chat_sessions')->insertGetId([
            'session_uuid'    => $newUuid,
            'session_type'    => 'admin_copilot',
            'admin_id'        => $adminId,
            'client_id'       => 0,
            'status'          => 'active',
            'title'           => 'Ops Session ' . Carbon::now()->format('M j, g:i a'),
            'last_message_at' => Carbon::now(),
            'created_at'      => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ]);

        return (array) Capsule::table('tblsahdev_chat_sessions')->where('id', $id)->first();
    }

    /**
     * Get recent conversation history for a session.
     */
    public static function getSessionMessages(int $sessionId, int $limit = 50): array
    {
        SchemaManager::ensureChatMessagesTable();
        return Capsule::table('tblsahdev_chat_messages')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get()
            ->map(function ($m) {
                return [
                    'id'               => (int) $m->id,
                    'sender_type'      => $m->sender_type,
                    'sender_name'      => $m->sender_name,
                    'message_text'     => $m->message_text,
                    'action_card'      => !empty($m->action_card_json) ? json_decode($m->action_card_json, true) : null,
                    'tool_calls'       => !empty($m->tool_calls_json) ? json_decode($m->tool_calls_json, true) : null,
                    'created_at'       => $m->created_at,
                ];
            })
            ->toArray();
    }

    /**
     * Handle message sending for Admin Copilot with tool execution and multi-turn resolution.
     */
    public static function handleAdminMessage(int $adminId, string $sessionUuid, string $userMessageText, array $pageContext = []): array
    {
        $session = self::getOrCreateAdminSession($adminId, $sessionUuid);
        $sessionId = (int) $session['id'];

        $adminName = Capsule::table('tbladmins')->where('id', $adminId)->value('username') ?: 'Admin';

        // 1. Record User Message
        Capsule::table('tblsahdev_chat_messages')->insert([
            'session_id'   => $sessionId,
            'sender_type'  => 'user',
            'sender_id'    => $adminId,
            'sender_name'  => $adminName,
            'message_text' => $userMessageText,
            'created_at'   => Carbon::now(),
        ]);

        Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->update([
            'last_message_at' => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ]);

        // 2. Resolve AI Provider
        $provider = self::resolveChatProvider();
        if (!$provider) {
            $botReply = "No active Chat AI Provider is configured. Please configure OpenRouter or Google Gemini in AI Providers > Chat Models.";
            self::recordAssistantMessage($sessionId, $botReply);
            return [
                'success' => true,
                'reply'   => $botReply,
            ];
        }

        // 3. Assemble Prompt & Tools
        $systemPrompt = self::buildAdminSystemPrompt($adminId, $pageContext);
        $chatHistory = self::formatOpenAIMessages($sessionId, $systemPrompt);
        $tools = SafeOpsService::getOpenAIToolsDefinition();

        $settings = [
            'model_name'  => self::getChatSetting('copilot_model_name', 'anthropic/claude-3.5-sonnet'),
            'temperature' => (float) self::getChatSetting('copilot_temperature', 0.70),
            'max_tokens'  => (int) self::getChatSetting('copilot_max_tokens', 2048),
        ];

        // 4. Call Model (using generateChat if provider supports it)
        try {
            $actionCards = [];
            $response = null;

            if (method_exists($provider, 'generateChat')) {
                $response = $provider->generateChat($chatHistory, $tools, $settings);
            } else {
                // Fallback standard text response
                $res = $provider->generateResponse([
                    'subject'     => 'Admin Copilot Request',
                    'client_name' => $adminName,
                    'department'  => 'Ops',
                    'messages'    => [['user' => $adminName, 'message' => $userMessageText]],
                    '__autopilot_raw_reply__' => true,
                ], $settings, 'Professional', $systemPrompt);

                $response = [
                    'content'    => $res['CLIENT_REPLY'] ?? 'No reply generated.',
                    'tool_calls' => [],
                ];
            }

            $assistantContent = $response['content'] ?? '';
            $toolCalls = $response['tool_calls'] ?? [];

            // 5. Handle Tool Calls
            if (!empty($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    $fnName = $tc['function']['name'] ?? '';
                    $fnArgsRaw = $tc['function']['arguments'] ?? '{}';
                    $fnArgs = is_array($fnArgsRaw) ? $fnArgsRaw : json_decode($fnArgsRaw, true);
                    if (!is_array($fnArgs)) $fnArgs = [];

                    $opResult = SafeOpsService::handleOperationRequest($fnName, $fnArgs, $adminId, $sessionId);

                    if (!empty($opResult['is_action_proposal'])) {
                        // Mutating operation requires confirmation card
                        $actionCards[] = $opResult;
                    } elseif (!empty($opResult['success']) && isset($opResult['data'])) {
                        // Read-Only operation executed successfully: Feed back to model for synthesis
                        $chatHistory[] = [
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => [$tc],
                        ];
                        $chatHistory[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $tc['id'] ?? 'call_1',
                            'name'         => $fnName,
                            'content'      => json_encode($opResult['data']),
                        ];

                        // Follow-up completion for natural language explanation
                        if (method_exists($provider, 'generateChat')) {
                            $followUp = $provider->generateChat($chatHistory, [], $settings);
                            if (!empty($followUp['content'])) {
                                $assistantContent .= "\n\n" . $followUp['content'];
                            }
                        }
                    } elseif (!empty($opResult['error'])) {
                        $assistantContent .= "\n\n⚠️ " . $opResult['error'];
                    }
                }
            }

            if (empty($assistantContent) && !empty($actionCards)) {
                $assistantContent = "I've prepared the following WHMCS operation for your review. Please confirm below:";
            }

            // 6. Record Assistant Message in DB
            $msgId = self::recordAssistantMessage($sessionId, $assistantContent, $actionCards, $toolCalls);

            return [
                'success'      => true,
                'message_id'   => $msgId,
                'reply'        => trim($assistantContent),
                'action_cards' => $actionCards,
            ];
        } catch (\Throwable $e) {
            $errorMsg = "Copilot Error: " . $e->getMessage();
            self::recordAssistantMessage($sessionId, $errorMsg);
            return [
                'success' => false,
                'error'   => $errorMsg,
            ];
        }
    }

    /**
     * Real-time SSE streaming for Admin Copilot.
     */
    public static function streamAdminMessage(int $adminId, string $sessionUuid, string $userMessageText, array $pageContext, callable $onChunk): void
    {
        $session = self::getOrCreateAdminSession($adminId, $sessionUuid);
        $sessionId = (int) $session['id'];
        $adminName = Capsule::table('tbladmins')->where('id', $adminId)->value('username') ?: 'Admin';

        // Record User Message
        Capsule::table('tblsahdev_chat_messages')->insert([
            'session_id'   => $sessionId,
            'sender_type'  => 'user',
            'sender_id'    => $adminId,
            'sender_name'  => $adminName,
            'message_text' => $userMessageText,
            'created_at'   => Carbon::now(),
        ]);

        $provider = self::resolveChatProvider();
        if (!$provider || !method_exists($provider, 'generateChatStream')) {
            // Fallback non-streaming
            $result = self::handleAdminMessage($adminId, $sessionUuid, $userMessageText, $pageContext);
            $onChunk($result['reply'] ?? '', ['done' => true, 'action_cards' => $result['action_cards'] ?? []]);
            return;
        }

        $systemPrompt = self::buildAdminSystemPrompt($adminId, $pageContext);
        $chatHistory = self::formatOpenAIMessages($sessionId, $systemPrompt);
        $tools = SafeOpsService::getOpenAIToolsDefinition();

        $settings = [
            'model_name'  => self::getChatSetting('copilot_model_name', 'anthropic/claude-3.5-sonnet'),
            'temperature' => (float) self::getChatSetting('copilot_temperature', 0.70),
            'max_tokens'  => (int) self::getChatSetting('copilot_max_tokens', 2048),
        ];

        try {
            $streamResult = $provider->generateChatStream($chatHistory, $tools, $settings, function ($token, $meta) use ($onChunk) {
                $onChunk($token, $meta);
            });

            $accumulatedContent = $streamResult['content'] ?? '';
            $toolCalls = $streamResult['tool_calls'] ?? [];
            $actionCards = [];

            // Process tool calls if any were emitted
            if (!empty($toolCalls)) {
                foreach ($toolCalls as $tc) {
                    $fnName = $tc['function']['name'] ?? '';
                    $fnArgsRaw = $tc['function']['arguments'] ?? '{}';
                    $fnArgs = is_array($fnArgsRaw) ? $fnArgsRaw : json_decode($fnArgsRaw, true);
                    if (!is_array($fnArgs)) $fnArgs = [];

                    $opResult = SafeOpsService::handleOperationRequest($fnName, $fnArgs, $adminId, $sessionId);

                    if (!empty($opResult['is_action_proposal'])) {
                        $actionCards[] = $opResult;
                    } elseif (!empty($opResult['success']) && isset($opResult['data'])) {
                        // Send tool execution event to client
                        $onChunk("\n\n" . json_encode($opResult['data'], JSON_PRETTY_PRINT), ['type' => 'tool_output', 'function' => $fnName]);
                    }
                }
            }

            // Save assistant message to DB
            self::recordAssistantMessage($sessionId, $accumulatedContent, $actionCards, $toolCalls);

            $onChunk('', ['done' => true, 'action_cards' => $actionCards]);
        } catch (\Throwable $e) {
            $onChunk("Error during stream: " . $e->getMessage(), ['error' => true, 'done' => true]);
        }
    }

    /**
     * Client Live Chat Session Handler.
     */
    public static function getOrCreateClientSession(string $visitorToken, ?int $clientId = null, array $metadata = []): array
    {
        SchemaManager::ensureChatSessionsTable();
        SchemaManager::ensureVisitorTable();

        // Update visitor presence
        Capsule::table('tblsahdev_client_chat_visitors')->updateOrInsert(
            ['visitor_token' => $visitorToken],
            [
                'client_id'    => $clientId ?: 0,
                'visitor_name' => $metadata['name'] ?? null,
                'visitor_email'=> $metadata['email'] ?? null,
                'current_page' => $metadata['page'] ?? null,
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'status'       => 'chatting',
                'last_seen_at' => Carbon::now(),
                'updated_at'   => Carbon::now(),
            ]
        );

        $session = Capsule::table('tblsahdev_chat_sessions')
            ->where('visitor_token', $visitorToken)
            ->where('session_type', 'client_livechat')
            ->whereIn('status', ['active', 'taken_over'])
            ->first();

        if ($session) {
            return (array) $session;
        }

        $uuid = 'chat_' . bin2hex(random_bytes(16));
        $title = !empty($metadata['name']) ? "Chat with {$metadata['name']}" : "Visitor Chat (" . substr($visitorToken, 0, 8) . ")";

        $id = Capsule::table('tblsahdev_chat_sessions')->insertGetId([
            'session_uuid'    => $uuid,
            'session_type'    => 'client_livechat',
            'admin_id'        => 0,
            'client_id'       => $clientId ?: 0,
            'visitor_token'   => $visitorToken,
            'status'          => 'active',
            'title'           => $title,
            'metadata_json'   => json_encode($metadata),
            'last_message_at' => Carbon::now(),
            'created_at'      => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ]);

        return (array) Capsule::table('tblsahdev_chat_sessions')->where('id', $id)->first();
    }

    /**
     * Handle incoming visitor message in Client Live Chat.
     */
    public static function handleClientMessage(string $visitorToken, string $messageText, ?int $clientId = null): array
    {
        $session = self::getOrCreateClientSession($visitorToken, $clientId);
        $sessionId = (int) $session['id'];

        $senderName = $clientId > 0
            ? (Capsule::table('tblclients')->where('id', $clientId)->value('firstname') ?: 'Client')
            : 'Visitor';

        // 1. Record visitor message
        Capsule::table('tblsahdev_chat_messages')->insert([
            'session_id'   => $sessionId,
            'sender_type'  => 'user',
            'sender_id'    => $clientId ?: 0,
            'sender_name'  => $senderName,
            'message_text' => $messageText,
            'created_at'   => Carbon::now(),
        ]);

        Capsule::table('tblsahdev_chat_sessions')->where('id', $sessionId)->update([
            'last_message_at' => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ]);

        // 2. If staff has taken over this chat, do not let AI answer!
        if ($session['status'] === 'taken_over') {
            return [
                'success'       => true,
                'is_takeover'   => true,
                'status'        => 'taken_over',
                'message'       => 'Your message was delivered to our support agent.',
            ];
        }

        // 3. Knowledge Base Grounding & Client Context
        $kbContext = self::searchKnowledgeBase($messageText);
        $clientContext = self::getClientScopeSummary($clientId);

        $systemPrompt = "You are a professional, helpful customer support assistant for a web hosting and cloud services provider.\n"
            . "CRITICAL RULES:\n"
            . "- Answer the customer's question clearly, warmly, and accurately.\n"
            . "- Use ONLY the provided knowledge base articles and customer services context below.\n"
            . "- NEVER reveal internal server hostnames, root passwords, internal admin notes, or pricing not publicly listed.\n"
            . "- If you cannot answer with high certainty, politely suggest opening a support ticket.\n\n"
            . "CLIENT ACCOUNT CONTEXT:\n" . $clientContext . "\n\n"
            . "KNOWLEDGE BASE RESOURCES:\n" . $kbContext;

        $provider = self::resolveChatProvider();
        if (!$provider) {
            $fallback = "Thank you for reaching out! Our team is currently reviewing your message. You can also open a support ticket for immediate assistance.";
            self::recordAssistantMessage($sessionId, $fallback);
            return ['success' => true, 'reply' => $fallback];
        }

        $chatHistory = self::formatOpenAIMessages($sessionId, $systemPrompt);

        $settings = [
            'model_name'  => self::getChatSetting('copilot_model_name', 'openai/gpt-4o-mini'),
            'temperature' => 0.5,
            'max_tokens'  => 1024,
        ];

        try {
            if (method_exists($provider, 'generateChat')) {
                $res = $provider->generateChat($chatHistory, [], $settings);
                $reply = $res['content'] ?? '';
            } else {
                $res = $provider->generateResponse([
                    'subject'     => 'Client Live Chat',
                    'client_name' => $senderName,
                    'department'  => 'Customer Support',
                    'messages'    => [['user' => $senderName, 'message' => $messageText]],
                    '__autopilot_raw_reply__' => true,
                ], $settings, 'Friendly', $systemPrompt);
                $reply = $res['CLIENT_REPLY'] ?? '';
            }

            self::recordAssistantMessage($sessionId, $reply);

            return [
                'success' => true,
                'reply'   => trim($reply),
                'can_escalate' => true,
            ];
        } catch (\Throwable $e) {
            $errReply = "We apologize, our assistant encountered a momentary issue. Would you like to create a support ticket with your inquiry?";
            self::recordAssistantMessage($sessionId, $errReply);
            return [
                'success'      => true,
                'reply'        => $errReply,
                'can_escalate' => true,
            ];
        }
    }

    /**
     * 1-Click Support Ticket Escalation: Converts the full chat transcript into a WHMCS ticket.
     */
    public static function escalateChatToTicket(string $sessionUuid, ?int $clientId = null, string $department = 'Support'): array
    {
        $session = Capsule::table('tblsahdev_chat_sessions')->where('session_uuid', $sessionUuid)->first();
        if (!$session) {
            return ['success' => false, 'error' => "Session not found."];
        }

        $messages = self::getSessionMessages((int) $session->id, 100);
        $transcript = "=== CHAT TRANSCRIPT ESCALATED FROM SAHDEV LIVE CHAT ===\n\n";
        foreach ($messages as $m) {
            $transcript .= "[{$m['created_at']}] {$m['sender_name']} ({$m['sender_type']}):\n{$m['message_text']}\n\n";
        }

        $subject = "Live Chat Escalation: " . ($session->title ?: "Support Inquiry");
        $userId = $clientId ?: (int) $session->client_id;

        // Default dept ID
        $deptId = Capsule::table('tblticketdepartments')->value('id') ?: 1;

        if (function_exists('localAPI') && $userId > 0) {
            $res = localAPI('OpenTicket', [
                'clientid'   => $userId,
                'deptid'     => $deptId,
                'subject'    => $subject,
                'message'    => $transcript,
                'priority'   => 'Medium',
                'admin'      => true,
            ]);

            if ($res['result'] === 'success') {
                $ticketId = $res['id'] ?? 0;
                $tid = $res['tid'] ?? '';
                Capsule::table('tblsahdev_chat_sessions')->where('id', $session->id)->update([
                    'status'     => 'escalated_ticket',
                    'updated_at' => Carbon::now(),
                ]);

                return [
                    'success'   => true,
                    'ticket_id' => $ticketId,
                    'tid'       => $tid,
                    'message'   => "Ticket #{$tid} created successfully.",
                ];
            }
        }

        // Direct database insert fallback
        $tid = rand(100000, 999999);
        $ticketId = Capsule::table('tbltickets')->insertGetId([
            'did'        => $deptId,
            'userid'     => $userId,
            'name'       => 'Live Chat Visitor',
            'email'      => 'visitor@chat.local',
            'date'       => Carbon::now(),
            'title'      => $subject,
            'message'    => $transcript,
            'status'     => 'Open',
            'urgency'    => 'Medium',
            'lastreply'  => Carbon::now(),
            'tid'        => $tid,
        ]);

        Capsule::table('tblsahdev_chat_sessions')->where('id', $session->id)->update([
            'status'     => 'escalated_ticket',
            'updated_at' => Carbon::now(),
        ]);

        return [
            'success'   => true,
            'ticket_id' => $ticketId,
            'tid'       => $tid,
            'message'   => "Support Ticket #{$tid} created successfully.",
        ];
    }

    /**
     * Live Staff Takeover: Admin claims active visitor chat.
     */
    public static function takeoverSession(string $sessionUuid, int $adminId): array
    {
        $adminName = Capsule::table('tbladmins')->where('id', $adminId)->value('username') ?: 'Staff Agent';

        $session = Capsule::table('tblsahdev_chat_sessions')->where('session_uuid', $sessionUuid)->first();
        if (!$session) {
            return ['success' => false, 'error' => "Session not found."];
        }

        Capsule::table('tblsahdev_chat_sessions')->where('id', $session->id)->update([
            'status'            => 'taken_over',
            'assigned_admin_id' => $adminId,
            'updated_at'        => Carbon::now(),
        ]);

        // Post system announcement in chat
        Capsule::table('tblsahdev_chat_messages')->insert([
            'session_id'   => $session->id,
            'sender_type'  => 'system',
            'sender_id'    => $adminId,
            'sender_name'  => 'System',
            'message_text' => "Staff member {$adminName} has joined the conversation to assist you.",
            'created_at'   => Carbon::now(),
        ]);

        return [
            'success'    => true,
            'session_id' => $session->id,
            'admin_name' => $adminName,
        ];
    }

    /**
     * Send staff reply to a visitor in an active takeover session.
     */
    public static function sendStaffReply(string $sessionUuid, int $adminId, string $messageText): array
    {
        $session = Capsule::table('tblsahdev_chat_sessions')->where('session_uuid', $sessionUuid)->first();
        if (!$session) {
            return ['success' => false, 'error' => "Session not found."];
        }

        $adminName = Capsule::table('tbladmins')->where('id', $adminId)->value('username') ?: 'Staff Agent';

        $msgId = Capsule::table('tblsahdev_chat_messages')->insertGetId([
            'session_id'   => $session->id,
            'sender_type'  => 'staff',
            'sender_id'    => $adminId,
            'sender_name'  => $adminName,
            'message_text' => $messageText,
            'created_at'   => Carbon::now(),
        ]);

        Capsule::table('tblsahdev_chat_sessions')->where('id', $session->id)->update([
            'last_message_at' => Carbon::now(),
            'updated_at'      => Carbon::now(),
        ]);

        return [
            'success'    => true,
            'message_id' => $msgId,
            'admin_name' => $adminName,
            'created_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /**
     * Build system prompt for Admin Ops Copilot with live environmental context.
     */
    private static function buildAdminSystemPrompt(int $adminId, array $pageContext = []): string
    {
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first(['username', 'email', 'roleid']);
        $adminName = $admin ? $admin->username : 'Admin';

        $prompt = "You are Sahdev Ops Copilot, an elite AI Assistant engineered specifically for WHMCS web hosting operations.\n"
            . "Current Staff Operator: {$adminName} (Admin ID #{$adminId})\n\n"
            . "CORE CAPABILITIES & RULES:\n"
            . "1. Execute WHMCS safe operations via your registered tools (inspect tickets, lookup clients/invoices/services, suspend/unsuspend, apply credits, etc.).\n"
            . "2. When performing read-only queries (Tier 1), use tools immediately and synthesize direct, concise answers with key metrics formatted in Markdown tables or bullet lists.\n"
            . "3. When requesting mutating actions (Tier 2 or 3), call the corresponding tool so the system can render a visual Action Proposal Card with diff preview for the admin to confirm.\n"
            . "4. Every mutating operation has atomic rollback capability backed by state snapshots.\n"
            . "5. Maintain a professional, executive, highly technical, and concise tone.\n";

        if (!empty($pageContext['ticket_id'])) {
            $prompt .= "\nCURRENT PAGE CONTEXT: Admin is viewing Support Ticket #{$pageContext['ticket_id']}.\n";
        }
        if (!empty($pageContext['client_id'])) {
            $prompt .= "\nCURRENT PAGE CONTEXT: Admin is viewing Client Profile #{$pageContext['client_id']}.\n";
        }
        if (!empty($pageContext['service_id'])) {
            $prompt .= "\nCURRENT PAGE CONTEXT: Admin is viewing Hosting Service #{$pageContext['service_id']}.\n";
        }

        return $prompt;
    }

    /**
     * Format past conversation messages for OpenAI-compatible payload.
     */
    private static function formatOpenAIMessages(int $sessionId, string $systemPrompt): array
    {
        $out = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $rows = Capsule::table('tblsahdev_chat_messages')
            ->where('session_id', $sessionId)
            ->orderBy('id', 'desc')
            ->limit(12)
            ->get()
            ->reverse();

        foreach ($rows as $r) {
            $role = ($r->sender_type === 'user') ? 'user' : 'assistant';
            if (!empty($r->message_text)) {
                $out[] = ['role' => $role, 'content' => $r->message_text];
            }
        }

        return $out;
    }

    private static function recordAssistantMessage(int $sessionId, string $text, array $actionCards = [], array $toolCalls = []): int
    {
        return Capsule::table('tblsahdev_chat_messages')->insertGetId([
            'session_id'        => $sessionId,
            'sender_type'       => 'assistant',
            'sender_id'         => 0,
            'sender_name'       => 'Sahdev AI',
            'message_text'      => $text,
            'action_card_json'  => !empty($actionCards) ? json_encode($actionCards) : null,
            'tool_calls_json'   => !empty($toolCalls) ? json_encode($toolCalls) : null,
            'created_at'        => Carbon::now(),
        ]);
    }

    /**
     * Resolve designated AI Provider for chat operations.
     */
    public static function resolveChatProvider(): ?AIProviderInterface
    {
        $settings = Capsule::table('tblsahdev_settings')->first();
        $providerId = (int) ($settings->copilot_primary_provider_id ?? 0);

        // If no explicit chat provider assigned, check primary provider
        if ($providerId <= 0) {
            $providerId = (int) ($settings->primary_provider_id ?? 0);
        }

        if ($providerId > 0) {
            $pData = Capsule::table('tblsahdev_providers')->where('id', $providerId)->where('is_active', 1)->first();
            if ($pData) {
                return self::instantiateProviderFromRecord($pData);
            }
        }

        // Fallback: search for first active OpenRouter, Google, or LMStudio provider
        $any = Capsule::table('tblsahdev_providers')
            ->where('is_active', 1)
            ->orderByRaw("FIELD(provider_type, 'openrouter', 'google', 'lmstudio')")
            ->first();

        return $any ? self::instantiateProviderFromRecord($any) : null;
    }

    private static function instantiateProviderFromRecord($pData): ?AIProviderInterface
    {
        try {
            $apiKey = !empty($pData->api_key) ? decrypt($pData->api_key) : '';

            if ($pData->provider_type === 'openrouter') {
                require_once __DIR__ . '/OpenRouterAIProvider.php';
                return new OpenRouterAIProvider($apiKey, $pData->api_url ?? '');
            } elseif ($pData->provider_type === 'google') {
                require_once __DIR__ . '/GoogleAIProvider.php';
                return new GoogleAIProvider($apiKey);
            } elseif ($pData->provider_type === 'lmstudio') {
                require_once __DIR__ . '/LMStudioAIProvider.php';
                return new LMStudioAIProvider($pData->api_url ?? '', $apiKey);
            }
        } catch (\Throwable $e) {}

        return null;
    }

    private static function getChatSetting(string $key, $default = null)
    {
        try {
            $val = Capsule::table('tblsahdev_settings')->value($key);
            return $val !== null ? $val : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private static function searchKnowledgeBase(string $query): string
    {
        $out = "";
        try {
            // Search WHMCS Knowledgebase
            $words = array_filter(explode(' ', preg_replace('/[^a-zA-Z0-9\s]/', '', $query)));
            if (!empty($words)) {
                $q = Capsule::table('tblknowledgebase')->where('parentid', '!=', 0);
                $q->where(function ($sub) use ($words) {
                    foreach ($words as $w) {
                        if (strlen($w) > 3) {
                            $sub->orWhere('title', 'LIKE', "%{$w}%")
                                ->orWhere('article', 'LIKE', "%{$w}%");
                        }
                    }
                });
                $articles = $q->limit(3)->get(['title', 'article']);
                foreach ($articles as $a) {
                    $clean = strip_tags($a->article);
                    $out .= "Article: {$a->title}\n" . mb_substr($clean, 0, 500) . "...\n\n";
                }
            }
        } catch (\Throwable $e) {}

        return !empty($out) ? $out : "(No specific KB articles matched.)";
    }

    private static function getClientScopeSummary(?int $clientId): string
    {
        if (!$clientId || $clientId <= 0) {
            return "Visitor is a guest (not logged in).";
        }

        try {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first(['firstname', 'lastname', 'email', 'status', 'credit']);
            if (!$client) return "Client record not found.";

            $services = Capsule::table('tblhosting')
                ->where('userid', $clientId)
                ->get(['domain', 'domainstatus']);

            $svcList = [];
            foreach ($services as $s) {
                $svcList[] = "{$s->domain} ({$s->domainstatus})";
            }

            return "Client Name: {$client->firstname} {$client->lastname}\n"
                . "Account Status: {$client->status}\n"
                . "Active Services: " . (empty($svcList) ? "None" : implode(', ', $svcList));
        } catch (\Throwable $e) {
            return "Client context unavailable.";
        }
    }
}
