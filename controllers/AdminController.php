<?php

namespace Sahdev\Controllers;

use WHMCS\Database\Capsule;
use Sahdev\Lib\TaskProviderResolver;

require_once dirname(__DIR__) . '/lib/TaskProviderResolver.php';
require_once dirname(__DIR__) . '/lib/AdminPreferences.php';

class AdminController
{
    protected $moduleVars;

    public function __construct($vars)
    {
        $this->moduleVars = $vars;
        $this->ensureSchemaIntegrity();
    }

    /**
     * Hot-fixes schema for users updating the module without deactivating/reactivating.
     */
    private function ensureSchemaIntegrity()
    {
        \Sahdev\Lib\AdminPreferences::ensureSchema();

        // 1. Ensure Summaries Table Exists
        try {
            Capsule::table('tblsahdev_summaries')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_summaries', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->integer('admin_id')->unsigned()->index();
                $table->mediumText('summary');
                $table->timestamps();
            });
        }

        // 2. Ensure Audit Trail Table Exists with corrected schema
        try {
            Capsule::table('tblsahdev_audit_trail')->first();
        } catch (\Exception $e) {
            $errMessage = $e->getMessage();
            if (strpos($errMessage, 'Base table or view not found') !== false || strpos($errMessage, 'doesn\'t exist') !== false) {
                Capsule::schema()->create('tblsahdev_audit_trail', function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->integer('admin_id')->unsigned()->index();
                    $table->string('action_type', 64)->nullable();
                    $table->string('provider_used', 64)->nullable();
                    $table->longText('prompt_text')->nullable();
                    $table->longText('response_text')->nullable();
                    $table->integer('tokens_used')->nullable();
                    $table->integer('execution_time_ms')->nullable();
                    $table->timestamp('created_at')->useCurrent();
                });
            }
        }

        // 3. Ensure Quality Scores Table Exists
        try {
            Capsule::table('tblsahdev_quality_scores')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_quality_scores', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->integer('admin_id')->unsigned()->index();
                $table->tinyInteger('score')->unsigned()->default(0);
                $table->tinyInteger('clarity')->unsigned()->nullable();
                $table->tinyInteger('tone_score')->unsigned()->nullable();
                $table->tinyInteger('completeness')->unsigned()->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_ai_generated')->default(1);
                $table->timestamps();
            });
        }
        
        // 4. Ensure Client Context Cache Table Exists for AI Memory
        try {
            Capsule::table('tblsahdev_client_context_cache')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_client_context_cache', function ($table) {
                $table->increments('id');
                $table->integer('ticket_id')->unsigned()->index();
                $table->integer('client_id')->unsigned()->index();
                $table->longText('historical_context');
                $table->timestamps();
            });
        }

        // Ensure execution_time_ms exists in case of an older schema
        try {
            Capsule::table('tblsahdev_audit_trail')->select('execution_time_ms')->first();
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                Capsule::schema()->table('tblsahdev_audit_trail', function ($table) {
                    $table->integer('execution_time_ms')->nullable();
                });
                
                // Safe raw-SQL renames for WHMCS environments lacking Doctrine/DBAL
                try {
                    Capsule::statement("ALTER TABLE tblsahdev_audit_trail CHANGE `action` `action_type` VARCHAR(64) NULL");
                    Capsule::statement("ALTER TABLE tblsahdev_audit_trail CHANGE `prompt` `prompt_text` LONGTEXT NULL");
                    Capsule::statement("ALTER TABLE tblsahdev_audit_trail CHANGE `response` `response_text` LONGTEXT NULL");
                } catch (\Exception $renameEx) {
                    // Ignore if already renamed or other constraint
                }
            }
        }

        // 5. Ensure Prompt Templates Table Exists (Prompt Library)
        try {
            Capsule::table('tblsahdev_prompt_templates')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_prompt_templates', function ($table) {
                $table->increments('id');
                $table->string('prompt_key', 64)->index();
                $table->string('label', 128);
                $table->text('description')->nullable();
                $table->longText('default_content');
                $table->longText('content');
                $table->timestamps();
            });
            // Seed defaults
            $this->seedDefaultPromptTemplates();
        }

        // 6. Ensure Prompt Presets Table Exists
        try {
            Capsule::table('tblsahdev_prompt_presets')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_prompt_presets', function ($table) {
                $table->increments('id');
                $table->string('name', 128);
                $table->longText('prompt_snapshots'); // JSON blob of all prompt keys
                $table->timestamps();
            });
        }

        // 7. Ensure Limits and Cost columns exist
        try {
            Capsule::table('tblsahdev_settings')->select('max_messages')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->integer('max_messages')->default(10);
                $table->integer('max_attachment_chars')->default(5000);
                $table->integer('max_images')->default(3);
            });
        }
        try {
            Capsule::table('tblsahdev_providers')->select('cost_input_1m')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_providers', function ($table) {
                $table->decimal('cost_input_1m', 10, 4)->default(0.0000);
                $table->decimal('cost_output_1m', 10, 4)->default(0.0000);
            });
        }

        // 8. Ensure Intents Table Exists and is Seeded
        try {
            // Check if table exists
            Capsule::table('tblsahdev_intents')->first();
            
            // If it exists but is completely empty, seed it
            if (Capsule::table('tblsahdev_intents')->count() == 0) {
                throw new \Exception("Table empty, needs seeding");
            }
        } catch (\Exception $e) {
            // Create table if it doesn't exist
            if (!Capsule::schema()->hasTable('tblsahdev_intents')) {
                Capsule::schema()->create('tblsahdev_intents', function ($table) {
                    $table->charset = 'utf8mb4';
                    $table->collation = 'utf8mb4_unicode_ci';
                    $table->increments('id');
                    $table->string('intent_key', 32)->unique();
                    $table->string('label', 64);
                    $table->text('directive');
                    $table->boolean('is_active')->default(1);
                    $table->integer('sort_order')->default(0);
                    $table->timestamps();
                });
            }

            // Seed defaults
            Capsule::table('tblsahdev_intents')->insert([
                [
                    'intent_key' => 'AUTO',
                    'label'      => 'Auto (AI Decides)',
                    'directive'  => '', // Handled by AUTO fallback
                    'is_active'  => 1,
                    'sort_order' => 10,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'RESOLVE',
                    'label'      => 'Resolved Query',
                    'directive'  => 'REPLY INTENT — RESOLVED: The admin confirms this issue has been resolved. Write CLIENT_REPLY as a confident closing message. Acknowledge what was fixed, thank the client for their patience, and advise them to reopen the ticket if the issue recurs. Do NOT ask further questions.',
                    'is_active'  => 1,
                    'sort_order' => 20,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'INVESTIGATE',
                    'label'      => 'Checking Query',
                    'directive'  => 'REPLY INTENT — INVESTIGATING: The admin is still actively investigating this issue. Write CLIENT_REPLY to acknowledge the issue empathetically, confirm the support team is actively working on it, and set realistic expectations without making firm time commitments. Keep the client reassured.',
                    'is_active'  => 1,
                    'sort_order' => 30,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'MORE_INFO',
                    'label'      => 'Need More Info',
                    'directive'  => 'REPLY INTENT — NEED MORE INFORMATION: The admin needs additional details before proceeding. Write CLIENT_REPLY to clearly and politely list exactly what specific information, logs, screenshots, credentials, or steps are required from the client. Be precise — avoid vague requests.',
                    'is_active'  => 1,
                    'sort_order' => 40,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'GUIDE',
                    'label'      => 'Guide to Solution',
                    'directive'  => 'REPLY INTENT — GUIDE TO SOLUTION: The admin wants to guide the client to self-resolve. Write CLIENT_REPLY as a clear, step-by-step guide in simple language the client can follow independently. Use numbered steps. Anticipate likely stumbling points and address them proactively.',
                    'is_active'  => 1,
                    'sort_order' => 50,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'OUT_OF_SCOPE',
                    'label'      => 'Out of Scope',
                    'directive'  => 'REPLY INTENT — OUT OF SUPPORT SCOPE: This issue falls outside the support boundaries. Write CLIENT_REPLY to clearly but respectfully explain that this specific issue is not covered under the current support scope or plan. Where applicable, point to relevant resources, documentation, or upgrade options. Be firm yet courteous — avoid leaving the client feeling dismissed.',
                    'is_active'  => 1,
                    'sort_order' => 60,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'DUPLICATE',
                    'label'      => 'Duplicate Ticket',
                    'directive'  => 'REPLY INTENT — DUPLICATE TICKET: This is a duplicate of an existing ticket. Write CLIENT_REPLY to politely inform the client that this appears to be a duplicate of an existing ticket they have already submitted. Instruct them to continue communication on the original ticket to avoid confusion and ensure continuity of support. Close this ticket gracefully.',
                    'is_active'  => 1,
                    'sort_order' => 70,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
                [
                    'intent_key' => 'ABUSE_REPORT',
                    'label'      => 'Abuse Report',
                    'directive'  => 'REPLY INTENT — ABUSE REPORT: The admin is handling abuse, phishing, spam, malware, copyright, or policy reports. Write CLIENT_REPLY as a calm, human, policy-aware message that acknowledges the report, requests missing evidence when needed, outlines next review steps, and sets realistic follow-up expectations. Continue the conversation naturally and avoid abrupt closure.',
                    'is_active'  => 1,
                    'sort_order' => 80,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now()
                ],
            ]);
        }

        // Backward-compatible migration: ensure ABUSE_REPORT exists even on previously seeded installs
        try {
            $abuseExists = Capsule::table('tblsahdev_intents')
                ->where('intent_key', 'ABUSE_REPORT')
                ->exists();
            if (!$abuseExists) {
                Capsule::table('tblsahdev_intents')->insert([
                    'intent_key' => 'ABUSE_REPORT',
                    'label'      => 'Abuse Report',
                    'directive'  => 'REPLY INTENT — ABUSE REPORT: The admin is handling abuse, phishing, spam, malware, copyright, or policy reports. Write CLIENT_REPLY as a calm, human, policy-aware message that acknowledges the report, requests missing evidence when needed, outlines next review steps, and sets realistic follow-up expectations. Continue the conversation naturally and avoid abrupt closure.',
                    'is_active'  => 1,
                    'sort_order' => 80,
                    'created_at' => \Carbon\Carbon::now(),
                    'updated_at' => \Carbon\Carbon::now(),
                ]);
            }
        } catch (\Exception $e) {
            // Keep admin module resilient even if migration attempt fails
        }

        // 9. Ensure missing prompt templates exist (migration for existing installs)
        try {
            $defs = $this->getDefaultPromptDefinitions();
            foreach (['cron_insights_system', 'cron_insights', 'tools_evidence_system', 'tools_evidence_user', 'tools_reply_context_wrapper', 'autopilot_system'] as $pkey) {
                $exists = Capsule::table('tblsahdev_prompt_templates')
                    ->where('prompt_key', $pkey)
                    ->exists();
                if (!$exists && isset($defs[$pkey])) {
                    $def = $defs[$pkey];
                    Capsule::table('tblsahdev_prompt_templates')->insert([
                        'prompt_key'      => $pkey,
                        'label'           => $def['label'],
                        'description'     => $def['description'],
                        'default_content' => $def['content'],
                        'content'         => $def['content'],
                        'created_at'      => \Carbon\Carbon::now(),
                        'updated_at'      => \Carbon\Carbon::now(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Non-fatal
        }

        // 10. Ensure cron insights settings columns exist (migration for existing installs)
        try {
            Capsule::table('tblsahdev_settings')->select('cron_insights_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('cron_insights_enabled')->default(1);
                $table->integer('cron_insights_interval_hours')->default(6);
                $table->integer('cron_insights_max_per_run')->default(20);
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('cron_insights_statuses')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('cron_insights_statuses', 512)->nullable();
            });
        }

        // 11. Ensure expanded tblsahdev_sentiment columns exist (migration for existing installs)
        try {
            Capsule::table('tblsahdev_sentiment')->select('client_tone')->first();
        } catch (\Exception $e) {
            if (Capsule::schema()->hasTable('tblsahdev_sentiment')) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->string('client_tone', 64)->nullable();
                    $table->text('ticket_summary')->nullable();
                    $table->integer('admin_reply_count')->unsigned()->default(0);
                    $table->string('last_admin_name', 128)->nullable();
                    $table->timestamp('ticket_last_reply_at')->nullable();
                    $table->timestamp('analyzed_at')->nullable();
                });
            }
        }
        // Add ticket_last_reply_at for installs that had the older schema without it
        try {
            Capsule::table('tblsahdev_sentiment')->select('ticket_last_reply_at')->first();
        } catch (\Exception $e) {
            if (Capsule::schema()->hasTable('tblsahdev_sentiment')) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->timestamp('ticket_last_reply_at')->nullable();
                });
            }
        }
        try {
            Capsule::table('tblsahdev_sentiment')->select('ai_tags_json')->first();
        } catch (\Exception $e) {
            if (Capsule::schema()->hasTable('tblsahdev_sentiment')) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->text('ai_tags_json')->nullable();
                });
            }
        }
    }

    /**
     * Returns the hardcoded default prompt content for all managed prompt keys.
     * Single source of truth used for seeding and reset operations.
     */
    private function getDefaultPromptDefinitions(): array
    {
        $settings = null;
        try { $settings = Capsule::table('tblsahdev_settings')->first(); } catch(\Exception $e) {}

        $systemDefault = $settings->system_prompt ?? "You are Sahdev, a Senior Technical Support Specialist for a premium web hosting company. Your goal is to provide elite-level support that feels empathetic, technical, and human.\n\nCORE DIRECTIVES:\n1. EMPATHY: Acknowledge the user's frustration or urgency without sounding corporate or robotic.\n2. PRECISION: If a technical issue is identified, explain it clearly and provide actionable insights.\n3. NATURAL FLOW: Use natural transitions. Avoid excessive bullet points or robotic lists.\n4. TONE: Strictly adhere to the requested Tone setting.\n\nAlways analyze the full conversation history to ensure the reply fits the current context perfectly.\n\nOutput only a valid JSON object as requested.";

        $userPromptDefault = $settings->user_prompt_template ?? "{{CUSTOM_INSTRUCTION_BLOCK}}=== TASK ===\nAnalyze the provided technical support ticket and output ONLY a valid JSON object matching the schema below. No extra text.\n\n=== SCHEMA ===\n{\n  \"ROOT_CAUSE\": \"string (brief technical analysis)\",\n  \"RESPONSIBILITY\": \"string (Client, Host, or 3rd Party)\",\n  \"RISK_LEVEL\": \"string (Low, Medium, High, or Critical)\",\n  \"INTERNAL_ACTION_PLAN\": \"string (detailed steps for the support team)\",\n  \"CLIENT_REPLY\": \"string (reply to client in Markdown — body only, no greeting or sign-off)\"\n}\n\n=== TONE ===\nWrite CLIENT_REPLY in a {{TONE}} tone.\n\n=== TICKET DATA ===\nClient: {{CLIENT_NAME}}\nDepartment: {{DEPARTMENT}}\nSubject: {{SUBJECT}}\n{{SERVICES_BLOCK}}\n=== CONVERSATION ===\n{{MESSAGES}}\n{{ATTACHMENTS_BLOCK}}";

        return [
            'system_default' => [
                'label'       => 'System Prompt (AI Persona)',
                'description' => 'The AI identity and core behavioral rules injected as the system instruction on every request. Defines tone, empathy, directives, and output format expectations.',
                'content'     => $systemDefault,
            ],
            'user_prompt_template' => [
                'label'       => 'User Prompt Template (Main Reply)',
                'description' => 'The structured user-turn prompt for ticket analysis. Supports placeholders: {{CUSTOM_INSTRUCTION_BLOCK}}, {{TONE}}, {{CLIENT_NAME}}, {{DEPARTMENT}}, {{SUBJECT}}, {{SERVICES_BLOCK}}, {{MESSAGES}}, {{ATTACHMENTS_BLOCK}}.',
                'content'     => $userPromptDefault,
            ],
            'summarizer' => [
                'label'       => 'Ticket Summarizer Prompt',
                'description' => 'System prompt for the AI when condensing a long ticket conversation into a concise summary (used by the Adaptive Summarizer feature).',
                'content'     => "You are a senior technical support analyst. Your job is to create concise, accurate ticket summaries that capture the essential context: root issue, actions taken, client sentiment, and current status.\n\nSUMMARY RULES:\n- Length: adapt dynamically based on ticket complexity (short tickets → 3-5 lines; complex tickets → 8-12 lines)\n- Include: the original problem, key technical details exchanged, any steps already tried, current status\n- Do NOT include greetings, small talk, or formatting metadata\n- Write in past-tense, third-person, concise prose\n- Output ONLY the summary text. No labels, no JSON, no prefixes.",
            ],
            'historical_context' => [
                'label'       => 'Historical Context Analyst Prompt',
                'description' => 'System prompt for the AI when generating a historical context summary of a client\'s past tickets (AI Memory feature).',
                'content'     => "You are a customer support historian. Analyze the user's past tickets against their current active issue.\nYOUR TASK:\n1. explicitly highlight and summarize any past tickets that are related or similar to the current issue.\n2. briefly group and summarize unrelated tickets just to provide general context on their account health.\nFormat your response purely in Markdown. Do not include JSON. Be concise but helpful for the support agent.",
            ],
            'rewrite_reply' => [
                'label'       => 'Reply Rewriter / Expander Prompt',
                'description' => 'The prompt template used when an admin writes a rough draft and asks Sahdev to expand and polish it into a professional client reply. Supports {{TONE}}, {{SUBJECT}}, {{CLIENT_NAME}}, {{EXTRA_INSTRUCTION}}, {{DRAFT}}.',
                'content'     => "=== TASK ===\nThe admin has written a short rough draft reply for the following support ticket. EXPAND and POLISH it into a complete, fluent, professional client-facing reply.\n\nRULES:\n- Preserve the original intent and any specific instructions in the draft.\n- Do NOT add a greeting (e.g. 'Dear Client') or a sign-off — the signature is handled separately.\n- Write in a **{{TONE}}** tone.\n- Output ONLY the final reply body. No extra commentary, no JSON, no prefixes.\n{{EXTRA_INSTRUCTION}}\n\n=== TICKET CONTEXT ===\nSubject: {{SUBJECT}}\nClient: {{CLIENT_NAME}}\n\n=== ADMIN DRAFT ===\n{{DRAFT}}\n\n=== POLISHED REPLY (output only) ===",
            ],
            'score_reply' => [
                'label'       => 'Reply Quality Scorer System Prompt',
                'description' => 'System prompt used when the AI evaluates a support reply for quality scoring (Clarity, Tone, Completeness).',
                'content'     => "You are an expert QA Manager scoring support replies. Output strictly a single raw JSON object matching the requested schema.",
            ],
            'canned_template' => [
                'label'       => 'Canned Response Generator Prompt',
                'description' => 'Prompt used when converting a specific reply into a reusable, generalized canned response template.',
                'content'     => "Rewrite the following support ticket reply into a reusable, generalized canned response template.\n- Remove any specific client names, domain names, IP addresses, or highly specific dates.\n- Replace removed specifics with general placeholders like [Client Name], [Domain], [IP Address].\n- Make the tone professional and helpful.\n- DO NOT include any JSON wrapping or preamble, just the raw text template.\n\n=== DRAFT TO GENERALIZE ===\n{{DRAFT}}",
            ],
            'cron_insights_system' => [
                'label'       => 'Ticket Insights — System (AI persona)',
                'description' => 'System instruction for the cron / batch ticket insights job. Shapes tone and depth of the JSON analysis. Editable like other prompts; paired with “Ticket Insights (Cron) — User prompt”.',
                'content'     => "You are a senior support lead triaging hosting and billing tickets for an internal team. Your job is to read the conversation and output one JSON object only — no markdown fences, no preamble, no explanation outside JSON.\n\nHow to write TICKET_SUMMARY:\n- Sound like a real handoff from an experienced tech: concrete, plain language, specific facts (product, error text, deadlines, who is waiting on what).\n- Aim for 3–6 sentences when the ticket has real substance; shorter for trivial threads.\n- Avoid generic AI phrasing: do not use filler such as \"It is important to note\", \"The client is seeking assistance\", \"It appears that\", \"Overall, the conversation indicates\", or hollow signposting.\n- Prefer short, information-dense sentences. If something is unclear, say what is unknown and what would confirm it — do not invent details.\n\nTAGS must align with the substance of the thread (same themes you would mention to a colleague), using only the ai-* slug format described in the user message.\n\nAll JSON keys required by the user message must be present and valid.",
            ],
            'cron_insights' => [
                'label'       => 'Ticket Insights (Cron) — User prompt',
                'description' => 'User-turn prompt for the background cron that analyzes tickets (sentiment, urgency, tone, TICKET_SUMMARY, TAGS for WHMCS Tag Cloud). Placeholders: {{CLIENT_NAME}}, {{DEPARTMENT}}, {{SUBJECT}}, {{MESSAGES}}. Pair with “Ticket Insights — System”.',
                'content'     => "=== TASK ===\nAnalyze the support ticket conversation below and output ONLY a valid JSON object exactly matching this schema. No extra text.\n\n=== SCHEMA ===\n{\n  \"SENTIMENT_SCORE\": <integer 1-10, where 1=very satisfied/calm and 10=extremely frustrated/angry>,\n  \"SENTIMENT_LABEL\": <\"Satisfied\" | \"Neutral\" | \"Frustrated\" | \"Angry\">,\n  \"URGENCY\": <\"Low\" | \"Medium\" | \"High\" | \"Critical\">,\n  \"CLIENT_TONE\": <one of: \"Polite\", \"Neutral\", \"Impatient\", \"Demanding\", \"Angry\", \"Threatening\", \"Confused\", \"Appreciative\">,\n  \"TICKET_SUMMARY\": <string: 3-6 sentence plain-text summary of the entire ticket conversation, what the issue is, current status, and what is needed>,\n  \"TAGS\": <JSON array of 2-6 short topic slugs for the WHMCS Tag Cloud. Rules: each string must start with the prefix ai- (examples: ai-billing, ai-ssl, ai-dns, ai-outage, ai-email, ai-abuse); after ai- use only lowercase letters, digits, and hyphens; no spaces; pick themes that match this ticket (product area, failure type, billing, security, abuse, email, DNS, etc.)>\n}\n\n=== URGENCY GUIDE ===\nCritical = service is completely down or data is at risk\nHigh = major disruption, client explicitly escalating or threatening to leave\nMedium = functional issue affecting daily operations\nLow = informational question or minor inconvenience\n\n=== TICKET DATA ===\nClient: {{CLIENT_NAME}}\nDepartment: {{DEPARTMENT}}\nSubject: {{SUBJECT}}\n\n=== CONVERSATION ===\n{{MESSAGES}}",
            ],
            'tools_evidence_system' => [
                'label'       => 'Tools Evidence — System Prompt',
                'description' => 'System prompt for optional readable evidence extraction from tool outputs. Keep this strict and concise; used as a safe enhancement and not required for reply generation.',
                'content'     => "You are a technical evidence normalizer for hosting support. Convert raw network diagnostic outputs into concise, factual findings. Never fabricate values. If data is missing or unclear, state unknown. Output plain text only.",
            ],
            'tools_evidence_user' => [
                'label'       => 'Tools Evidence — User Prompt',
                'description' => 'Template for converting raw tool API output into readable evidence. Placeholder: {{RAW_TOOL_OUTPUT}}.',
                'content'     => "Normalize the following raw tool output for support staff.\n\nRequirements:\n- Keep only high-signal findings.\n- Mention errors/timeouts explicitly.\n- Use max 4 bullets.\n\nRaw output:\n{{RAW_TOOL_OUTPUT}}",
            ],
            'tools_reply_context_wrapper' => [
                'label'       => 'Tools Reply Context Wrapper',
                'description' => 'Template wrapper injected into reply generation custom instructions. Placeholder: {{TOOLS_EVIDENCE}}.',
                'content'     => "=== TOOLS EXECUTION RESULTS (AUTO-RUN) ===\n{{TOOLS_EVIDENCE}}",
            ],
            'autopilot_system' => [
                'label'       => 'Autopilot — System Prompt',
                'description' => 'System persona injected when Autopilot generates an automated first reply. This controls the tone, format, and behavior of auto-replies. Output ONLY the reply body — no JSON schema.',
                'content'     => "You are Sahdev, a Senior Technical Support Specialist for a premium web hosting company. Your goal is to provide helpful, warm, and professional first-response support.\n\nAUTOPILOT RULES:\n1. Write a complete reply to the client's ticket — address their issue specifically.\n2. Include a warm, professional greeting using the client's first name when known.\n3. Do NOT include a sign-off or signature — these are added automatically.\n4. Be empathetic, clear, and solution-focused.\n5. For technical issues, provide step-by-step guidance when possible.\n6. Output ONLY the reply text. No JSON, no schema, no code fences, no preamble.",
            ],
        ];
    }

    /**
     * Seeds the tblsahdev_prompt_templates table with default values.
     * Called once when the table is first created.
     */
    private function seedDefaultPromptTemplates(): void
    {
        $now = \Carbon\Carbon::now();
        foreach ($this->getDefaultPromptDefinitions() as $key => $def) {
            Capsule::table('tblsahdev_prompt_templates')->insert([
                'prompt_key'      => $key,
                'label'           => $def['label'],
                'description'     => $def['description'],
                'default_content' => $def['content'],
                'content'         => $def['content'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    /**
     * Generates a standardized, beautifully styled navigation menu for the Admin UI.
     * 
     * @param string $activeTab The key of the currently active tab.
     * @return string
     */
    private function getNavigationMarkup(string $activeTab = 'settings'): string
    {
        $base = htmlspecialchars($this->moduleVars['modulelink']);
        $tabs = [
            'settings' => ['label' => '<i class="fas fa-cog"></i> General Settings', 'url' => $base],
            'my_preferences' => ['label' => '<i class="fas fa-user-cog"></i> My Preferences', 'url' => $base . '&action=my_preferences'],
            'providers' => ['label' => '<i class="fas fa-microchip"></i> AI Providers', 'url' => $base . '&action=providers'],
            'knowledgebase' => ['label' => '<i class="fas fa-book"></i> Knowledgebase', 'url' => $base . '&action=knowledgebase'],
            'prompt_manager' => ['label' => '<i class="fas fa-magic"></i> Prompt Manager', 'url' => $base . '&action=prompt_manager'],
            'summaries' => ['label' => '<i class="fas fa-file-alt"></i> Ticket Summaries', 'url' => $base . '&action=summaries'],
            'canned_responses' => ['label' => '<i class="fas fa-save"></i> Canned Responses', 'url' => $base . '&action=canned_responses'],
            'intents' => ['label' => '<i class="fas fa-bullseye"></i> Intents Manager', 'url' => $base . '&action=intents'],
            'tools' => ['label' => '<i class="fas fa-tools"></i> Tools Execution', 'url' => $base . '&action=tools'],
            'autopilot' => ['label' => '<i class="fas fa-robot"></i> Autopilot', 'url' => $base . '&action=autopilot'],
            'cron_center' => ['label' => '<i class="fas fa-clock"></i> Separate Cron', 'url' => $base . '&action=cron_center'],
            'ticket_insights' => ['label' => '<i class="fas fa-brain"></i> Ticket Insights', 'url' => $base . '&action=ticket_insights'],
            'analytics' => ['label' => '<i class="fas fa-chart-line"></i> Analytics & ROI', 'url' => $base . '&action=analytics'],
            'audit_trail' => ['label' => '<i class="fas fa-history"></i> Audit Trail', 'url' => $base . '&action=audit_trail'],
            'module_logs' => ['label' => '<i class="fas fa-clipboard-list"></i> Module log', 'url' => $base . '&action=module_logs'],
            'logs_maintenance' => ['label' => '<i class="fas fa-database"></i> Logs & Maintenance', 'url' => $base . '&action=logs_maintenance'],
        ];

        $html = '<style>
            .sahdev-nav-wrapper { 
                background: #fff; 
                padding: 15px 20px; 
                border-radius: 8px; 
                box-shadow: 0 2px 8px rgba(0,0,0,0.04); 
                margin-bottom: 25px; 
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                border-left: 4px solid #0d6efd;
            }
            .sahdev-nav-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 16px;
                font-size: 14px;
                font-weight: 500;
                color: #495057;
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 6px;
                text-decoration: none !important;
                transition: all 0.2s ease-in-out;
            }
            .sahdev-nav-btn:hover {
                background: #e9ecef;
                color: #0d6efd;
                transform: translateY(-1px);
            }
            .sahdev-nav-btn.active {
                background: #0d6efd;
                color: #fff;
                border-color: #0d6efd;
                box-shadow: 0 4px 10px rgba(13, 110, 253, 0.2);
            }
            .sahdev-nav-btn i { font-size: 13px; }
            .sahdev-page-container {
                width: 100%;
                padding: 20px;
            }
        </style>';

        $html .= '<div class="sahdev-nav-wrapper">';
        foreach ($tabs as $key => $tab) {
            $activeClass = ($key === $activeTab) ? ' active' : '';
            $html .= sprintf('<a href="%s" class="sahdev-nav-btn%s">%s</a>', $tab['url'], $activeClass, $tab['label']);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Per-admin preferences (default model, tone, feature opt-outs). Own row only.
     *
     * @return string
     */
    public function my_preferences()
    {
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        if ($adminId <= 0) {
            return '<div class="alert alert-danger">Not logged in.</div>';
        }

        \Sahdev\Lib\AdminPreferences::ensureSchema();
        $settings = Capsule::table('tblsahdev_settings')->first();
        $settingsArr = $settings ? (array) $settings : [];
        $prefs = \Sahdev\Lib\AdminPreferences::load($adminId);

        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_my_preferences'])) {
            check_token('WHMCS.admin.default');
            try {
                $toneRaw = trim((string) ($_POST['tone_default'] ?? ''));
                $toneDefault = $toneRaw === '' ? null : $toneRaw;

                $incoming = [
                    'default_provider_id' => (int) ($_POST['default_provider_id'] ?? 0),
                    'tone_default'        => $toneDefault,
                    'features'            => [],
                ];
                foreach (\Sahdev\Lib\AdminPreferences::allFeatureKeys() as $k) {
                    $incoming['features'][$k] = \Sahdev\Lib\AdminPreferences::globalAllowsFeature($k, $settingsArr)
                        ? !empty($_POST['feature_' . $k])
                        : false;
                }
                \Sahdev\Lib\AdminPreferences::save($adminId, $incoming);
                $successMessage = 'Your preferences have been saved.';
                $prefs = \Sahdev\Lib\AdminPreferences::load($adminId);
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
            }
        }

        $providers = Capsule::table('tblsahdev_providers')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get();

        $csrfToken = generate_token('form');
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=my_preferences';

        $featureLabels = [
            \Sahdev\Lib\AdminPreferences::FEATURE_TICKET_AI          => 'Ticket analysis & reply (main panel, snapshot)',
            \Sahdev\Lib\AdminPreferences::FEATURE_SUMMARIZER         => 'AI ticket summarizer',
            \Sahdev\Lib\AdminPreferences::FEATURE_HISTORICAL_CONTEXT  => 'Historical client context (memory)',
            \Sahdev\Lib\AdminPreferences::FEATURE_REWRITE             => 'Expand & polish draft (rewrite)',
            \Sahdev\Lib\AdminPreferences::FEATURE_QUALITY_SCORE       => 'Score admin draft (quality)',
            \Sahdev\Lib\AdminPreferences::FEATURE_CANNED_KB          => 'Canned responses & KB search',
            \Sahdev\Lib\AdminPreferences::FEATURE_TOOLS               => 'Tools execution (diagnostics)',
            \Sahdev\Lib\AdminPreferences::FEATURE_TICKET_INSIGHTS     => 'Ticket insights (list badges / bulk fetch)',
            \Sahdev\Lib\AdminPreferences::FEATURE_ANALYTICS          => 'Analytics page data',
            \Sahdev\Lib\AdminPreferences::FEATURE_AUDIT_DELETE        => 'Deleting audit trail entries (bulk cleanup)',
        ];

        ob_start();
        ?>
        <?php echo $this->getNavigationMarkup('my_preferences'); ?>
        <div class="sahdev-page-container">
            <h2 style="margin-bottom:10px;"><i class="fas fa-user-cog"></i> My Sahdev preferences</h2>
            <p class="text-muted" style="margin-bottom:20px;">
                Organization-wide settings under <strong>General Settings</strong> must enable a feature before you can use it.
                Here you can choose your default AI model and tone, and turn off Sahdev features you do not need for your own account.
            </p>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <form method="post" action="<?php echo $actionUrl; ?>">
                <?php echo $csrfToken; ?>
                <input type="hidden" name="save_my_preferences" value="1">

                <div class="panel panel-default" style="margin-bottom:20px;">
                    <div class="panel-heading"><strong>Defaults on ticket view</strong></div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label>Default AI provider for ticket analysis</label>
                            <select name="default_provider_id" class="form-control" style="max-width:520px;">
                                <option value="0"<?php echo ((int) ($prefs['default_provider_id'] ?? 0) <= 0) ? ' selected' : ''; ?>>Use organization routing (task map / primary)</option>
                                <?php foreach ($providers as $p): ?>
                                    <option value="<?php echo (int) $p->id; ?>"<?php echo ((int) ($prefs['default_provider_id'] ?? 0) === (int) $p->id) ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p->name . ' — ' . ($p->model_name ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="help-block">Pre-selects the model dropdown on support tickets. You can still change it per request.</p>
                        </div>
                        <div class="form-group">
                            <label>Default tone</label>
                            <select name="tone_default" class="form-control" style="max-width:320px;">
                                <option value=""<?php echo ($prefs['tone_default'] === null || $prefs['tone_default'] === '') ? ' selected' : ''; ?>>Inherit organization default</option>
                                <option value="Professional"<?php echo (($prefs['tone_default'] ?? '') === 'Professional') ? ' selected' : ''; ?>>Professional</option>
                                <option value="Technical"<?php echo (($prefs['tone_default'] ?? '') === 'Technical') ? ' selected' : ''; ?>>Technical</option>
                                <option value="Friendly"<?php echo (($prefs['tone_default'] ?? '') === 'Friendly') ? ' selected' : ''; ?>>Friendly</option>
                                <option value="Strict"<?php echo (($prefs['tone_default'] ?? '') === 'Strict') ? ' selected' : ''; ?>>Strict</option>
                                <option value="Custom"<?php echo (($prefs['tone_default'] ?? '') === 'Custom') ? ' selected' : ''; ?>>Custom</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="panel panel-default">
                    <div class="panel-heading"><strong>Features visible to me</strong></div>
                    <div class="panel-body">
                        <p class="text-muted" style="margin-bottom:15px;">Uncheck to hide a capability from your ticket UI and block its AJAX actions. Does not affect other staff or background cron jobs.</p>
                        <table class="table table-striped" style="max-width:900px;">
                            <thead>
                                <tr>
                                    <th>Feature</th>
                                    <th style="width:120px;">Enabled for me</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (\Sahdev\Lib\AdminPreferences::allFeatureKeys() as $key): ?>
                                    <?php
                                    $globOn = \Sahdev\Lib\AdminPreferences::globalAllowsFeature($key, $settingsArr);
                                    $checked = !empty($prefs['features'][$key]);
                                    $disabled = !$globOn ? ' disabled' : '';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($featureLabels[$key] ?? $key); ?>
                                            <?php if (!$globOn): ?>
                                                <br><span class="label label-default">Disabled organization-wide — enable under General Settings first</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <input type="hidden" name="feature_<?php echo htmlspecialchars($key); ?>" value="0">
                                            <input type="checkbox" name="feature_<?php echo htmlspecialchars($key); ?>" value="1"<?php echo $checked ? ' checked' : ''; ?><?php echo $disabled; ?>>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="help-block text-muted" style="margin-top:12px;">
                            <i class="fas fa-info-circle"></i> Cron-based ticket insights and tools queue are not controlled here.
                        </p>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save preferences</button>
                    </div>
                </div>
            </form>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Default view for the Addon settings.
     *
     * @return string
     */
    public function settings()
    {
        // Inline migration: ensure auto_analyze_on_load column exists on older installs
        try {
            Capsule::table('tblsahdev_settings')->select('auto_analyze_on_load')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('auto_analyze_on_load')->default(0);
            });
        }
        
        // Inline migration: ensure summarizer columns exist
        try {
            Capsule::table('tblsahdev_settings')->select('summarizer_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('summarizer_enabled')->default(0);
                $table->integer('summarizer_threshold')->default(15);
            });
        }
        
        // Inline migration: ensure compliance_mode column exists
        try {
            Capsule::table('tblsahdev_settings')->select('compliance_mode')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('compliance_mode')->default(0);
                $table->boolean('scrub_emails')->default(1);
                $table->boolean('scrub_cc')->default(1);
                $table->boolean('scrub_ips')->default(1);
                $table->boolean('scrub_passwords')->default(1);
            });
        }

        // Granular columns inline check for existing installs
        try {
            Capsule::table('tblsahdev_settings')->select('scrub_emails')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('scrub_emails')->default(1);
                $table->boolean('scrub_cc')->default(1);
                $table->boolean('scrub_ips')->default(1);
                $table->boolean('scrub_passwords')->default(1);
            });
        }

        // Inline migration: ensure quality_scorer_enabled column exists
        try {
            Capsule::table('tblsahdev_settings')->select('quality_scorer_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('quality_scorer_enabled')->default(1);
            });
        }

        // Inline migration: ensure auto_tagging column exists (WHMCS Tag Cloud from cron insights)
        try {
            Capsule::table('tblsahdev_settings')->select('auto_tagging')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('auto_tagging')->default(0);
            });
        }

        // Inline migration: ensure custom_attachments_dir column exists
        try {
            Capsule::table('tblsahdev_settings')->select('custom_attachments_dir')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('custom_attachments_dir', 255)->nullable();
            });
        }

        // Multi-model orchestration: per-task provider map (JSON)
        try {
            Capsule::table('tblsahdev_settings')->select('task_provider_map')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->longText('task_provider_map')->nullable();
            });
        }
        // Token fallback when model/global token value is unavailable.
        try {
            Capsule::table('tblsahdev_settings')->select('max_tokens_fallback')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->integer('max_tokens_fallback')->default(4096);
            });
        }

        try {
            Capsule::table('tblsahdev_settings')->select('context_enrichment_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('context_enrichment_enabled')->default(1);
                $table->integer('context_enrichment_max_chars')->default(2500);
                $table->boolean('context_enrichment_invoices')->default(1);
                $table->boolean('context_enrichment_domains')->default(1);
                $table->boolean('context_enrichment_addons')->default(1);
                $table->boolean('context_enrichment_custom_fields')->default(1);
                $table->boolean('context_enrichment_hosting')->default(1);
                $table->boolean('context_enrichment_client_notes')->default(0);
                $table->text('context_enrichment_custom_field_allowlist')->nullable();
            });
        }

        try {
            Capsule::table('tblsahdev_settings')->select('context_enrichment_hosting')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('context_enrichment_hosting')->default(1);
            });
        }

        try {
            Capsule::table('tblsahdev_settings')->select('scrub_phones')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('scrub_phones')->default(1);
            });
        }

        try {
            Capsule::table('tblsahdev_module_logs')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_module_logs', function ($table) {
                $table->increments('id');
                $table->string('level', 16)->index();
                $table->string('source', 128)->index();
                $table->text('message');
                $table->integer('ticket_id')->unsigned()->nullable()->index();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
            check_token("WHMCS.admin.default"); // Verify CSRF

            $primaryProviderId = (int) ($_POST['primary_provider_id'] ?? 1);
            $fallbackProviderId = (int) ($_POST['fallback_provider_id'] ?? 0);
            $temperature = (float) ($_POST['temperature'] ?? 0.70);
            $maxTokens = (int) ($_POST['max_tokens'] ?? 2048);
            $maxTokensFallback = max(4096, (int) ($_POST['max_tokens_fallback'] ?? 4096));
            $toneDefault = $_POST['tone_default'] ?? 'Professional';
            $autoAnalyzeOnLoad = !empty($_POST['auto_analyze_on_load']) ? 1 : 0;
            
            $maxMessages = (int) ($_POST['max_messages'] ?? 10);
            $maxAttachmentChars = (int) ($_POST['max_attachment_chars'] ?? 5000);
            $maxImages = (int) ($_POST['max_images'] ?? 3);
            
            $summarizerEnabled = !empty($_POST['summarizer_enabled']) ? 1 : 0;
            $summarizerThreshold = (int) ($_POST['summarizer_threshold'] ?? 15);
            if ($summarizerThreshold < 3) $summarizerThreshold = 3; 
            
            $complianceMode = !empty($_POST['compliance_mode']) ? 1 : 0;
            $scrubEmails = !empty($_POST['scrub_emails']) ? 1 : 0;
            $scrubCc = !empty($_POST['scrub_cc']) ? 1 : 0;
            $scrubIps = !empty($_POST['scrub_ips']) ? 1 : 0;
            $scrubPasswords = !empty($_POST['scrub_passwords']) ? 1 : 0;
            $scrubPhones = !empty($_POST['scrub_phones']) ? 1 : 0;
            $qualityScorerEnabled = !empty($_POST['quality_scorer_enabled']) ? 1 : 0;
            $autoTagging = !empty($_POST['auto_tagging']) ? 1 : 0;
            $customAttachmentsDir = trim($_POST['custom_attachments_dir'] ?? '');

            $contextEnrichmentEnabled = !empty($_POST['context_enrichment_enabled']) ? 1 : 0;
            $contextEnrichmentMaxChars = max(500, min(20000, (int) ($_POST['context_enrichment_max_chars'] ?? 2500)));
            $contextEnrichmentInvoices = !empty($_POST['context_enrichment_invoices']) ? 1 : 0;
            $contextEnrichmentDomains = !empty($_POST['context_enrichment_domains']) ? 1 : 0;
            $contextEnrichmentHosting = !empty($_POST['context_enrichment_hosting']) ? 1 : 0;
            $contextEnrichmentAddons = !empty($_POST['context_enrichment_addons']) ? 1 : 0;
            $contextEnrichmentCustomFields = !empty($_POST['context_enrichment_custom_fields']) ? 1 : 0;
            $contextEnrichmentClientNotes = !empty($_POST['context_enrichment_client_notes']) ? 1 : 0;
            $contextEnrichmentCustomFieldAllowlist = trim($_POST['context_enrichment_custom_field_allowlist'] ?? '');

            $taskProviderMap = [];
            $taskMapRaw = $_POST['task_provider_map'] ?? [];
            if (is_array($taskMapRaw)) {
                foreach (TaskProviderResolver::canonicalTaskKeys() as $key) {
                    if (!isset($taskMapRaw[$key])) {
                        continue;
                    }
                    $vid = (int) $taskMapRaw[$key];
                    if ($vid > 0 && TaskProviderResolver::isValidActiveProviderId($vid)) {
                        $taskProviderMap[$key] = $vid;
                    }
                }
            }
            $taskProviderMapJson = $taskProviderMap === [] ? null : json_encode($taskProviderMap);

            // Ensure valid bounds
            if ($temperature < 0 || $temperature > 1) {
                $temperature = 0.70;
            }

            Capsule::table('tblsahdev_settings')->updateOrInsert(
                ['id' => 1],
                [
                    'primary_provider_id' => $primaryProviderId ?: null,
                    'fallback_provider_id' => $fallbackProviderId ?: null,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'max_tokens_fallback' => $maxTokensFallback,
                    'tone_default' => $toneDefault,
                    'max_messages' => $maxMessages,
                    'max_attachment_chars' => $maxAttachmentChars,
                    'max_images' => $maxImages,
                    'auto_analyze_on_load' => $autoAnalyzeOnLoad,
                    'summarizer_enabled' => $summarizerEnabled,
                    'summarizer_threshold' => $summarizerThreshold,
                    'compliance_mode' => $complianceMode,
                    'scrub_emails' => $scrubEmails,
                    'scrub_cc' => $scrubCc,
                    'scrub_ips' => $scrubIps,
                    'scrub_passwords' => $scrubPasswords,
                    'scrub_phones' => $scrubPhones,
                    'quality_scorer_enabled' => $qualityScorerEnabled,
                    'auto_tagging' => $autoTagging,
                    'custom_attachments_dir' => $customAttachmentsDir,
                    'task_provider_map' => $taskProviderMapJson,
                    'context_enrichment_enabled' => $contextEnrichmentEnabled,
                    'context_enrichment_max_chars' => $contextEnrichmentMaxChars,
                    'context_enrichment_invoices' => $contextEnrichmentInvoices,
                    'context_enrichment_domains' => $contextEnrichmentDomains,
                    'context_enrichment_hosting' => $contextEnrichmentHosting,
                    'context_enrichment_addons' => $contextEnrichmentAddons,
                    'context_enrichment_custom_fields' => $contextEnrichmentCustomFields,
                    'context_enrichment_client_notes' => $contextEnrichmentClientNotes,
                    'context_enrichment_custom_field_allowlist' => $contextEnrichmentCustomFieldAllowlist === '' ? null : $contextEnrichmentCustomFieldAllowlist,
                    'updated_at' => \Carbon\Carbon::now(),
                ]
            );

            $successMessage = "Settings saved successfully.";
        }

        // Fetch current settings
        $settings = Capsule::table('tblsahdev_settings')->first();
        if (!$settings) {
            $settings = (object) [
                'primary_provider_id' => 1,
                'fallback_provider_id' => null,
                'temperature' => 0.70,
                'max_tokens' => 2048,
                'max_tokens_fallback' => 4096,
                'tone_default' => 'Professional',
                'max_messages' => 10,
                'max_attachment_chars' => 5000,
                'max_images' => 3,
                'auto_analyze_on_load' => 0,
                'summarizer_enabled' => 0,
                'summarizer_threshold' => 15,
                'compliance_mode' => 0,
                'scrub_emails' => 1,
                'scrub_cc' => 1,
                'scrub_ips' => 1,
                'scrub_passwords' => 1,
                'scrub_phones' => 1,
                'quality_scorer_enabled' => 1,
                'auto_tagging' => 0,
                'task_provider_map' => null,
                'context_enrichment_enabled' => 1,
                'context_enrichment_max_chars' => 2500,
                'context_enrichment_invoices' => 1,
                'context_enrichment_domains' => 1,
                'context_enrichment_hosting' => 1,
                'context_enrichment_addons' => 1,
                'context_enrichment_custom_fields' => 1,
                'context_enrichment_client_notes' => 0,
                'context_enrichment_custom_field_allowlist' => null,
            ];
        }

        $taskMapStored = TaskProviderResolver::parseTaskProviderMap($settings->task_provider_map ?? null);

        // Fetch all active providers
        $providers = Capsule::table('tblsahdev_providers')->where('is_active', 1)->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>
        

        <?php echo $this->getNavigationMarkup('settings'); ?>
        <div class="sahdev-page-container">

            <h2 style="border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">Sahdev AI Intelligence - Settings</h2>

            <form method="post" action="<?php echo $actionUrl; ?>">
                <?php echo $csrfToken; ?>
                <input type="hidden" name="save_settings" value="1">

                <div class="row" style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Primary AI Provider 🌟</label>
                        <select name="primary_provider_id" class="form-control">
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?php echo $provider->id; ?>" <?php echo ($settings->primary_provider_id == $provider->id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($provider->name . ' (' . ucfirst($provider->provider_type) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">The main AI model used for ticket analysis.</small>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Fallback AI Provider 🛡️
                            (Optional)</label>
                        <select name="fallback_provider_id" class="form-control">
                            <option value="0">-- None (Don't use fallback) --</option>
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?php echo $provider->id; ?>" <?php echo ($settings->fallback_provider_id == $provider->id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($provider->name . ' (' . ucfirst($provider->provider_type) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Used automatically if the Primary AI fails to respond or is offline.</small>
                    </div>
                </div>

                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #6f42c1;">
                    <div class="panel-heading" style="background: #faf8ff;">
                        <h4 style="margin: 0; font-size: 15px; color:#5a32a3;"><i class="fas fa-route"></i> Model routing (per task)</h4>
                    </div>
                    <div class="panel-body">
                        <p class="text-muted" style="margin-top: 0; font-size: 13px;">
                            Choose which saved AI provider runs each feature. Leave a row as <strong>Use primary</strong> to use the Primary AI Provider above.
                            On ticket replies you can optionally override the model for one generation from the ticket panel.
                        </p>
                        <div class="table-responsive">
                            <table class="table table-condensed" style="margin-bottom: 0;">
                                <thead>
                                    <tr><th style="width: 40%;">Feature</th><th>Provider</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $labels = TaskProviderResolver::taskKeyLabels();
                                    foreach (TaskProviderResolver::canonicalTaskKeys() as $taskKey):
                                        $sel = $taskMapStored[$taskKey] ?? 0;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($labels[$taskKey] ?? $taskKey); ?></td>
                                        <td>
                                            <select name="task_provider_map[<?php echo htmlspecialchars($taskKey); ?>]" class="form-control input-sm">
                                                <option value="0" <?php echo $sel ? '' : 'selected'; ?>>Use primary (default)</option>
                                                <?php foreach ($providers as $p): ?>
                                                    <option value="<?php echo (int) $p->id; ?>" <?php echo ((int) $sel === (int) $p->id) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($p->name . ' — ' . $p->model_name); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row" style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Temperature</label>
                        <input type="number" step="0.01" min="0" max="1" name="temperature" class="form-control"
                            value="<?php echo htmlspecialchars($settings->temperature); ?>">
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Max Tokens</label>
                        <input type="number" step="1" min="1" name="max_tokens" class="form-control"
                            value="<?php echo htmlspecialchars($settings->max_tokens); ?>">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;">Token Fallback (minimum)</label>
                        <input type="number" step="1" min="4096" name="max_tokens_fallback" class="form-control"
                            value="<?php echo htmlspecialchars((int) ($settings->max_tokens_fallback ?? 4096)); ?>">
                        <small class="text-muted">Used when max_tokens is missing/unavailable (cron/tools fallback).</small>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 25px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 5px;">Default Tone</label>
                    <select name="tone_default" class="form-control" style="width: 100%; max-width: 300px;">
                        <?php
                        $tones = ['Professional', 'Technical', 'Friendly', 'Strict', 'Custom'];
                        foreach ($tones as $tone) {
                            $selected = ($settings->tone_default === $tone) ? 'selected' : '';
                            echo "<option value=\"$tone\" $selected>$tone</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #17a2b8;">
                    <div class="panel-heading" style="background: #f4fbfc;">
                        <h4 style="margin: 0; font-size: 15px; color:#117a8b;"><i class="fas fa-filter"></i> Data Extraction Limits <span class="label label-info" style="font-size: 11px; vertical-align: middle; margin-left: 6px;">Manage Context Size</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="row" style="display: flex; gap: 20px;">
                            <div class="form-group" style="flex: 1;">
                                <label style="font-weight: 600; display: block; margin-bottom: 5px;">Recent Messages Limit</label>
                                <input type="number" step="1" min="1" max="100" name="max_messages" class="form-control"
                                    value="<?php echo htmlspecialchars($settings->max_messages ?? 10); ?>">
                                <small class="text-muted">Number of most recent ticket replies to send to AI.</small>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label style="font-weight: 600; display: block; margin-bottom: 5px;">Text Attachment/Logs Chars</label>
                                <input type="number" step="100" min="0" name="max_attachment_chars" class="form-control"
                                    value="<?php echo htmlspecialchars($settings->max_attachment_chars ?? 5000); ?>">
                                <small class="text-muted">Max characters extracted from attached .txt/.log files.</small>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label style="font-weight: 600; display: block; margin-bottom: 5px;">Max Images Analyzed</label>
                                <input type="number" step="1" min="0" max="10" name="max_images" class="form-control"
                                    value="<?php echo htmlspecialchars($settings->max_images ?? 3); ?>">
                                <small class="text-muted">Number of recent images to send (Requires Gemini/GPT-4o).</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #5c4d7d;">
                    <div class="panel-heading" style="background: #f7f5fc;">
                        <h4 style="margin: 0; font-size: 15px; color:#3d3554;"><i class="fas fa-id-card"></i> Account context enrichment (read-only) <span class="label label-default" style="font-size: 11px; vertical-align: middle; margin-left: 6px;">WHMCS data</span></h4>
                    </div>
                    <div class="panel-body">
                        <p class="text-muted" style="margin-top: 0; font-size: 13px;">
                            Append bounded, read-only client account snippets (invoices, domains, addons, optional custom fields) to the <strong>Services</strong> block sent to the AI. Scoped by the ticket&rsquo;s client ID only; guest tickets are skipped.
                        </p>
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="context_enrichment_enabled" value="1" <?php echo !empty($settings->context_enrichment_enabled) ? 'checked' : ''; ?>>
                                &nbsp;Enable account context enrichment
                            </label>
                        </div>
                        <div class="form-group" style="margin-top: 12px; max-width: 280px;">
                            <label style="font-weight: 600;">Max characters (entire services block)</label>
                            <input type="number" name="context_enrichment_max_chars" class="form-control" min="500" max="20000" step="100"
                                value="<?php echo htmlspecialchars((string) ($settings->context_enrichment_max_chars ?? 2500)); ?>">
                        </div>
                        <p style="font-weight: 600; margin: 16px 0 8px;">Include in enrichment</p>
                        <div class="row" style="display: flex; flex-wrap: wrap; gap: 12px 24px;">
                            <div class="checkbox" style="margin: 0;"><label><input type="checkbox" name="context_enrichment_invoices" value="1" <?php echo !empty($settings->context_enrichment_invoices) ? 'checked' : ''; ?>> Recent invoices</label></div>
                            <div class="checkbox" style="margin: 0;"><label><input type="checkbox" name="context_enrichment_domains" value="1" <?php echo !empty($settings->context_enrichment_domains) ? 'checked' : ''; ?>> Domains</label></div>
                            <div class="checkbox" style="margin: 0;"><label><input type="checkbox" name="context_enrichment_hosting" value="1" <?php echo !empty($settings->context_enrichment_hosting) ? 'checked' : ''; ?>> Hosting & Server context</label></div>
                            <div class="checkbox" style="margin: 0;"><label><input type="checkbox" name="context_enrichment_addons" value="1" <?php echo !empty($settings->context_enrichment_addons) ? 'checked' : ''; ?>> Hosting addons</label></div>
                            <div class="checkbox" style="margin: 0;"><label><input type="checkbox" name="context_enrichment_custom_fields" value="1" <?php echo !empty($settings->context_enrichment_custom_fields) ? 'checked' : ''; ?>> Custom fields (filtered)</label></div>
                            <div class="checkbox" style="margin: 0;"><label><input type="checkbox" name="context_enrichment_client_notes" value="1" <?php echo !empty($settings->context_enrichment_client_notes) ? 'checked' : ''; ?>> Staff client notes (internal)</label></div>
                        </div>
                        <div class="form-group" style="margin-top: 14px;">
                            <label style="font-weight: 600;">Custom field allowlist (optional)</label>
                            <input type="text" name="context_enrichment_custom_field_allowlist" class="form-control" placeholder="e.g. VAT Number, 12, Company Name"
                                value="<?php echo htmlspecialchars((string) ($settings->context_enrichment_custom_field_allowlist ?? '')); ?>">
                            <small class="text-muted">Comma-separated field names or numeric field IDs. If empty, only safe filtered fields are included.</small>
                        </div>
                    </div>
                </div>

                <!-- AI Snapshot Feature Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #0d6efd;">
                    <div class="panel-heading" style="background: #f0f5ff;">
                        <h4 style="margin: 0; font-size: 15px;"><i class="fas fa-bolt"></i> AI Snapshot on Page Load <span class="label label-primary" style="font-size: 11px; vertical-align: middle; margin-left: 6px;">Feature Toggle</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="auto_analyze_on_load" value="1" <?php echo !empty($settings->auto_analyze_on_load) ? 'checked' : ''; ?>>
                                &nbsp;Enable automatic AI analysis when a ticket page loads
                            </label>
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0;">
                            When <strong>ON</strong>: Sahdev will silently fetch the AI-generated <strong>Summary, Root Cause, Responsibility, Risk Level, and Action Plan</strong> as soon as a ticket page opens.<br>
                            <em class="text-warning"><i class="fas fa-exclamation-triangle"></i> Note: This uses one AI call per ticket page load. Uses cache when available so repeat views are free.</em>
                        </p>
                    </div>
                </div>

                <!-- AI Summarizer Feature Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #6f42c1;">
                    <div class="panel-heading" style="background: #fdfbff;">
                        <h4 style="margin: 0; font-size: 15px; color:#4b2d8a;"><i class="fas fa-compress-alt"></i> Adaptive Ticket Summarizer <span class="label label-success" style="font-size: 11px; vertical-align: middle; margin-left: 6px; background:#6f42c1;">Saves Tokens</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="summarizer_enabled" value="1" <?php echo !empty($settings->summarizer_enabled) ? 'checked' : ''; ?>>
                                &nbsp;Enable Adaptive Ticket Summarizer
                            </label>
                        </div>
                        <div style="margin-top: 10px; display:flex; align-items:center; gap:10px;">
                            <label style="margin:0; font-weight:600;">Message Threshold:</label>
                            <input type="number" name="summarizer_threshold" value="<?php echo htmlspecialchars($settings->summarizer_threshold ?? 15); ?>" class="form-control" style="width: 80px; display:inline-block;" min="3">
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                            When a ticket reaches the threshold number of replies, the AI will use the generated summary of the conversation instead of the full raw message history. This drastically reduces input token usage for long tickets. Admins can manage these in the ticket sidebar.
                        </p>
                    </div>
                </div>

                <!-- Response Quality Scorer Feature Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #fd7e14;">
                    <div class="panel-heading" style="background: #fff8f3;">
                        <h4 style="margin: 0; font-size: 15px; color:#d35400;"><i class="fas fa-bullseye"></i> AI Response Quality Scorer <span class="label label-success" style="font-size: 11px; vertical-align: middle; margin-left: 6px; background:#fd7e14;">New</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="quality_scorer_enabled" value="1" <?php echo !empty($settings->quality_scorer_enabled) ? 'checked' : ''; ?>>
                                &nbsp;Enable Auto-Scoring for AI Generated Replies
                            </label>
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                            When enabled, Sahdev will automatically evaluate the clarity, tone, and completeness of its own generated replies and display a confidence score badge in the ticket panel in a single combined AI call. Manual draft scoring remains available regardless of this setting.
                        </p>
                    </div>
                </div>

                <!-- WHMCS Tag Cloud (cron insights) -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #0dcaf0;">
                    <div class="panel-heading" style="background: #f0fcff;">
                        <h4 style="margin: 0; font-size: 15px; color:#087990;"><i class="fas fa-tags"></i> WHMCS ticket tags (AI) <span class="label label-info" style="font-size: 11px; vertical-align: middle; margin-left: 6px;">Tag Cloud</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="auto_tagging" value="1" <?php echo !empty($settings->auto_tagging) ? 'checked' : ''; ?>>
                                &nbsp;Apply AI-generated tags from Ticket Insights (cron) to WHMCS
                            </label>
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                            When enabled, each successful cron or ticket-list “analyze” run pushes tags into WHMCS so they show in the <strong>native Tag Cloud</strong> field on the ticket (sidebar). Tags use the <code>ai-</code> prefix (e.g. <code>ai-billing</code>) so they stay distinct from manual tags; Sahdev replaces previous <code>ai-*</code> entries on that ticket when it re-analyzes. Sahdev tries the internal <code>UpdateTicket</code> API, the <code>tbltickets.tags</code> column if your build has it, and tag storage (including <code>tbltickettags</code> rows with <code>ticketid</code> + plain <code>tag</code> text, or <code>tag_id</code> + <code>tbltags</code>, or <code>tbltaglinks</code>). After enabling, run analysis again (or wait for cron) so existing tickets get tags. The ticket list still shows tag chips in Sahdev insights.
                        </p>
                    </div>
                </div>

                <!-- Compliance Mode Toggle -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #198754;">
                    <div class="panel-heading" style="background: #f8fff9;">
                        <h4 style="margin: 0; font-size: 15px; color:#146c43;"><i class="fas fa-user-shield"></i> Compliance Mode (PII Scrubber) <span class="label label-success" style="font-size: 11px; vertical-align: middle; margin-left: 6px; background:#198754;">Privacy</span></h4>
                    </div>
                    <div class="panel-body">
                        <div class="checkbox" style="margin-top: 0;">
                            <label style="font-weight: 600; font-size: 14px;">
                                <input type="checkbox" name="compliance_mode" value="1" <?php echo !empty($settings->compliance_mode) ? 'checked' : ''; ?>>
                                &nbsp;Enable PII Scrubber
                            </label>
                        </div>
                        <div style="margin-top: 10px; display:flex; gap:15px; flex-wrap: wrap;">
                            <label><input type="checkbox" name="scrub_emails" value="1" <?php echo (!isset($settings->scrub_emails) || !empty($settings->scrub_emails)) ? 'checked' : ''; ?>> <i class="fas fa-envelope text-muted"></i> Emails</label>
                            <label><input type="checkbox" name="scrub_cc" value="1" <?php echo (!isset($settings->scrub_cc) || !empty($settings->scrub_cc)) ? 'checked' : ''; ?>> <i class="fas fa-credit-card text-muted"></i> Credit Cards</label>
                            <label><input type="checkbox" name="scrub_ips" value="1" <?php echo (!isset($settings->scrub_ips) || !empty($settings->scrub_ips)) ? 'checked' : ''; ?>> <i class="fas fa-network-wired text-muted"></i> IPv4</label>
                            <label><input type="checkbox" name="scrub_passwords" value="1" <?php echo (!isset($settings->scrub_passwords) || !empty($settings->scrub_passwords)) ? 'checked' : ''; ?>> <i class="fas fa-key text-muted"></i> Passwords</label>
                            <label><input type="checkbox" name="scrub_phones" value="1" <?php echo (!isset($settings->scrub_phones) || !empty($settings->scrub_phones)) ? 'checked' : ''; ?>> <i class="fas fa-phone text-muted"></i> Phone numbers</label>
                        </div>
                        <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                            When enabled, Sahdev will automatically use regex to strip out selected sensitive information before sending the ticket context to the AI model. Essential context structure remains intact.
                        </p>
                    </div>
                </div>

                <!-- Advanced Path Settings -->
                <div class="panel panel-default" style="margin-bottom: 25px; border-left: 4px solid #6c757d;">
                    <div class="panel-heading" style="background: #f8f9fa;">
                        <h4 style="margin: 0; font-size: 15px; color:#495057;"><i class="fas fa-folder-open"></i> Advanced Path Settings</h4>
                    </div>
                    <div class="panel-body">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-weight: 600; display: block; margin-bottom: 5px;">Custom Attachments Directory Path (Optional)</label>
                            <input type="text" name="custom_attachments_dir" class="form-control" placeholder="e.g. /home/user/whmcsdata/attachments" value="<?php echo htmlspecialchars($settings->custom_attachments_dir ?? ''); ?>">
                            <p class="text-muted" style="margin-top: 8px; margin-bottom: 0; font-size:13px;">
                                Leave blank to automatically detect the WHMCS attachments folder from <code>configuration.php</code>. If images are failing to load for AI analysis, you can hardcode the absolute server path to your attachments folder here.
                            </p>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: 600;">
                    <i class="fas fa-save" style="margin-right: 5px;"></i> Save General Settings
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AI Providers Manager View
     *
     * @return string
     */
    public function providers()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");

            $action = $_POST['provider_action'] ?? '';

            if ($action === 'create' || $action === 'update') {
                $id = (int) ($_POST['provider_id'] ?? 0);
                $name = trim($_POST['provider_name'] ?? '');
                $type = $_POST['provider_type'] ?? 'lmstudio';
                $apiKey = $_POST['api_key'] ?? '';
                $apiUrl = $_POST['api_url'] ?? '';
                $modelName = $_POST['model_name'] ?? '';
                $costInput = (float) ($_POST['cost_input_1m'] ?? 0.0000);
                $costOutput = (float) ($_POST['cost_output_1m'] ?? 0.0000);

                if (empty($name)) {
                    $errorMessage = "Provider name is required.";
                } else {
                    $data = [
                        'name' => $name,
                        'provider_type' => $type,
                        'api_url' => $apiUrl,
                        'model_name' => $modelName,
                        'cost_input_1m' => $costInput,
                        'cost_output_1m' => $costOutput,
                        'updated_at' => \Carbon\Carbon::now(),
                    ];

                    if (!empty($apiKey)) {
                        $data['api_key'] = encrypt($apiKey);
                    }

                    if ($action === 'create') {
                        $data['created_at'] = \Carbon\Carbon::now();
                        Capsule::table('tblsahdev_providers')->insert($data);
                        $successMessage = "Provider created successfully.";
                    } else {
                        Capsule::table('tblsahdev_providers')->where('id', $id)->update($data);
                        $successMessage = "Provider updated successfully.";
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int) $_POST['provider_id'];

                // Check if it's currently assigned
                $inUse = Capsule::table('tblsahdev_settings')
                    ->where('primary_provider_id', $id)
                    ->orWhere('fallback_provider_id', $id)
                    ->exists();

                if ($inUse) {
                    $errorMessage = "Cannot delete provider while it is assigned as Primary or Fallback in General Settings.";
                } else {
                    Capsule::table('tblsahdev_providers')->where('id', $id)->delete();
                    $successMessage = "Provider deleted.";
                }
            }
        }

        $providers = Capsule::table('tblsahdev_providers')->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=providers';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);
        $kbUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=knowledgebase';

        ob_start();
        ?>
        <style>

            .provider-card {
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 15px;
                background: #fafafa;
            }

            .provider-header {
                margin-bottom: 15px;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
        </style>

        <?php echo $this->getNavigationMarkup('providers'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom: 10px;">AI Providers Manager</h2>
            <p class="text-muted" style="margin-bottom: 25px;">Create and manage connections to various LLM APIs (OpenAI, Local
                LM Studio, Ollama, Google GenAI, Replicate). You can assign these as Primary or Fallback in General Settings.
            </p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Add New Form -->
            <div class="provider-card" style="border-left: 4px solid #198754; background: #f8fff9;">
                <div class="provider-header">
                    <h4 style="margin:0;"><i class="fas fa-plus-circle"></i> Add New AI Provider</h4>
                </div>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="provider_action" value="create">

                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-6">
                            <label>Display Name</label>
                            <input type="text" name="provider_name" class="form-control" placeholder="e.g. My Secure Local AI"
                                required>
                        </div>
                        <div class="col-md-6">
                            <label>API Format Type</label>
                            <select name="provider_type" class="form-control">
                                <option value="lmstudio">OpenAI Compatible (LM Studio / Ollama / OpenAI)</option>
                                <option value="google">Google GenAI (Gemini)</option>
                                <option value="replicate">Replicate</option>
                            </select>
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-12 mb-2">
                            <label>API Key</label>
                            <input type="password" name="api_key" class="form-control"
                                placeholder="Leave blank if local without auth">
                        </div>
                        <div class="col-md-12 mb-2">
                            <label>API URL Endpoint</label>
                            <input type="text" name="api_url" class="form-control"
                                placeholder="e.g. http://localhost:1234/v1/chat/completions">
                        </div>
                        <div class="col-md-12 mb-2">
                            <label>Model Name</label>
                            <input type="text" name="model_name" class="form-control"
                                placeholder="e.g. gpt-4, gemma-7b, models/gemini-pro">
                        </div>
                    </div>

                    <div class="row" style="margin-bottom: 15px;">
                        <div class="col-md-6 mb-2">
                            <label>Input Cost / 1M Tokens ($)</label>
                            <input type="number" step="0.0001" name="cost_input_1m" class="form-control"
                                placeholder="e.g. 1.25" value="0.00">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label>Output Cost / 1M Tokens ($)</label>
                            <input type="number" step="0.0001" name="cost_output_1m" class="form-control"
                                placeholder="e.g. 5.00" value="0.00">
                        </div>
                    </div>

                    <div style="text-align: right;">
                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> Add Provider</button>
                    </div>
                </form>
            </div>

            <hr style="margin: 30px 0;">
            <h4 style="margin-bottom: 15px;">Existing Providers</h4>

            <?php foreach ($providers as $p): ?>
                <div class="provider-card">
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="provider_id" value="<?php echo $p->id; ?>">

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-md-6">
                                <label>Display Name</label>
                                <input type="text" name="provider_name" class="form-control"
                                    value="<?php echo htmlspecialchars($p->name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label>API Format Type</label>
                                <select name="provider_type" class="form-control">
                                    <option value="lmstudio" <?php echo ($p->provider_type == 'lmstudio') ? 'selected' : ''; ?>>OpenAI
                                        Compatible</option>
                                    <option value="google" <?php echo ($p->provider_type == 'google') ? 'selected' : ''; ?>>Google
                                        GenAI</option>
                                    <option value="replicate" <?php echo ($p->provider_type == 'replicate') ? 'selected' : ''; ?>>
                                        Replicate</option>
                                </select>
                            </div>
                        </div>

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-md-12 mb-2">
                                <label>API Key</label>
                                <input type="password" name="api_key" class="form-control"
                                    placeholder="Hidden. Enter a new key to update.">
                            </div>
                            <div class="col-md-12 mb-2">
                                <label>API URL Endpoint</label>
                                <input type="text" name="api_url" class="form-control"
                                    value="<?php echo htmlspecialchars($p->api_url ?? ''); ?>">
                            </div>
                            <div class="col-md-12 mb-2">
                                <label>Model Name</label>
                                <input type="text" name="model_name" class="form-control"
                                    value="<?php echo htmlspecialchars($p->model_name ?? ''); ?>">
                            </div>
                        </div>

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-md-6 mb-2">
                                <label>Input Cost / 1M Tokens ($)</label>
                                <input type="number" step="0.0001" name="cost_input_1m" class="form-control"
                                    value="<?php echo htmlspecialchars($p->cost_input_1m ?? '0.0000'); ?>">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label>Output Cost / 1M Tokens ($)</label>
                                <input type="number" step="0.0001" name="cost_output_1m" class="form-control"
                                    value="<?php echo htmlspecialchars($p->cost_output_1m ?? '0.0000'); ?>">
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                            <button type="submit" name="provider_action" value="delete" class="btn btn-sm btn-danger"
                                onclick="return confirm('WARNING: Are you sure you want to delete this provider?');"><i
                                    class="fas fa-trash"></i> Delete</button>
                            <button type="submit" name="provider_action" value="update" class="btn btn-sm btn-primary"><i
                                    class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Intents Manager View
     *
     * @return string
     */
    public function intents()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");

            $action = $_POST['intent_action'] ?? '';

            if ($action === 'create' || $action === 'update') {
                $id = (int) ($_POST['intent_id'] ?? 0);
                $intentKey = strtoupper(trim($_POST['intent_key'] ?? ''));
                
                // Helper function to strip 4-byte characters (emojis)
                $stripEmojis = function($string) {
                    return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $string);
                };

                $label = trim($stripEmojis($_POST['label'] ?? ''));
                $directive = trim($stripEmojis($_POST['directive'] ?? ''));
                $isActive = !empty($_POST['is_active']) ? 1 : 0;
                $sortOrder = (int) ($_POST['sort_order'] ?? 0);

                if (empty($intentKey) || empty($label)) {
                    $errorMessage = "Intent Key and Label are required.";
                } elseif (!preg_match('/^[A-Z0-9_]+$/', $intentKey)) {
                    $errorMessage = "Intent Key must contain only uppercase letters, numbers, and underscores (e.g., RESOLVE_ISSUE).";
                } else {
                    $data = [
                        'intent_key' => $intentKey,
                        'label' => $label,
                        'directive' => $directive,
                        'is_active' => $isActive,
                        'sort_order' => $sortOrder,
                        'updated_at' => \Carbon\Carbon::now(),
                    ];

                    try {
                        if ($action === 'create') {
                            $data['created_at'] = \Carbon\Carbon::now();
                            Capsule::table('tblsahdev_intents')->insert($data);
                            $successMessage = "Intent created successfully.";
                        } else {
                            Capsule::table('tblsahdev_intents')->where('id', $id)->update($data);
                            $successMessage = "Intent updated successfully.";
                        }
                    } catch (\Exception $e) {
                         // Likely unique constraint violation on intent_key
                         $errorMessage = "Failed to save Intent. Ensure the Intent Key is unique. Error: " . $e->getMessage();
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int) $_POST['intent_id'];
                
                // Prevent deleting 'AUTO' as it's a fallback
                $intent = Capsule::table('tblsahdev_intents')->where('id', $id)->first();
                if ($intent && strtoupper(trim($intent->intent_key)) === 'AUTO') {
                    $errorMessage = "The 'AUTO' intent is required by the system and cannot be deleted.";
                } else {
                    Capsule::table('tblsahdev_intents')->where('id', $id)->delete();
                    $successMessage = "Intent deleted.";
                }
            }
        }

        // AGGRESSIVE SEEDING CHECK: if the table is completely empty, force seed it right before rendering.
        try {
            if (Capsule::table('tblsahdev_intents')->count() == 0) {
                Capsule::table('tblsahdev_intents')->insert([
                    [
                        'intent_key' => 'AUTO',
                        'label'      => 'Auto (AI Decides)',
                        'directive'  => '', 
                        'is_active'  => 1,
                        'sort_order' => 10,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                    [
                        'intent_key' => 'RESOLVE',
                        'label'      => 'Resolved Query',
                        'directive'  => 'REPLY INTENT — RESOLVED: The admin confirms this issue has been resolved. Write CLIENT_REPLY as a confident closing message. Acknowledge what was fixed, thank the client for their patience, and advise them to reopen the ticket if the issue recurs. Do NOT ask further questions.',
                        'is_active'  => 1,
                        'sort_order' => 20,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                    [
                        'intent_key' => 'INVESTIGATE',
                        'label'      => 'Checking Query',
                        'directive'  => 'REPLY INTENT — INVESTIGATING: The admin is still actively investigating this issue. Write CLIENT_REPLY to acknowledge the issue empathetically, confirm the support team is actively working on it, and set realistic expectations without making firm time commitments. Keep the client reassured.',
                        'is_active'  => 1,
                        'sort_order' => 30,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                    [
                        'intent_key' => 'MORE_INFO',
                        'label'      => 'Need More Info',
                        'directive'  => 'REPLY INTENT — NEED MORE INFORMATION: The admin needs additional details before proceeding. Write CLIENT_REPLY to clearly and politely list exactly what specific information, logs, screenshots, credentials, or steps are required from the client. Be precise — avoid vague requests.',
                        'is_active'  => 1,
                        'sort_order' => 40,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                    [
                        'intent_key' => 'GUIDE',
                        'label'      => 'Guide to Solution',
                        'directive'  => 'REPLY INTENT — GUIDE TO SOLUTION: The admin wants to guide the client to self-resolve. Write CLIENT_REPLY as a clear, step-by-step guide in simple language the client can follow independently. Use numbered steps. Anticipate likely stumbling points and address them proactively.',
                        'is_active'  => 1,
                        'sort_order' => 50,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                    [
                        'intent_key' => 'OUT_OF_SCOPE',
                        'label'      => 'Out of Scope',
                        'directive'  => 'REPLY INTENT — OUT OF SUPPORT SCOPE: This issue falls outside the support boundaries. Write CLIENT_REPLY to clearly but respectfully explain that this specific issue is not covered under the current support scope or plan. Where applicable, point to relevant resources, documentation, or upgrade options. Be firm yet courteous — avoid leaving the client feeling dismissed.',
                        'is_active'  => 1,
                        'sort_order' => 60,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                    [
                        'intent_key' => 'DUPLICATE',
                        'label'      => 'Duplicate Ticket',
                        'directive'  => 'REPLY INTENT — DUPLICATE TICKET: This is a duplicate of an existing ticket. Write CLIENT_REPLY to politely inform the client that this appears to be a duplicate of an existing ticket they have already submitted. Instruct them to continue communication on the original ticket to avoid confusion and ensure continuity of support. Close this ticket gracefully.',
                        'is_active'  => 1,
                        'sort_order' => 70,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                    [
                        'intent_key' => 'ABUSE_REPORT',
                        'label'      => 'Abuse Report',
                        'directive'  => 'REPLY INTENT — ABUSE REPORT: The admin is handling abuse, phishing, spam, malware, copyright, or policy reports. Write CLIENT_REPLY as a calm, human, policy-aware message that acknowledges the report, requests missing evidence when needed, outlines next review steps, and sets realistic follow-up expectations. Continue the conversation naturally and avoid abrupt closure.',
                        'is_active'  => 1,
                        'sort_order' => 80,
                        'created_at' => \Carbon\Carbon::now(),
                        'updated_at' => \Carbon\Carbon::now()
                    ],
                ]);
                $successMessage = empty($successMessage) ? "Database seeded with default intents." : $successMessage;
            }
        } catch (\Exception $e) {
            // Ignore if table doesn't exist yet
        }

        $intents = Capsule::table('tblsahdev_intents')->orderBy('sort_order', 'asc')->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=intents';

        ob_start();
        ?>
        <style>
            .intent-card {
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 15px;
                background: #fafafa;
            }
            .intent-header {
                margin-bottom: 15px;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
        </style>

        <?php echo $this->getNavigationMarkup('intents'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom: 10px;">Reply Intents Manager</h2>
            <p class="text-muted" style="margin-bottom: 25px;">Create and manage reply intents (e.g., Resolved, Need More Info). These appear as quick buttons in the ticket view and inject specific directives into the AI prompt when generating a reply.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Add New Form -->
            <div class="intent-card" style="border-left: 4px solid #198754; background: #f8fff9;">
                <div class="intent-header">
                    <h4 style="margin:0;"><i class="fas fa-plus-circle"></i> Add New Intent</h4>
                </div>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="intent_action" value="create">

                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-3">
                            <label>Internal Key (Uppercase)</label>
                            <input type="text" name="intent_key" class="form-control" placeholder="e.g. ESCALATE" required>
                        </div>
                        <div class="col-md-5">
                            <label>Button Label (Appears in UI)</label>
                            <input type="text" name="label" class="form-control" placeholder="e.g. 📈 Escalate" required>
                        </div>
                        <div class="col-md-2">
                            <label>Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="100">
                        </div>
                        <div class="col-md-2">
                            <label style="display:block;">Status</label>
                            <div class="checkbox" style="margin-top: 5px;">
                                <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row" style="margin-bottom: 10px;">
                        <div class="col-md-12">
                            <label>AI Prompt Directive</label>
                            <textarea name="directive" class="form-control" rows="3" placeholder="Instruction injected into system prompt when this intent is selected..."></textarea>
                            <small class="text-muted">Example: "REPLY INTENT — ESCALATING: Inform the client this issue is being escalated to a senior technician for further review. Set expectations for a follow-up."</small>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-sm btn-success mt-2"><i class="fas fa-plus"></i> Create Intent</button>
                </form>
            </div>

            <hr style="margin: 30px 0;">
            <h4 style="margin-bottom: 15px;">Existing Intents</h4>

            <?php 
                // Hardcode emojis for well known intents
                $intentIconMap = [
                    'AUTO'         => '🤖 ',
                    'RESOLVE'      => '✅ ',
                    'INVESTIGATE'  => '🧐 ',
                    'MORE_INFO'    => '❓ ',
                    'GUIDE'        => '🗺️ ',
                    'OUT_OF_SCOPE' => '🚫 ',
                    'DUPLICATE'    => '🔁 ',
                    'ABUSE_REPORT' => '🛡️ ',
                ];
            ?>

            <?php foreach ($intents as $intent): ?>
                <div class="intent-card" style="<?php echo (!$intent->is_active) ? 'opacity: 0.6;' : ''; ?>">
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="intent_id" value="<?php echo $intent->id; ?>">

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-md-3">
                                <label>Internal Key</label>
                                <input type="text" name="intent_key" class="form-control" value="<?php echo htmlspecialchars($intent->intent_key); ?>" <?php echo ($intent->intent_key === 'AUTO') ? 'readonly' : 'required'; ?>>
                            </div>
                            <div class="col-md-5">
                                <label>Button Label (<?php echo isset($intentIconMap[$intent->intent_key]) ? $intentIconMap[$intent->intent_key] : ''; ?>)</label>
                                <input type="text" name="label" class="form-control" value="<?php echo htmlspecialchars($intent->label); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label>Sort Order</label>
                                <input type="number" name="sort_order" class="form-control" value="<?php echo htmlspecialchars($intent->sort_order); ?>">
                            </div>
                            <div class="col-md-2">
                                <label style="display:block;">Status</label>
                                <div class="checkbox" style="margin-top: 5px;">
                                    <label><input type="checkbox" name="is_active" value="1" <?php echo ($intent->is_active) ? 'checked' : ''; ?>> Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-md-12">
                                <label>AI Prompt Directive</label>
                                <textarea name="directive" class="form-control" rows="3" <?php echo ($intent->intent_key === 'AUTO') ? 'readonly placeholder="AUTO uses default AI behavior without extra directives."' : ''; ?>><?php echo htmlspecialchars($intent->directive); ?></textarea>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                            <button type="submit" name="intent_action" value="update" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                            <button type="submit" name="intent_action" value="delete" class="btn btn-sm btn-danger" <?php echo ($intent->intent_key === 'AUTO') ? 'disabled' : ''; ?>
                                onclick="return confirm('WARNING: Are you sure you want to delete this intent?');"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Knowledgebase & Rules Manager View
     *
     * @return string
     */
    public function knowledgebase()
    {
        $kbPath = dirname(__DIR__) . '/knowledgebase';
        if (!is_dir($kbPath)) {
            mkdir($kbPath, 0755, true);
        }

        $successMessage = '';
        $errorMessage = '';

        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");

            $action = $_POST['kb_action'] ?? '';
            $filename = $_POST['filename'] ?? '';
            $content = $_POST['file_content'] ?? '';

            // Basic sanitization
            $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);

            if (!empty($action) && !empty($filename)) {
                $filePath = $kbPath . '/' . $filename . '.txt';

                if ($action === 'save') {
                    if (file_put_contents($filePath, $content) !== false) {
                        $successMessage = "File '$filename.txt' saved successfully.";
                    } else {
                        $errorMessage = "Failed to save file. Check directory permissions.";
                    }
                } elseif ($action === 'delete') {
                    if (file_exists($filePath) && unlink($filePath)) {
                        $successMessage = "File '$filename.txt' deleted successfully.";
                    } else {
                        $errorMessage = "Failed to delete file.";
                    }
                }
            } else {
                if ($action === 'save')
                    $errorMessage = "Filename is required.";
            }
        }

        // List files
        $files = [];
        $dir = new \DirectoryIterator($kbPath);
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->getExtension() === 'txt') {
                $files[$fileinfo->getFilename()] = file_get_contents($fileinfo->getPathname());
            }
        }
        ksort($files);

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=knowledgebase';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        <style>

            .kb-file-card {
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 15px;
                margin-bottom: 15px;
                background: #fafafa;
            }

            .kb-file-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }
        </style>

        <?php echo $this->getNavigationMarkup('knowledgebase'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom: 10px;">Knowledgebase & AI Rules</h2>
            <p class="text-muted" style="margin-bottom: 25px;">Create text files below containing context, rules, and facts you
                want Sahdev AI to always know about when replying to users. It reads all <code>.txt</code> files here
                automatically.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Add New File Form -->
            <div class="kb-file-card" style="border-left: 4px solid #198754; background: #f8fff9;">
                <h4 style="margin-top:0;"><i class="fas fa-plus-circle"></i> Create New Rule / File</h4>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="kb_action" value="save">
                    <div class="row">
                        <div class="col-md-3">
                            <label>Filename (No spaces, no extension)</label>
                            <input type="text" name="filename" class="form-control" placeholder="e.g. migration_rules" required
                                pattern="[a-zA-Z0-9_-]+">
                        </div>
                        <div class="col-md-9">
                            <label>File Content (Rules, Context, Fact Sheet)</label>
                            <textarea name="file_content" class="form-control" rows="4" required
                                placeholder="Enter instructions like 'If a user asks about migration, tell them it costs $50...'"></textarea>
                        </div>
                    </div>
                    <div style="margin-top: 10px; text-align: right;">
                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> Save File</button>
                    </div>
                </form>
            </div>

            <hr style="margin: 30px 0;">

            <!-- List Existing Files -->
            <h4 style="margin-bottom: 15px;">Existing Knowledgebase Files</h4>
            <?php if (empty($files)): ?>
                <div class="alert alert-info">No knowledgebase files found. Create one above!</div>
            <?php else: ?>
                <?php foreach ($files as $fullname => $content): ?>
                    <?php $basename = pathinfo($fullname, PATHINFO_FILENAME); ?>
                    <div class="kb-file-card">
                        <form method="post" action="<?php echo $actionUrl; ?>">
                            <?php echo $csrfToken; ?>
                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($basename); ?>">

                            <div class="kb-file-header">
                                <strong><i class="far fa-file-alt"></i> <?php echo htmlspecialchars($fullname); ?></strong>
                                <div>
                                    <button type="submit" name="kb_action" value="save" class="btn btn-xs btn-primary"><i
                                            class="fas fa-save"></i> Update</button>
                                    <button type="submit" name="kb_action" value="delete" class="btn btn-xs btn-danger"
                                        onclick="return confirm('Delete <?php echo htmlspecialchars($fullname); ?>?');"><i
                                            class="fas fa-trash"></i> Delete</button>
                                </div>
                            </div>
                            <textarea name="file_content" class="form-control"
                                rows="4"><?php echo htmlspecialchars($content); ?></textarea>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
    /**
     * Prompt Manager View — Full Prompt Template Library
     * Allows admins to view, edit, reset, create presets, and import/export all AI system prompts.
     */
    public function prompt_manager()
    {
        // --- Ensure tables exist (inline migration) ---
        try {
            Capsule::table('tblsahdev_prompt_templates')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_prompt_templates', function ($table) {
                $table->increments('id');
                $table->string('prompt_key', 64)->index();
                $table->string('label', 128);
                $table->text('description')->nullable();
                $table->longText('default_content');
                $table->longText('content');
                $table->timestamps();
            });
            $this->seedDefaultPromptTemplates();
        }
        try {
            Capsule::table('tblsahdev_prompt_presets')->first();
        } catch (\Exception $e) {
            Capsule::schema()->create('tblsahdev_prompt_presets', function ($table) {
                $table->increments('id');
                $table->string('name', 128);
                $table->longText('prompt_snapshots');
                $table->timestamps();
            });
        }

        $successMessage = '';
        $errorMessage   = '';

        // ---- Handle POST actions ----
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");
            $action = $_POST['prompt_mgr_action'] ?? '';

            // --- Export: stream JSON and exit ---
            if ($action === 'export_prompts') {
                $rows = Capsule::table('tblsahdev_prompt_templates')->get();
                $export = [];
                foreach ($rows as $row) {
                    $export[$row->prompt_key] = $row->content;
                }
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="sahdev_prompts_' . date('Ymd_His') . '.json"');
                echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }

            // --- Import from uploaded JSON ---
            if ($action === 'import_prompts') {
                if (!empty($_FILES['import_file']['tmp_name'])) {
                    $raw  = file_get_contents($_FILES['import_file']['tmp_name']);
                    $data = json_decode($raw, true);
                    if (!is_array($data)) {
                        $errorMessage = 'Invalid JSON file. Import aborted.';
                    } else {
                        $validKeys = array_keys($this->getDefaultPromptDefinitions());
                        $imported  = 0;
                        foreach ($data as $key => $text) {
                            if (in_array($key, $validKeys, true)) {
                                Capsule::table('tblsahdev_prompt_templates')
                                    ->where('prompt_key', $key)
                                    ->update(['content' => $text, 'updated_at' => \Carbon\Carbon::now()]);
                                // Sync back to settings for the two legacy columns
                                if ($key === 'system_default') {
                                    Capsule::table('tblsahdev_settings')->where('id', 1)->update(['system_prompt' => $text]);
                                } elseif ($key === 'user_prompt_template') {
                                    Capsule::table('tblsahdev_settings')->where('id', 1)->update(['user_prompt_template' => $text]);
                                }
                                $imported++;
                            }
                        }
                        $successMessage = "Imported {$imported} prompt(s) successfully.";
                    }
                } else {
                    $errorMessage = 'No file uploaded.';
                }
            }

            // --- Save single prompt ---
            if ($action === 'save_prompt') {
                $key  = $_POST['prompt_key'] ?? '';
                $text = $_POST['prompt_content'] ?? '';
                $validKeys = array_keys($this->getDefaultPromptDefinitions());
                if (in_array($key, $validKeys, true)) {
                    Capsule::table('tblsahdev_prompt_templates')
                        ->where('prompt_key', $key)
                        ->update(['content' => $text, 'updated_at' => \Carbon\Carbon::now()]);
                    // Sync back to legacy columns in settings
                    if ($key === 'system_default') {
                        Capsule::table('tblsahdev_settings')->where('id', 1)->update(['system_prompt' => $text, 'updated_at' => \Carbon\Carbon::now()]);
                    } elseif ($key === 'user_prompt_template') {
                        Capsule::table('tblsahdev_settings')->where('id', 1)->update(['user_prompt_template' => $text, 'updated_at' => \Carbon\Carbon::now()]);
                    }
                    $successMessage = "Prompt saved successfully.";
                } else {
                    $errorMessage = "Invalid prompt key.";
                }
            }

            // --- Reset single prompt to default ---
            if ($action === 'reset_prompt') {
                $key      = $_POST['prompt_key'] ?? '';
                $defaults = $this->getDefaultPromptDefinitions();
                if (isset($defaults[$key])) {
                    $defaultText = $defaults[$key]['content'];
                    Capsule::table('tblsahdev_prompt_templates')
                        ->where('prompt_key', $key)
                        ->update(['content' => $defaultText, 'updated_at' => \Carbon\Carbon::now()]);
                    if ($key === 'system_default') {
                        Capsule::table('tblsahdev_settings')->where('id', 1)->update(['system_prompt' => $defaultText, 'updated_at' => \Carbon\Carbon::now()]);
                    } elseif ($key === 'user_prompt_template') {
                        Capsule::table('tblsahdev_settings')->where('id', 1)->update(['user_prompt_template' => $defaultText, 'updated_at' => \Carbon\Carbon::now()]);
                    }
                    $successMessage = "Prompt reset to its factory default.";
                } else {
                    $errorMessage = "Invalid prompt key.";
                }
            }

            // --- Reset ALL prompts to defaults ---
            if ($action === 'reset_all') {
                $defaults = $this->getDefaultPromptDefinitions();
                $now = \Carbon\Carbon::now();
                foreach ($defaults as $key => $def) {
                    Capsule::table('tblsahdev_prompt_templates')
                        ->where('prompt_key', $key)
                        ->update(['content' => $def['content'], 'updated_at' => $now]);
                }
                Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                    'system_prompt'        => $defaults['system_default']['content'],
                    'user_prompt_template' => $defaults['user_prompt_template']['content'],
                    'updated_at'           => $now,
                ]);
                $successMessage = "All prompts have been reset to their factory defaults.";
            }

            // --- Save current prompts as a named preset ---
            if ($action === 'save_preset') {
                $name = trim($_POST['preset_name'] ?? '');
                if (empty($name)) {
                    $errorMessage = "Preset name is required.";
                } else {
                    $rows = Capsule::table('tblsahdev_prompt_templates')->get();
                    $snapshot = [];
                    foreach ($rows as $row) {
                        $snapshot[$row->prompt_key] = $row->content;
                    }
                    Capsule::table('tblsahdev_prompt_presets')->insert([
                        'name'             => $name,
                        'prompt_snapshots' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                        'created_at'       => \Carbon\Carbon::now(),
                        'updated_at'       => \Carbon\Carbon::now(),
                    ]);
                    $successMessage = "Preset \"{$name}\" saved successfully.";
                }
            }

            // --- Load a preset ---
            if ($action === 'load_preset') {
                $presetId = (int) ($_POST['preset_id'] ?? 0);
                $preset   = Capsule::table('tblsahdev_prompt_presets')->where('id', $presetId)->first();
                if (!$preset) {
                    $errorMessage = "Preset not found.";
                } else {
                    $snapshot = json_decode($preset->prompt_snapshots, true);
                    $validKeys = array_keys($this->getDefaultPromptDefinitions());
                    $now = \Carbon\Carbon::now();
                    foreach ($snapshot as $key => $text) {
                        if (in_array($key, $validKeys, true)) {
                            Capsule::table('tblsahdev_prompt_templates')
                                ->where('prompt_key', $key)
                                ->update(['content' => $text, 'updated_at' => $now]);
                            if ($key === 'system_default') {
                                Capsule::table('tblsahdev_settings')->where('id', 1)->update(['system_prompt' => $text]);
                            } elseif ($key === 'user_prompt_template') {
                                Capsule::table('tblsahdev_settings')->where('id', 1)->update(['user_prompt_template' => $text]);
                            }
                        }
                    }
                    $successMessage = "Preset \"{$preset->name}\" applied to all prompts.";
                }
            }

            // --- Delete a preset ---
            if ($action === 'delete_preset') {
                $presetId = (int) ($_POST['preset_id'] ?? 0);
                $preset   = Capsule::table('tblsahdev_prompt_presets')->where('id', $presetId)->first();
                if ($preset) {
                    Capsule::table('tblsahdev_prompt_presets')->where('id', $presetId)->delete();
                    $successMessage = "Preset \"{$preset->name}\" deleted.";
                } else {
                    $errorMessage = "Preset not found.";
                }
            }
        }

        // ---- Load data for view ----
        $templates = [];
        $rows = Capsule::table('tblsahdev_prompt_templates')->get();
        foreach ($rows as $row) {
            $templates[$row->prompt_key] = $row;
        }
        // Ensure all defined keys exist in the table (in case new keys were added in an update)
        $defaultDefs = $this->getDefaultPromptDefinitions();
        $now = \Carbon\Carbon::now();
        foreach ($defaultDefs as $key => $def) {
            if (!isset($templates[$key])) {
                $id = Capsule::table('tblsahdev_prompt_templates')->insertGetId([
                    'prompt_key'      => $key,
                    'label'           => $def['label'],
                    'description'     => $def['description'],
                    'default_content' => $def['content'],
                    'content'         => $def['content'],
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                $templates[$key] = Capsule::table('tblsahdev_prompt_templates')->where('id', $id)->first();
            }
        }

        $presets    = Capsule::table('tblsahdev_prompt_presets')->orderBy('id', 'desc')->get();
        $csrfToken  = generate_token("form");
        $actionUrl  = htmlspecialchars($this->moduleVars['modulelink']) . '&action=prompt_manager';

        ob_start();
        ?>
        <style>
            .pm-card {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                margin-bottom: 18px;
                overflow: hidden;
                box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            }
            .pm-card-header {
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
                padding: 14px 18px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                cursor: pointer;
                user-select: none;
            }
            .pm-card-header:hover { background: #f0f4f8; }
            .pm-card-header h5 { margin:0; font-size:14px; font-weight:600; color:#2d3748; }
            .pm-card-header .pm-desc { font-size:12px; color:#718096; margin-top:3px; }
            .pm-card-header .pm-badge {
                font-size:11px;
                background:#e2e8f0;
                color:#4a5568;
                padding:3px 8px;
                border-radius:20px;
                white-space:nowrap;
            }
            .pm-card-body { padding: 16px 18px; display: none; }
            .pm-card-body.open { display: block; }
            .pm-textarea {
                font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace;
                font-size: 12.5px;
                background: #1a202c;
                color: #e2e8f0;
                border: 1px solid #4a5568;
                border-radius: 6px;
                width: 100%;
                box-sizing: border-box;
                padding: 12px;
                resize: vertical;
                line-height: 1.6;
                min-height: 160px;
            }
            .pm-tag {
                display: inline-block;
                background: #2d3748;
                color: #90cdf4;
                padding: 2px 7px;
                border-radius: 4px;
                font-size: 11px;
                font-family: monospace;
                margin: 1px;
            }
            .pm-panel {
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 18px;
                margin-bottom: 20px;
                box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            }
            .pm-panel h5 { margin: 0 0 14px; font-size: 14px; font-weight: 600; color: #2d3748; }
        </style>

        <?php echo $this->getNavigationMarkup('prompt_manager'); ?>
        <div class="sahdev-page-container" style="max-width:1200px;">

            <h2 style="margin-bottom:5px;">🔬 Prompt Manager — Template Library</h2>
            <p class="text-muted" style="margin-bottom:20px;">
                Edit, reset, and manage all AI system prompts used across Sahdev. Create
                <strong>presets</strong> to switch between prompt configurations instantly, and use
                <strong>export/import</strong> to back up or share prompt sets.
            </p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Presets & Import/Export Panel -->
            <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px;">

                <!-- Presets Panel -->
                <div class="pm-panel" style="flex:1; min-width:280px;">
                    <h5><i class="fas fa-layer-group" style="color:#667eea;"></i> Presets</h5>
                    <?php if ($presets->isEmpty()): ?>
                        <p class="text-muted" style="font-size:13px; margin-bottom:12px;">No presets saved yet. Save the current configuration as a preset below.</p>
                    <?php else: ?>
                        <form method="post" action="<?php echo $actionUrl; ?>" style="margin-bottom:12px;">
                            <?php echo $csrfToken; ?>
                            <input type="hidden" name="prompt_mgr_action" value="load_preset">
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <select name="preset_id" class="form-control" style="flex:1; min-width:160px;">
                                    <?php foreach ($presets as $p): ?>
                                        <option value="<?php echo $p->id; ?>"><?php echo htmlspecialchars($p->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-primary" title="Apply Preset"><i class="fas fa-play"></i> Apply</button>
                            </div>
                        </form>
                        <form method="post" action="<?php echo $actionUrl; ?>" style="margin-bottom:12px;">
                            <?php echo $csrfToken; ?>
                            <input type="hidden" name="prompt_mgr_action" value="delete_preset">
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <select name="preset_id" class="form-control" style="flex:1; min-width:160px;">
                                    <?php foreach ($presets as $p): ?>
                                        <option value="<?php echo $p->id; ?>"><?php echo htmlspecialchars($p->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this preset?')" title="Delete Preset"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="prompt_mgr_action" value="save_preset">
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <input type="text" name="preset_name" class="form-control" placeholder="New preset name…" style="flex:1;">
                            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> Save Current</button>
                        </div>
                    </form>
                </div>

                <!-- Import/Export Panel -->
                <div class="pm-panel" style="flex:1; min-width:280px;">
                    <h5><i class="fas fa-exchange-alt" style="color:#48bb78;"></i> Import / Export</h5>
                    <form method="post" action="<?php echo $actionUrl; ?>" style="margin-bottom:12px;">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="prompt_mgr_action" value="export_prompts">
                        <button type="submit" class="btn btn-sm btn-default" style="width:100%;"><i class="fas fa-download"></i> Export All Prompts as JSON</button>
                    </form>
                    <form method="post" action="<?php echo $actionUrl; ?>" enctype="multipart/form-data">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="prompt_mgr_action" value="import_prompts">
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <input type="file" name="import_file" accept=".json" class="form-control" style="flex:1;">
                            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('This will overwrite all matching prompts. Continue?')"><i class="fas fa-upload"></i> Import</button>
                        </div>
                        <small class="text-muted" style="font-size:11px;">Upload a JSON file previously exported from Sahdev.</small>
                    </form>
                    <hr style="margin:12px 0;">
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="prompt_mgr_action" value="reset_all">
                        <button type="submit" class="btn btn-sm btn-danger" style="width:100%;"
                            onclick="return confirm('Reset ALL prompts to factory defaults? This cannot be undone unless you have a preset or export.')">
                            <i class="fas fa-redo"></i> Reset All to Factory Defaults
                        </button>
                    </form>
                </div>
            </div>

            <h4 style="margin-bottom:15px; font-size:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <i class="fas fa-magic"></i> Prompt Templates
            </h4>
            <p class="text-muted" style="font-size:12px; margin:-8px 0 16px;">
                <strong>Ticket Insights (cron)</strong> — <code>cron_insights_system</code> sets the AI persona; <code>cron_insights</code> is the user turn with JSON schema. Both are used by the WHMCS CronJob batch and “analyze” actions on the Support Tickets list.
            </p>

            <?php
            // Define placeholder hints for each key
            $placeholderHints = [
                'system_default'       => [],
                'user_prompt_template' => ['{{CUSTOM_INSTRUCTION_BLOCK}}','{{TONE}}','{{CLIENT_NAME}}','{{DEPARTMENT}}','{{SUBJECT}}','{{SERVICES_BLOCK}}','{{MESSAGES}}','{{ATTACHMENTS_BLOCK}}'],
                'summarizer'           => [],
                'historical_context'   => [],
                'rewrite_reply'        => ['{{TONE}}','{{SUBJECT}}','{{CLIENT_NAME}}','{{EXTRA_INSTRUCTION}}','{{DRAFT}}'],
                'score_reply'          => [],
                'canned_template'      => ['{{DRAFT}}'],
                'cron_insights_system' => [],
                'cron_insights'        => ['{{CLIENT_NAME}}','{{DEPARTMENT}}','{{SUBJECT}}','{{MESSAGES}}'],
                'tools_evidence_system' => [],
                'tools_evidence_user' => ['{{RAW_TOOL_OUTPUT}}'],
                'tools_reply_context_wrapper' => ['{{TOOLS_EVIDENCE}}'],
                'autopilot_system'     => [],
            ];
            $keyIcons = [
                'system_default'       => 'fas fa-robot',
                'user_prompt_template' => 'fas fa-file-code',
                'summarizer'           => 'fas fa-compress-alt',
                'historical_context'   => 'fas fa-history',
                'rewrite_reply'        => 'fas fa-pen-fancy',
                'score_reply'          => 'fas fa-star-half-alt',
                'canned_template'      => 'fas fa-clone',
                'cron_insights_system' => 'fas fa-user-shield',
                'cron_insights'        => 'fas fa-clock',
                'tools_evidence_system' => 'fas fa-filter',
                'tools_evidence_user' => 'fas fa-stream',
                'tools_reply_context_wrapper' => 'fas fa-box-open',
                'autopilot_system'     => 'fas fa-plane-arrival',
            ];
            $orderedKeys = [
                'system_default',
                'user_prompt_template',
                'summarizer',
                'historical_context',
                'rewrite_reply',
                'score_reply',
                'canned_template',
                'cron_insights_system',
                'cron_insights',
                'tools_evidence_system',
                'tools_evidence_user',
                'tools_reply_context_wrapper',
                'autopilot_system',
            ];
            foreach ($orderedKeys as $key):
                if (!isset($templates[$key])) continue;
                $tpl   = $templates[$key];
                $hints = $placeholderHints[$key] ?? [];
                $icon  = $keyIcons[$key] ?? 'fas fa-terminal';
                $isModified = ($tpl->content !== $tpl->default_content);
            ?>
            <div class="pm-card">
                <div class="pm-card-header" onclick="this.nextElementSibling.classList.toggle('open')">
                    <div>
                        <h5><i class="<?php echo $icon; ?>" style="margin-right:7px; color:#667eea;"></i><?php echo htmlspecialchars($tpl->label); ?></h5>
                        <div class="pm-desc"><?php echo htmlspecialchars($tpl->description ?? ''); ?></div>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <?php if ($isModified): ?>
                            <span class="pm-badge" style="background:#fef3c7; color:#92400e;"><i class="fas fa-pencil-alt"></i> Modified</span>
                        <?php else: ?>
                            <span class="pm-badge" style="background:#d1fae5; color:#065f46;"><i class="fas fa-check"></i> Default</span>
                        <?php endif; ?>
                        <span class="pm-badge"><code style="font-size:10px;"><?php echo htmlspecialchars($key); ?></code></span>
                        <i class="fas fa-chevron-down" style="color:#a0aec0; font-size:12px;"></i>
                    </div>
                </div>
                <div class="pm-card-body">
                    <?php if (!empty($hints)): ?>
                        <div style="margin-bottom:10px; background:#2d3748; padding:8px 12px; border-radius:6px;">
                            <small style="color:#a0aec0; font-size:11px;"><strong>Placeholders:</strong> </small>
                            <?php foreach ($hints as $ph): ?>
                                <span class="pm-tag"><?php echo htmlspecialchars($ph); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="prompt_mgr_action" value="save_prompt">
                        <input type="hidden" name="prompt_key" value="<?php echo htmlspecialchars($key); ?>">
                        <textarea name="prompt_content" class="pm-textarea" rows="<?php
                            echo $key === 'user_prompt_template' ? 22 : (strpos($key, 'cron_insights') === 0 ? 18 : 10);
                        ?>"><?php echo htmlspecialchars($tpl->content); ?></textarea>
                        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:10px; flex-wrap:wrap;">
                            <small class="text-muted" style="align-self:center; flex:1; font-size:11px;">Last updated: <?php echo $tpl->updated_at; ?></small>
                            <button type="submit" name="prompt_mgr_action" value="reset_prompt" class="btn btn-xs btn-default"
                                onclick="return confirm('Reset this prompt to its factory default?')">
                                <i class="fas fa-undo"></i> Reset to Default
                            </button>
                            <button type="submit" name="prompt_mgr_action" value="save_prompt" class="btn btn-xs btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
        <script>
        // Auto-open first card
        (function(){
            var first = document.querySelector('.pm-card-body');
            if (first) first.classList.add('open');
        })();
        </script>
        <?php
        return ob_get_clean();
    }





    /**
     * Ticket Summaries Manager View
     */
    public function summaries()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");
            $action = $_POST['summary_action'] ?? '';

            if ($action === 'delete') {
                $id = (int) $_POST['summary_id'];
                Capsule::table('tblsahdev_summaries')->where('id', $id)->delete();
                $successMessage = "Summary deleted successfully.";
            }
        }

        // Fetch summaries with ticket and admin info
        $summaries = Capsule::table('tblsahdev_summaries')
            ->leftJoin('tbltickets', 'tblsahdev_summaries.ticket_id', '=', 'tbltickets.id')
            ->leftJoin('tbladmins', 'tblsahdev_summaries.admin_id', '=', 'tbladmins.id')
            ->select(
                'tblsahdev_summaries.*',
                'tbltickets.tid as ticket_mask',
                'tbltickets.title as ticket_title',
                'tbladmins.username as admin_username'
            )
            ->orderBy('tblsahdev_summaries.id', 'desc')
            ->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=summaries';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        
        <?php echo $this->getNavigationMarkup('summaries'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom:5px;">✨ Adaptive Ticket Summaries</h2>
            <p class="text-muted" style="margin-bottom:20px;">Manage AI-generated conversation summaries. These summaries replace the full message history in AI prompts for long tickets, saving massive amounts of input tokens.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            
            <table class="table table-striped table-bordered text-center" style="font-size:13px;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th width="80" class="text-center">ID</th>
                        <th width="120" class="text-center">Ticket</th>
                        <th>AI Condensed Summary</th>
                        <th width="150" class="text-center">Generated By</th>
                        <th width="150" class="text-center">Date Date</th>
                        <th width="100" class="text-center">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($summaries->isEmpty()): ?>
                        <tr><td colspan="6" class="text-muted py-4">No AI summaries have been generated yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($summaries as $s): ?>
                            <tr>
                                <td><?php echo $s->id; ?></td>
                                <td>
                                    <a href="supporttickets.php?action=view&id=<?php echo $s->ticket_id; ?>" target="_blank">
                                        #<?php echo htmlspecialchars($s->ticket_mask ?? $s->ticket_id); ?>
                                    </a>
                                </td>
                                <td class="text-left">
                                    <div style="max-height:80px; overflow-y:auto; font-size:12px; white-space:pre-wrap; color:#333;"><?php echo htmlspecialchars($s->summary); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($s->admin_username ?? 'System'); ?></td>
                                <td><span class="text-muted" style="font-size:11px;"><?php echo $s->updated_at; ?></span></td>
                                <td>
                                    <form method="post" action="<?php echo $actionUrl; ?>" style="display:inline;">
                                        <?php echo $csrfToken; ?>
                                        <input type="hidden" name="summary_action" value="delete">
                                        <input type="hidden" name="summary_id" value="<?php echo $s->id; ?>">
                                        <button type="submit" class="btn btn-xs btn-danger" onclick="return confirm('Delete this summary?');"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AI Audit Trail Manager View
     */
    public function audit_trail()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");
            $action = $_POST['audit_action'] ?? '';

            if ($action === 'delete_range') {
                $days = (int) $_POST['delete_days'];
                if ($days > 0) {
                    $cutoffDate = \Carbon\Carbon::now()->subDays($days);
                    $deleted = Capsule::table('tblsahdev_audit_trail')
                        ->where('created_at', '<', $cutoffDate)
                        ->delete();
                    $successMessage = "Successfully deleted {$deleted} audit log entries older than {$days} days.";
                }
            } elseif ($action === 'delete_single') {
                $id = (int) $_POST['log_id'];
                Capsule::table('tblsahdev_audit_trail')->where('id', $id)->delete();
                $successMessage = "Audit log entry deleted.";
            }
        }

        // Fetch recent audit logs
        $logs = Capsule::table('tblsahdev_audit_trail')
            ->leftJoin('tbltickets', 'tblsahdev_audit_trail.ticket_id', '=', 'tbltickets.id')
            ->leftJoin('tbladmins', 'tblsahdev_audit_trail.admin_id', '=', 'tbladmins.id')
            ->select(
                'tblsahdev_audit_trail.*',
                'tbltickets.tid as ticket_mask',
                'tbltickets.title as ticket_title',
                'tbladmins.username as admin_username'
            )
            ->orderBy('tblsahdev_audit_trail.id', 'desc')
            ->limit(500) // Hard limit to prevent memory exhaustion, should implement real pagination later
            ->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=audit_trail';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        <style>
            .audit-code-block { background: #1e1e2e; color: #cdd6f4; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 11px; max-height: 200px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; margin: 0; }
        </style>
        <?php echo $this->getNavigationMarkup('audit_trail'); ?>
        <div class="sahdev-page-container">

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <div>
                    <h2 style="margin-bottom:5px; margin-top:0;">🕵️ AI Audit Trail</h2>
                    <p class="text-muted" style="margin-bottom:0px;">Complete record of what is sent to and received from the AI models. Showing last 500 entries.</p>
                </div>
                <div style="background:#f8d7da; padding:10px 15px; border-radius:6px; border:1px solid #f5c6cb;">
                    <form method="post" action="<?php echo $actionUrl; ?>" class="form-inline" style="margin:0;" onsubmit="return confirm('WARNING: This will permanently delete old audit logs. Proceed?');">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="audit_action" value="delete_range">
                        <label style="color:#721c24; margin-right:10px; font-weight:600;"><i class="fas fa-trash-alt"></i> Auto-Prune Logs:</label>
                        <select name="delete_days" class="form-control input-sm" style="margin-right:10px;">
                            <option value="7">Older than 7 days</option>
                            <option value="15">Older than 15 days</option>
                            <option value="30" selected>Older than 30 days</option>
                            <option value="90">Older than 90 days</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-danger">Prune Now</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            
            <table class="table table-bordered table-striped" style="font-size:12px; table-layout: fixed;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th width="80" class="text-center">ID / Date</th>
                        <th width="120" class="text-center">Context</th>
                        <th width="35%">Raw Prompt Sent to AI</th>
                        <th width="35%">Raw AI Response</th>
                        <th width="120" class="text-center">Action / Metrics</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs->isEmpty()): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No audit trail logs found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="text-center">
                                    <strong>#<?php echo $log->id; ?></strong><br>
                                    <span class="text-muted" style="font-size:10px;"><?php echo $log->created_at; ?></span>
                                </td>
                                <td>
                                    <?php if ($log->ticket_id): ?>
                                        <a href="supporttickets.php?action=view&id=<?php echo $log->ticket_id; ?>" target="_blank" style="font-weight:600;">
                                            Ticket #<?php echo htmlspecialchars($log->ticket_mask ?? $log->ticket_id); ?>
                                        </a><br>
                                    <?php else: ?>
                                        <span class="text-muted">No Ticket</span><br>
                                    <?php endif; ?>
                                    <span style="font-size:11px;">Admin: <?php echo htmlspecialchars($log->admin_username ?? 'System'); ?></span>
                                </td>
                                <td>
                                    <pre class="audit-code-block"><?php echo htmlspecialchars($log->prompt_text ?? ''); ?></pre>
                                </td>
                                <td>
                                    <pre class="audit-code-block" style="background:#1e3a2e;"><?php echo htmlspecialchars($log->response_text ?? ''); ?></pre>
                                </td>
                                <td class="text-center">
                                    <span class="label label-primary" style="display:block; margin-bottom:4px;"><?php echo strtoupper($log->action_type); ?></span>
                                    <span class="label label-info" style="display:block; margin-bottom:4px;"><?php echo htmlspecialchars($log->provider_used ?? 'Unknown'); ?></span>
                                    <div style="font-size:10px; margin-top:6px; color:#555;">
                                        Tokens: <?php echo number_format($log->tokens_used); ?><br>
                                        Time: <?php echo number_format($log->execution_time_ms); ?>ms
                                    </div>
                                    <form method="post" action="<?php echo $actionUrl; ?>" style="margin-top:8px;">
                                        <?php echo $csrfToken; ?>
                                        <input type="hidden" name="audit_action" value="delete_single">
                                        <input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                                        <button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Delete this specific log entry?');"><i class="fas fa-trash text-danger"></i> Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Sahdev module diagnostic log (enrichment failures, etc.).
     */
    public function module_logs()
    {
        $successMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");
            $action = $_POST['module_log_action'] ?? '';

            if ($action === 'delete_range') {
                $days = (int) ($_POST['delete_days'] ?? 0);
                if ($days > 0 && Capsule::schema()->hasTable('tblsahdev_module_logs')) {
                    $cutoffDate = \Carbon\Carbon::now()->subDays($days);
                    $deleted = Capsule::table('tblsahdev_module_logs')
                        ->where('created_at', '<', $cutoffDate)
                        ->delete();
                    $successMessage = "Deleted {$deleted} module log entries older than {$days} days.";
                }
            } elseif ($action === 'delete_single') {
                $id = (int) ($_POST['log_id'] ?? 0);
                if ($id > 0 && Capsule::schema()->hasTable('tblsahdev_module_logs')) {
                    Capsule::table('tblsahdev_module_logs')->where('id', $id)->delete();
                    $successMessage = 'Log entry deleted.';
                }
            }
        }

        $logs = null;
        if (Capsule::schema()->hasTable('tblsahdev_module_logs')) {
            $logs = Capsule::table('tblsahdev_module_logs')
                ->leftJoin('tbltickets', 'tblsahdev_module_logs.ticket_id', '=', 'tbltickets.id')
                ->select(
                    'tblsahdev_module_logs.*',
                    'tbltickets.tid as ticket_mask'
                )
                ->orderBy('tblsahdev_module_logs.id', 'desc')
                ->limit(500)
                ->get();
        }

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=module_logs';

        ob_start();
        ?>
        <?php echo $this->getNavigationMarkup('module_logs'); ?>
        <div class="sahdev-page-container">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom: 20px;">
                <div>
                    <h2 style="margin:0 0 6px 0;">Module diagnostic log</h2>
                    <p class="text-muted" style="margin:0; font-size:13px;">
                        Warnings and errors from Sahdev internals (e.g. account context enrichment). Does not include full AI prompts.
                    </p>
                </div>
                <div style="background:#fff3cd; padding:10px 15px; border-radius:6px; border:1px solid #ffc107;">
                    <form method="post" action="<?php echo $actionUrl; ?>" class="form-inline" style="margin:0;" onsubmit="return confirm('Permanently delete old module log rows?');">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="module_log_action" value="delete_range">
                        <label style="margin-right:10px; font-weight:600;">Prune:</label>
                        <select name="delete_days" class="form-control input-sm" style="margin-right:10px;">
                            <option value="7">Older than 7 days</option>
                            <option value="15">Older than 15 days</option>
                            <option value="30" selected>Older than 30 days</option>
                            <option value="90">Older than 90 days</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-warning">Prune now</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>

            <?php if (!Capsule::schema()->hasTable('tblsahdev_module_logs')): ?>
                <div class="alert alert-info">Table <code>tblsahdev_module_logs</code> is not present yet. Save module settings or re-run module activation to create it.</div>
            <?php else: ?>
            <table class="table table-bordered table-striped" style="font-size:12px;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th width="90">ID</th>
                        <th width="100">Level</th>
                        <th width="200">Source</th>
                        <th width="120">Ticket</th>
                        <th>Message</th>
                        <th width="160">Time</th>
                        <th width="90"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs === null || $logs->isEmpty()): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No module log entries yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo (int) $log->id; ?></td>
                                <td><span class="label <?php echo ($log->level === 'error') ? 'label-danger' : 'label-warning'; ?>"><?php echo htmlspecialchars($log->level ?? ''); ?></span></td>
                                <td style="word-break:break-all;"><?php echo htmlspecialchars($log->source ?? ''); ?></td>
                                <td>
                                    <?php if (!empty($log->ticket_id)): ?>
                                        <a href="supporttickets.php?action=view&id=<?php echo (int) $log->ticket_id; ?>" target="_blank">#<?php echo htmlspecialchars($log->ticket_mask ?? $log->ticket_id); ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="word-break:break-word; white-space:pre-wrap; font-family:monospace; font-size:11px;"><?php echo htmlspecialchars($log->message ?? ''); ?></td>
                                <td class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars((string) ($log->created_at ?? '')); ?></td>
                                <td>
                                    <form method="post" action="<?php echo $actionUrl; ?>" style="display:inline;">
                                        <?php echo $csrfToken; ?>
                                        <input type="hidden" name="module_log_action" value="delete_single">
                                        <input type="hidden" name="log_id" value="<?php echo (int) $log->id; ?>">
                                        <button type="submit" class="btn btn-xs btn-default" onclick="return confirm('Delete this row?');"><i class="fas fa-trash text-danger"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Canned Responses Manager View
     *
     * @return string
     */
    public function canned_responses()
    {
        $successMessage = '';
        $errorMessage = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token("WHMCS.admin.default");

            $action = $_POST['canned_action'] ?? '';

            if ($action === 'create' || $action === 'update') {
                $id = (int) ($_POST['canned_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $templateText = trim($_POST['template_text'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $tags = trim($_POST['tags'] ?? '');
                $adminId = $_SESSION['adminid'] ?? 1;

                if (empty($title) || empty($templateText)) {
                    $errorMessage = "Title and Template text are required.";
                } else {
                    $data = [
                        'title' => $title,
                        'template_text' => $templateText,
                        'category' => $category,
                        'tags' => $tags,
                        'updated_at' => \Carbon\Carbon::now(),
                    ];

                    if ($action === 'create') {
                        $data['admin_id'] = $adminId;
                        $data['created_at'] = \Carbon\Carbon::now();
                        Capsule::table('tblsahdev_canned_responses')->insert($data);
                        $successMessage = "Canned response created successfully.";
                    } else {
                        Capsule::table('tblsahdev_canned_responses')->where('id', $id)->update($data);
                        $successMessage = "Canned response updated successfully.";
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int) $_POST['canned_id'];
                Capsule::table('tblsahdev_canned_responses')->where('id', $id)->delete();
                $successMessage = "Canned response deleted.";
            }
        }

        $responses = Capsule::table('tblsahdev_canned_responses')
            ->orderBy('id', 'desc')
            ->get();

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=canned_responses';
        $settingsUrl = htmlspecialchars($this->moduleVars['modulelink']);

        ob_start();
        ?>
        <style>
            .canned-card { border: 1px solid #ddd; border-radius: 6px; padding: 15px; margin-bottom: 15px; background: #fafafa; }
            .canned-header { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        </style>
        <?php echo $this->getNavigationMarkup('canned_responses'); ?>
        <div class="sahdev-page-container">

            <h2 style="margin-bottom:10px;">💾 Canned Responses Manager</h2>
            <p class="text-muted" style="margin-bottom:25px;">Manage AI-generated or manual canned response templates. These are immediately searchable in the ticket view panel.</p>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Add New Form -->
            <div class="canned-card" style="border-left: 4px solid #f0ad4e; background: #fffdfa;">
                <div class="canned-header">
                    <h4 style="margin:0;"><i class="fas fa-plus-circle"></i> Add New Response</h4>
                </div>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="canned_action" value="create">
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label>Title</label>
                                <input type="text" name="title" class="form-control" placeholder="e.g. Server Restart Instructions" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label>Category</label>
                                <input type="text" name="category" class="form-control" placeholder="e.g. Sales, Technical, Billing">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-2">
                                <label>Tags (Comma separated)</label>
                                <input type="text" name="tags" class="form-control" placeholder="e.g. restart, down, emergency">
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-2">
                        <label>Template Text / HTML</label>
                        <textarea name="template_text" class="form-control" rows="5" placeholder="Insert your generalized response here... HTML is allowed." required></textarea>
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-save"></i> Save Template</button>
                    </div>
                </form>
            </div>

            <hr style="margin:30px 0;">
            <h4 style="margin-bottom:15px;">Existing Responses</h4>

            <?php foreach ($responses as $r): ?>
                <div class="canned-card">
                    <form method="post" action="<?php echo $actionUrl; ?>">
                        <?php echo $csrfToken; ?>
                        <input type="hidden" name="canned_id" value="<?php echo $r->id; ?>">
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-2">
                                    <label>Title</label>
                                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($r->title); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-2">
                                    <label>Category</label>
                                    <input type="text" name="category" class="form-control" value="<?php echo htmlspecialchars($r->category ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-2">
                                    <label>Tags (Comma separated)</label>
                                    <input type="text" name="tags" class="form-control" value="<?php echo htmlspecialchars($r->tags ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-2">
                            <label>Template Text / HTML</label>
                            <textarea name="template_text" class="form-control" rows="5" required><?php echo htmlspecialchars($r->template_text); ?></textarea>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
                            <button type="submit" name="canned_action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Delete this canned response?');"><i class="fas fa-trash"></i> Delete</button>
                            <button type="submit" name="canned_action" value="update" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Dashboard view for AI Performance Analytics (Feature 8).
     *
     * @return string
     */
    public function analytics()
    {
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        $settings = Capsule::table('tblsahdev_settings')->first();
        if ($adminId > 0 && !\Sahdev\Lib\AdminPreferences::featureEnabled(
            \Sahdev\Lib\AdminPreferences::FEATURE_ANALYTICS, $adminId, $settings
        )) {
            return $this->getNavigationMarkup('analytics')
                . '<div class="sahdev-page-container"><div class="alert alert-warning">You have disabled Analytics for your account under <a href="'
                . htmlspecialchars($this->moduleVars['modulelink']) . '&action=my_preferences">My Preferences</a>.</div></div>';
        }

        // ── Schema migrations ──────────────────────────────────────────────────
        foreach (['avg_handle_minutes' => 'integer', 'autopilot_draft_mode' => 'boolean'] as $col => $type) {
            try {
                Capsule::table('tblsahdev_settings')->select($col)->first();
            } catch (\Exception $e) {
                try {
                    Capsule::schema()->table('tblsahdev_settings', function ($t) use ($col, $type) {
                        if ($type === 'boolean') $t->boolean($col)->default(0);
                        else $t->integer($col)->default(8)->nullable();
                    });
                } catch (\Throwable $ex) {}
            }
        }

        $actionUrl  = htmlspecialchars($this->moduleVars['modulelink']) . '&action=analytics';

        // ── Handle settings save ───────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['roi_save'])) {
            check_token('WHMCS.admin.default');
            $newMin = max(1, min(120, (int) ($_POST['avg_handle_minutes'] ?? 8)));
            Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'avg_handle_minutes' => $newMin,
                'updated_at'         => \Carbon\Carbon::now(),
            ]);
            header('Location: ' . $actionUrl);
            exit;
        }

        $settings     = Capsule::table('tblsahdev_settings')->first();
        $avgHandleMin = max(1, (int) ($settings->avg_handle_minutes ?? 8));

        // ── AIController analytics ─────────────────────────────────────────────
        if (!class_exists('\Sahdev\Lib\AIController')) {
            require_once dirname(__DIR__) . '/lib/AIController.php';
        }
        $aiController  = new \Sahdev\Lib\AIController(0, $_SESSION['adminid'] ?? 1);
        $analytics     = $aiController->getAnalyticsData();
        $analyticsData = $analytics['data'] ?? [];

        $totalActions  = $analyticsData['total_actions'] ?? 0;
        $totalTokens   = $analyticsData['total_tokens']  ?? 0;
        $avgExecMs     = $analyticsData['avg_exec_time_ms'] ?? 0;
        $actionsByType = $analyticsData['action_breakdown'] ?? [];
        $scores        = $analyticsData['quality'] ?? [];
        $roiOld        = $analyticsData['roi'] ?? ['time_saved_string' => '0h 0m', 'estimated_cost_usd' => 0.00];
        $toolsNorm     = $analyticsData['tools_normalization'] ?? [];

        // ── Token & Cost: source of truth is tblsahdev_audit_trail ──────────────
        $nowTs = \Carbon\Carbon::now();
        $week  = $nowTs->copy()->subDays(7); // kept for time-saved KPI sub-stat only

        // ── Period filter ───────────────────────────────────────────────────────
        $validPeriods = ['day' => '1 Day', 'week' => '1 Week', 'month' => '1 Month', 'year' => '1 Year', 'lifetime' => 'Lifetime'];
        $selectedPeriod = in_array($_GET['period'] ?? '', array_keys($validPeriods)) ? $_GET['period'] : 'lifetime';
        if ($selectedPeriod === 'day') {
            $since = $nowTs->copy()->subDay()->startOfDay();
        } elseif ($selectedPeriod === 'week') {
            $since = $nowTs->copy()->subDays(7)->startOfDay();
        } elseif ($selectedPeriod === 'month') {
            $since = $nowTs->copy()->subDays(30)->startOfDay();
        } elseif ($selectedPeriod === 'year') {
            $since = $nowTs->copy()->subDays(365)->startOfDay();
        } else {
            $since = null; // lifetime = no date filter
        }
        $periodLabel = $validPeriods[$selectedPeriod];

        // Build provider cost-rate map: provider_type => avg_cost_per_1m_tokens
        $providerCostMap = []; // e.g. ['google' => 1.25, 'lmstudio' => 0.0]
        $providerNameMap = []; // e.g. ['google' => 'My Gemini Provider']
        foreach (Capsule::table('tblsahdev_providers')->where('is_active', 1)->get() as $p) {
            $pt = strtolower(trim($p->provider_type));
            $rate = (float) (($p->cost_input_1m + $p->cost_output_1m) / 2);
            if (!isset($providerCostMap[$pt]) || $rate > $providerCostMap[$pt]) {
                $providerCostMap[$pt] = $rate;
                $providerNameMap[$pt] = $p->name;
            }
        }
        // Keyword → type resolver for getName() strings
        $resolveType = function ($name) {
            $n = strtolower((string)$name);
            if (strpos($n, 'google') !== false || strpos($n, 'gemini') !== false) return 'google';
            if (strpos($n, 'lm studio') !== false || strpos($n, 'lmstudio') !== false) return 'lmstudio';
            if (strpos($n, 'replicate') !== false) return 'replicate';
            return $n; // fallback: use as-is
        };

        // Aggregate audit_trail for the selected period
        $auditQ = Capsule::table('tblsahdev_audit_trail')
            ->whereNotNull('tokens_used')->where('tokens_used', '>', 0);
        if ($since) $auditQ->where('created_at', '>=', $since);
        $auditByProvider = (clone $auditQ)
            ->selectRaw('provider_used, SUM(tokens_used) as tokens, COUNT(*) as calls')
            ->groupBy('provider_used')->get();
        $auditPeriod = (clone $auditQ)
            ->selectRaw('SUM(tokens_used) as tokens, COUNT(*) as calls')->first();

        // Autopilot tokens for the period
        $apQ = Capsule::table('tblsahdev_autopilot_log')
            ->whereNotNull('tokens_used')->where('tokens_used', '>', 0);
        if ($since) $apQ->where('created_at', '>=', $since);
        $apPeriod = (clone $apQ)->selectRaw('SUM(tokens_used) as tokens, COUNT(*) as calls')->first();

        // Autopilot replied/drafted for the period (time-saved calc)
        $apRepliesQ = Capsule::table('tblsahdev_autopilot_log')->whereIn('ai_decision', ['replied', 'drafted']);
        if ($since) $apRepliesQ->where('created_at', '>=', $since);
        $apRepliesPeriod = (clone $apRepliesQ)->count();
        // Also need all-time replied count for the Autopilot Processed card sub-stat
        $apRepliesAll  = Capsule::table('tblsahdev_autopilot_log')->whereIn('ai_decision', ['replied', 'drafted'])->count();

        // Unique tickets touched in the period
        $atQ = Capsule::table('tblsahdev_audit_trail')->whereNotNull('ticket_id');
        if ($since) $atQ->where('created_at', '>=', $since);
        $logsTicketsPeriod = (clone $atQ)->distinct()->pluck('ticket_id')->toArray();

        $apTQ = Capsule::table('tblsahdev_autopilot_log');
        if ($since) $apTQ->where('created_at', '>=', $since);
        $apTicketsPeriod = (clone $apTQ)->distinct()->pluck('ticket_id')->toArray();
        $ticketsPeriod = count(array_unique(array_merge($logsTicketsPeriod, $apTicketsPeriod)));

        // Autopilot all processing runs for the period
        $apProcQ = Capsule::table('tblsahdev_autopilot_log');
        if ($since) $apProcQ->where('created_at', '>=', $since);
        $apProcessedPeriod = (clone $apProcQ)->count();


        // Build provider rows + period totals
        $totalTokensAll  = 0;
        $totalCallsAll   = 0;
        $totalCostAll    = 0.0;
        $providerRows    = [];

        foreach ($auditByProvider as $row) {
            $tok   = (int) $row->tokens;
            $calls = (int) $row->calls;
            $totalTokensAll += $tok;
            $totalCallsAll  += $calls;
            $type      = $resolveType($row->provider_used);
            $rate      = $providerCostMap[$type] ?? 0.0;
            $cost      = round($tok / 1_000_000 * $rate, 6);
            $totalCostAll += $cost;
            $providerRows[] = [
                'name'   => htmlspecialchars($row->provider_used),
                'type'   => $type,
                'tokens' => $tok,
                'calls'  => $calls,
                'rate'   => $rate,
                'cost'   => $cost,
            ];
        }
        $totalTokensAll += (int) ($apPeriod->tokens ?? 0);
        $totalCallsAll  += (int) ($apPeriod->calls ?? 0);

        // Period cost via direct per-provider query
        $totalCostPeriod = $totalCostAll; // audit rows already filtered to period
        // Add autopilot tokens cost (estimate via blended rate)
        if ($totalTokensAll > 0) {
            $apBlendedRate = $totalTokensAll > 0 ? ($totalCostAll / max(1, $totalTokensAll - (int)($apPeriod->tokens ?? 0))) : 0;
            $totalCostPeriod += round((int)($apPeriod->tokens ?? 0) / 1_000_000 * $apBlendedRate, 6);
        }
        $totalCostAll   = $totalCostPeriod; // rename to single variable for display
        $totalTokensWeek = (int)(($auditPeriod->tokens ?? 0) + ($apPeriod->tokens ?? 0)); // period tokens

        $timeSavedAllH = round(($apRepliesPeriod * $avgHandleMin) / 60, 1);
        // "Replied" sub-stat: keep all-time replied for context on the Autopilot card

        // 30-day daily chart — use audit_trail as source of truth
        $dailyData = [];
        for ($d = 29; $d >= 0; $d--) {
            $dayStart = $nowTs->copy()->subDays($d)->startOfDay();
            $dayEnd   = $nowTs->copy()->subDays($d)->endOfDay();
            $tok  = (int) Capsule::table('tblsahdev_audit_trail')->whereBetween('created_at', [$dayStart, $dayEnd])->sum('tokens_used');
            $tok += (int) Capsule::table('tblsahdev_autopilot_log')->whereBetween('created_at', [$dayStart, $dayEnd])->sum('tokens_used');
            $dailyData[] = ['label' => $dayStart->format('d'), 'tokens' => $tok, 'date' => $dayStart->format('d M')];
        }
        $maxDayTokens = max(1, max(array_column($dailyData, 'tokens')));

        ob_start();
        ?>
        <?php echo $this->getNavigationMarkup('analytics'); ?>
        <div class="sahdev-page-container">
        <style>
        .sad-card{background:#fff;border-radius:12px;box-shadow:0 2px 14px rgba(0,0,0,.07);padding:24px 28px;margin-bottom:22px;border:1px solid #f0f2f7}
        .sad-section{font-size:1rem;font-weight:700;color:#1a2035;margin-bottom:16px;display:flex;align-items:center;gap:10px}
        .sad-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:16px;margin-bottom:24px}
        .sad-kpi{border-radius:12px;padding:20px 18px;position:relative;overflow:hidden;border:1px solid transparent}
        .sad-kpi .kpi-val{font-size:2rem;font-weight:800;line-height:1;margin:6px 0 2px}
        .sad-kpi .kpi-lbl{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;opacity:.75}
        .sad-kpi .kpi-sub{font-size:.7rem;opacity:.55;margin-top:2px}
        .sad-kpi .kpi-icon{position:absolute;right:14px;top:14px;font-size:1.6rem;opacity:.13}
        /* Color themes */
        .kpi-blue{background:linear-gradient(135deg,#e8f0fe,#fff);border-color:#c5d8ff}.kpi-blue .kpi-val{color:#1a56db}
        .kpi-purple{background:linear-gradient(135deg,#f5f0ff,#fff);border-color:#d4c5ff}.kpi-purple .kpi-val{color:#7c3aed}
        .kpi-green{background:linear-gradient(135deg,#e9fdf4,#fff);border-color:#b6f0cf}.kpi-green .kpi-val{color:#059669}
        .kpi-teal{background:linear-gradient(135deg,#e0fafb,#fff);border-color:#9aeaef}.kpi-teal .kpi-val{color:#0891b2}
        .kpi-amber{background:linear-gradient(135deg,#fffbeb,#fff);border-color:#fde68a}.kpi-amber .kpi-val{color:#d97706}
        .kpi-red{background:linear-gradient(135deg,#fef2f2,#fff);border-color:#fecaca}.kpi-red .kpi-val{color:#dc2626}
        .kpi-indigo{background:linear-gradient(135deg,#eef2ff,#fff);border-color:#c7d2fe}.kpi-indigo .kpi-val{color:#4f46e5}
        .kpi-slate{background:linear-gradient(135deg,#f8fafc,#fff);border-color:#e2e8f0}.kpi-slate .kpi-val{color:#475569}
        /* bar chart */
        .sad-bar-chart{display:flex;align-items:flex-end;gap:3px;height:100px;margin-top:10px;padding:0 4px}
        .sad-bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;min-width:0}
        .sad-bar{width:100%;background:linear-gradient(180deg,#3b82f6,#93c5fd);border-radius:3px 3px 0 0;min-height:2px;transition:height .3s}
        .sad-bar-lbl{font-size:.55rem;color:#94a3b8;white-space:nowrap}
        /* pills */
        .sad-pill{display:inline-block;padding:5px 12px;border-radius:999px;font-size:.8rem;font-weight:600}
        /* progress bar */
        .sad-prog-row{margin-bottom:12px}
        .sad-prog-header{display:flex;justify-content:space-between;font-size:.82rem;font-weight:600;margin-bottom:4px}
        .sad-prog-track{background:#e2e8f0;height:10px;border-radius:5px;overflow:hidden}
        .sad-prog-fill{height:100%;border-radius:5px}
        /* tag grid */
        .sad-tag-grid{display:flex;gap:10px;flex-wrap:wrap}
        /* table */
        .sad-table th{font-size:.75rem;color:#64748b;border:none;background:#f8fafc;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
        .sad-table td{font-size:.85rem;vertical-align:middle}
        /* tabs */
        .sad-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
        .sad-tab{padding:6px 16px;border-radius:99px;font-size:.82rem;font-weight:600;cursor:pointer;border:1.5px solid #e2e8f0;background:#f8fafc;color:#475569;transition:all .15s}
        .sad-tab.active{background:#3b82f6;color:#fff;border-color:#3b82f6}
        /* settings form */
        .sad-settings-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .sad-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:700;letter-spacing:.04em}
        .badge-live{background:#dcfce7;color:#16a34a}
        .badge-draft{background:#fef9c3;color:#ca8a04}
        .badge-skip{background:#f1f5f9;color:#64748b}
        @media(max-width:700px){.sad-kpi-grid{grid-template-columns:1fr 1fr}}
        </style>

        <h2 style="margin-bottom:4px">📊 AI Performance &amp; ROI Analytics</h2>
        <p class="text-muted" style="margin-bottom:22px;font-size:.9rem">Unified view of AI performance, token spend, cost, and time saved.</p>

        <!-- ── Period Selector (AJAX) ── -->
        <?php
        // Route through the sahdev_act=ajax_handler bypass so WHMCS does NOT wrap the output in admin page HTML
        $ajaxUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&sahdev_act=ajax_handler&action=get_analytics_period';
        ?>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:20px">
            <span style="font-size:.8rem;color:#64748b;font-weight:600;margin-right:4px">Period:</span>
            <?php
            $validPeriods = ['day' => '1 Day', 'week' => '1 Week', 'month' => '1 Month', 'year' => '1 Year', 'lifetime' => 'Lifetime'];
            foreach ($validPeriods as $pk => $pl):
            ?>
            <button class="sad-period-btn" data-period="<?= $pk ?>"
               style="padding:5px 16px;border-radius:99px;font-size:.8rem;font-weight:600;cursor:pointer;border:1.5px solid #e2e8f0;background:#f8fafc;color:#475569;transition:all .15s" id="sadbtn-<?= $pk ?>">
                <?= $pl ?>
            </button>
            <?php endforeach; ?>
            <span id="sad-period-loading" style="display:none;font-size:.8rem;color:#3b82f6"><i class="fas fa-circle-notch fa-spin"></i></span>
        </div>

        <!-- ── KPI Stat Grid ── -->
        <div class="sad-kpi-grid">
            <div class="sad-kpi kpi-blue">
                <i class="fas fa-bolt kpi-icon"></i>
                <div class="kpi-lbl">Total AI Actions</div>
                <div class="kpi-val" id="sad-k-actions"><?= number_format($totalActions) ?></div>
                <div class="kpi-sub">All time (audit trail)</div>
            </div>
            <div class="sad-kpi kpi-purple">
                <i class="fas fa-coins kpi-icon"></i>
                <div class="kpi-lbl">Tokens Used</div>
                <div class="kpi-val" id="sad-k-tokens">—</div>
                <div class="kpi-sub" id="sad-k-tokens-sub">Loading…</div>
            </div>
            <div class="sad-kpi kpi-red">
                <i class="fas fa-dollar-sign kpi-icon"></i>
                <div class="kpi-lbl">Estimated Cost</div>
                <div class="kpi-val" id="sad-k-cost">—</div>
                <div class="kpi-sub" id="sad-k-cost-sub">Loading…</div>
            </div>
            <div class="sad-kpi kpi-teal">
                <i class="fas fa-tachometer-alt kpi-icon"></i>
                <div class="kpi-lbl">Avg Generation</div>
                <div class="kpi-val"><?= number_format($avgExecMs) ?> <small style="font-size:.9rem">ms</small></div>
                <div class="kpi-sub">Per AI call · All time</div>
            </div>
            <div class="sad-kpi kpi-indigo">
                <i class="fas fa-ticket-alt kpi-icon"></i>
                <div class="kpi-lbl">Tickets Handled</div>
                <div class="kpi-val" id="sad-k-tickets">—</div>
                <div class="kpi-sub" id="sad-k-tickets-sub">Loading…</div>
            </div>
            <div class="sad-kpi kpi-amber">
                <i class="fas fa-robot kpi-icon"></i>
                <div class="kpi-lbl">Autopilot Processed</div>
                <div class="kpi-val" id="sad-k-ap">—</div>
                <div class="kpi-sub" id="sad-k-ap-sub">Loading…</div>
            </div>
            <div class="sad-kpi kpi-green">
                <i class="fas fa-clock kpi-icon"></i>
                <div class="kpi-lbl">Time Saved</div>
                <div class="kpi-val" id="sad-k-time">—</div>
                <div class="kpi-sub" id="sad-k-time-sub">Loading…</div>
            </div>
            <div class="sad-kpi kpi-slate">
                <i class="fas fa-hourglass-half kpi-icon"></i>
                <div class="kpi-lbl">ROI Time Saved</div>
                <div class="kpi-val"><?= htmlspecialchars($roiOld['time_saved_string'] ?? '—') ?></div>
                <div class="kpi-sub">From quality scorer data</div>
            </div>
        </div>

        <script>
        (function(){
            var ajaxUrl = <?= json_encode($ajaxUrl) ?>;
            var activePeriod = 'lifetime';

            function setActive(period) {
                document.querySelectorAll('.sad-period-btn').forEach(function(btn) {
                    var isActive = btn.dataset.period === period;
                    btn.style.background = isActive ? '#3b82f6' : '#f8fafc';
                    btn.style.color      = isActive ? '#fff'    : '#475569';
                    btn.style.borderColor= isActive ? '#3b82f6' : '#e2e8f0';
                });
            }

            function fmt(n) { return Number(n).toLocaleString(); }

            function loadPeriod(period) {
                activePeriod = period;
                setActive(period);
                document.getElementById('sad-period-loading').style.display = 'inline';

                fetch(ajaxUrl + '&period=' + period, {credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(d) {
                        document.getElementById('sad-period-loading').style.display = 'none';
                        if (!d || d.error) { console.error('Analytics data error', d); return; }
                        var lbl = d.period_label;
                        function up(id, val) {
                            var el = document.getElementById(id);
                            if (!el) return;
                            el.style.opacity = '0';
                            el.textContent = val;
                            el.style.transition = 'opacity .25s';
                            el.style.opacity = '1';
                        }
                        up('sad-k-tokens',      fmt(d.tokens));
                        up('sad-k-tokens-sub',  lbl);
                        up('sad-k-cost',        '$' + Number(d.cost).toFixed(4));
                        up('sad-k-cost-sub',    lbl);
                        up('sad-k-tickets',     fmt(d.tickets));
                        up('sad-k-tickets-sub', lbl);
                        up('sad-k-ap',          fmt(d.ap_processed));
                        up('sad-k-ap-sub',      lbl + ' · ' + fmt(d.ap_replied) + ' replied/drafted');
                        up('sad-k-time',        d.time_saved + 'h');
                        up('sad-k-time-sub',    lbl + ' · ' + d.avg_handle_min + 'min/ticket avg');
                    })
                    .catch(function(e){
                        document.getElementById('sad-period-loading').style.display = 'none';
                        console.error('Fetch error', e);
                    });
            }

            document.querySelectorAll('.sad-period-btn').forEach(function(btn){
                btn.addEventListener('click', function(){ loadPeriod(this.dataset.period); });
            });

            // Load lifetime on page init
            loadPeriod('lifetime');
        })();
        </script>

        <!-- ── 30-Day Token Trend (SVG area chart) ── -->
        <div class="sad-card">
            <div class="sad-section"><i class="fas fa-chart-area" style="color:#3b82f6"></i> 30-Day Token Usage Trend</div>
            <?php
            $svgW = 900; $svgH = 130; $padL = 8; $padR = 8; $padT = 10; $padB = 26;
            $iW   = $svgW - $padL - $padR;
            $iH   = $svgH - $padT - $padB;
            $pts  = array_values($dailyData);
            $n    = count($pts);
            $coords = [];
            for ($i = 0; $i < $n; $i++) {
                $x = $padL + ($n > 1 ? $i / ($n - 1) : 0) * $iW;
                $y = $padT + $iH - ($maxDayTokens > 0 ? ($pts[$i]['tokens'] / $maxDayTokens) * $iH : 0);
                $coords[] = [$x, $y];
            }
            $polyline = implode(' ', array_map(function($c) { return round($c[0],1).','.round($c[1],1); }, $coords));
            $svgBottom = $padT + $iH;
            $area = 'M ' . $coords[0][0] . ',' . $coords[0][1];
            foreach (array_slice($coords, 1) as $c) $area .= ' L ' . $c[0] . ',' . $c[1];
            $area .= ' L ' . $coords[$n-1][0] . ',' . $svgBottom . ' L ' . $coords[0][0] . ',' . $svgBottom . ' Z';
            ?>
            <div style="position:relative;width:100%;overflow:hidden">
            <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="none"
                 style="width:100%;height:140px;display:block" id="sadTokenChart">
                <defs>
                    <linearGradient id="sadGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%"   stop-color="#3b82f6" stop-opacity="0.35"/>
                        <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.03"/>
                    </linearGradient>
                </defs>
                <?php for ($g = 0; $g <= 4; $g++): $gy = $padT + ($g/4)*$iH; ?>
                <line x1="<?= $padL ?>" y1="<?= round($gy,1) ?>" x2="<?= $svgW-$padR ?>" y2="<?= round($gy,1) ?>"
                      stroke="#e2e8f0" stroke-width="1"/>
                <?php endfor; ?>
                <path d="<?= $area ?>" fill="url(#sadGrad)"/>
                <polyline points="<?= $polyline ?>" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linejoin="round"/>
                <?php foreach ($coords as $i => $c):
                    if ($i % 5 !== 0 && $i !== $n-1) continue; ?>
                <text x="<?= round($c[0],1) ?>" y="<?= $svgH - 4 ?>" text-anchor="middle"
                      font-size="7" fill="#94a3b8"><?= htmlspecialchars($pts[$i]['label']) ?></text>
                <?php endforeach; ?>
                <?php foreach ($coords as $i => $c):
                    if ($pts[$i]['tokens'] <= 0) continue; ?>
                <circle cx="<?= round($c[0],1) ?>" cy="<?= round($c[1],1) ?>" r="3"
                        fill="#3b82f6" opacity="0.6" style="cursor:pointer">
                    <title><?= htmlspecialchars($pts[$i]['date']) ?>: <?= number_format($pts[$i]['tokens']) ?> tokens</title>
                </circle>
                <?php endforeach; ?>
            </svg>
            </div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;text-align:right">Last 30 days &middot; Hover dots for daily totals</div>
        </div>

        <!-- ── AI Action Breakdown ── -->
        <div class="sad-card">
            <div class="sad-section"><i class="fas fa-layer-group" style="color:#7c3aed"></i> AI Action Breakdown</div>
            <div class="sad-tag-grid">
                <?php if (empty($actionsByType)): ?>
                    <span class="text-muted" style="font-size:.88rem">No actions recorded yet.<br>Generate an AI response to see data here.</span>
                <?php else: ?>
                    <?php
                    $palette = ['#3b82f6','#7c3aed','#059669','#d97706','#dc2626','#0891b2','#db2777','#65a30d'];
                    $i = 0;
                    foreach ($actionsByType as $type => $count):
                        $col = $palette[$i++ % count($palette)];
                    ?>
                    <span class="sad-pill" style="background:<?= $col ?>18;color:<?= $col ?>;border:1px solid <?= $col ?>44">
                        <?= ucwords(str_replace('_', ' ', $type)) ?>: <strong><?= number_format($count) ?></strong>
                    </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Quality Metrics + Tool Evidence Side-by-Side ── -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="sad-two-col">
            <div class="sad-card" style="margin-bottom:0">
                <div class="sad-section"><i class="fas fa-star-half-alt" style="color:#d97706"></i> Quality Scores</div>
                <?php
                $metricsToGraph = ['overall','clarity','tone','completeness','ai_avg','manual_avg'];
                $labels = ['overall'=>'Overall','clarity'=>'Clarity','tone'=>'Tone','completeness'=>'Completeness','ai_avg'=>'AI Replies Avg','manual_avg'=>'Human Replies Avg'];
                $hasMetrics = false;
                foreach ($metricsToGraph as $mk):
                    if (!isset($scores[$mk]) || $scores[$mk] <= 0) continue;
                    $hasMetrics = true;
                    $val = $scores[$mk];
                    $pct = ($val / 10) * 100;
                    $fillCol = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="sad-prog-row">
                    <div class="sad-prog-header">
                        <span><?= $labels[$mk] ?></span>
                        <span><?= number_format($val, 1) ?>/10</span>
                    </div>
                    <div class="sad-prog-track">
                        <div class="sad-prog-fill" style="width:<?= $pct ?>%;background:<?= $fillCol ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$hasMetrics): ?>
                    <span class="text-muted" style="font-size:.85rem">No quality scores recorded yet. Have users rate AI responses to see data.</span>
                <?php endif; ?>
            </div>

            <div class="sad-card" style="margin-bottom:0">
                <div class="sad-section"><i class="fas fa-tools" style="color:#0891b2"></i> Tools Evidence Quality</div>
                <div class="sad-tag-grid">
                    <?php
                    $toolPills = [
                        ['Total Runs', $toolsNorm['total_tool_runs'] ?? 0, '#f8fafc', '#334155'],
                        ['Readable Extracted', $toolsNorm['normalized_runs'] ?? 0, '#e0fafb', '#0891b2'],
                        ['Raw Fallback', $toolsNorm['raw_fallback_runs'] ?? 0, '#fff7ed', '#d97706'],
                        ['Extraction Errors', $toolsNorm['normalization_errors'] ?? 0, '#fef2f2', '#dc2626'],
                    ];
                    foreach ($toolPills as [$label, $val, $bg, $col]): ?>
                    <span class="sad-pill" style="background:<?= $bg ?>;color:<?= $col ?>;border:1px solid <?= $col ?>30">
                        <?= $label ?>: <strong><?= number_format((int) $val) ?></strong>
                    </span>
                    <?php endforeach; ?>
                </div>

                <hr style="margin:16px 0">
                <div class="sad-section" style="margin-bottom:12px"><i class="fas fa-robot" style="color:#7c3aed"></i> Autopilot Outcome Split</div>
                <?php
                $apSplit = Capsule::table('tblsahdev_autopilot_log')
                    ->selectRaw('ai_decision, COUNT(*) as cnt')
                    ->groupBy('ai_decision')->get();
                $splitMap = [];
                foreach ($apSplit as $s) $splitMap[$s->ai_decision] = $s->cnt;
                $splitTotal = max(1, array_sum($splitMap));
                $splitDefs = [
                    'replied' => ['Replied', '#16a34a', 'badge-live'],
                    'drafted' => ['Drafted (Notes)', '#ca8a04', 'badge-draft'],
                    'skipped' => ['Skipped', '#64748b', 'badge-skip'],
                ];
                foreach ($splitDefs as $key => [$label, $col, $badgeCls]):
                    $cnt = $splitMap[$key] ?? 0;
                    $pct = $cnt > 0 ? round($cnt / $splitTotal * 100) : 0;
                ?>
                <div class="sad-prog-row">
                    <div class="sad-prog-header">
                        <span><span class="sad-badge <?= $badgeCls ?>"><?= $label ?></span></span>
                        <span><?= number_format($cnt) ?> (<?= $pct ?>%)</span>
                    </div>
                    <div class="sad-prog-track">
                        <div class="sad-prog-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>@media(max-width:700px){.sad-two-col{grid-template-columns:1fr!important}}</style>

        <!-- ── Per-Provider Cost Breakdown ── -->
        <div class="sad-card">
            <div class="sad-section"><i class="fas fa-microchip" style="color:#7c3aed"></i> Per-Provider Cost Breakdown</div>
            <?php if (empty($providerRows)): ?>
                <p style="color:#aaa;font-size:.88rem">No token data yet. Costs appear once AI calls are made and providers have <code>cost_input_1m</code>/<code>cost_output_1m</code> configured.</p>
            <?php else: ?>
            <table class="table table-sm sad-table">
                <thead><tr>
                    <th>Provider (as logged)</th><th>Type</th>
                    <th>Rate / 1M tokens</th><th>Total Tokens</th>
                    <th>API Calls</th><th>Est. Cost (USD)</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($providerRows as $r): ?>
                    <tr>
                        <td><i class="fas fa-circle" style="color:#3b82f6;font-size:.4em;vertical-align:middle;margin-right:6px"></i><?= $r['name'] ?></td>
                        <td><code style="font-size:.75rem"><?= htmlspecialchars($r['type']) ?></code></td>
                        <td><?= $r['rate'] > 0 ? '$'.number_format($r['rate'],4) : '<span style="color:#aaa">—</span>' ?></td>
                        <td><?= number_format($r['tokens']) ?></td>
                        <td><?= number_format($r['calls']) ?></td>
                        <td><?= $r['cost'] > 0 ? '<strong>$'.number_format($r['cost'],6).'</strong>' : '<span style="color:#aaa">$0.000000</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr style="font-weight:700;background:#f8fafc">
                    <td colspan="3">Total</td>
                    <td><?= number_format($totalTokensAll) ?></td>
                    <td><?= number_format($totalCallsAll) ?></td>
                    <td>$<?= number_format($totalCostAll, 6) ?></td>
                </tr></tfoot>
            </table>
            <p style="color:#94a3b8;font-size:.75rem;margin-top:8px">Rate matched via provider type keyword. Configure costs in <strong>AI Providers</strong> settings.</p>
            <?php endif; ?>
        </div>

        <!-- ── ROI Settings ── -->
        <div class="sad-card">
            <div class="sad-section"><i class="fas fa-sliders-h" style="color:#059669"></i> ROI Calculation Settings</div>
            <p style="color:#64748b;font-size:.85rem;margin-bottom:16px">Configure how Sahdev estimates the human time saved per autopilot reply. Adjust to your team's real average handle time.</p>
            <form method="post" action="<?= $actionUrl ?>">
                <?php echo function_exists('generate_token') ? generate_token() : ''; ?>
                <input type="hidden" name="roi_save" value="1">
                <div class="sad-settings-row">
                    <label style="font-weight:600;font-size:.88rem;margin:0">Avg. Handle Time per Ticket</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="number" name="avg_handle_minutes" class="form-control" style="width:90px" value="<?= (int) $avgHandleMin ?>" min="1" max="120">
                        <span style="font-size:.85rem;color:#64748b">minutes</span>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-save"></i> Save</button>
                    <span style="font-size:.78rem;color:#94a3b8">Used to compute <em>time saved = autopilot replies × avg handle time</em></span>
                </div>
            </form>
        </div>

        </div><!-- /sahdev-page-container -->
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX endpoint: returns JSON with period-filtered analytics KPI data.
     * Called by the period switcher JS in analytics().
     */
    public function analytics_data(): string
    {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');

        try {
            $settings     = Capsule::table('tblsahdev_settings')->first();
            $avgHandleMin = max(1, (int) ($settings->avg_handle_minutes ?? 8));

            $nowTs = \Carbon\Carbon::now();
            $validPeriods = ['day' => '1 Day', 'week' => '1 Week', 'month' => '1 Month', 'year' => '1 Year', 'lifetime' => 'Lifetime'];
            $selectedPeriod = isset($_REQUEST['period']) && isset($validPeriods[$_REQUEST['period']]) ? $_REQUEST['period'] : 'lifetime';
            $periodLabel    = $validPeriods[$selectedPeriod];

            if ($selectedPeriod === 'day') {
                $since = $nowTs->copy()->subDay()->startOfDay();
            } elseif ($selectedPeriod === 'week') {
                $since = $nowTs->copy()->subDays(7)->startOfDay();
            } elseif ($selectedPeriod === 'month') {
                $since = $nowTs->copy()->subDays(30)->startOfDay();
            } elseif ($selectedPeriod === 'year') {
                $since = $nowTs->copy()->subDays(365)->startOfDay();
            } else {
                $since = null;
            }

            // Provider cost-rate map
            $providerCostMap = [];
            foreach (Capsule::table('tblsahdev_providers')->where('is_active', 1)->get() as $p) {
                $pt   = strtolower(trim($p->provider_type));
                $rate = (float) (($p->cost_input_1m + $p->cost_output_1m) / 2);
                if (!isset($providerCostMap[$pt]) || $rate > $providerCostMap[$pt]) {
                    $providerCostMap[$pt] = $rate;
                }
            }
            $resolveType = function ($name) {
                $n = strtolower((string)$name);
                if (strpos($n, 'google') !== false || strpos($n, 'gemini') !== false) return 'google';
                if (strpos($n, 'lm studio') !== false || strpos($n, 'lmstudio') !== false) return 'lmstudio';
                if (strpos($n, 'replicate') !== false) return 'replicate';
                return $n;
            };

            // Audit trail tokens + cost
            $auditQ = Capsule::table('tblsahdev_audit_trail')->whereNotNull('tokens_used')->where('tokens_used', '>', 0);
            if ($since) $auditQ->where('created_at', '>=', $since);
            $auditByProvider = (clone $auditQ)->selectRaw('provider_used, SUM(tokens_used) as tokens')->groupBy('provider_used')->get();

            $totalTokens = 0;
            $totalCost   = 0.0;
            foreach ($auditByProvider as $row) {
                $tok  = (int) $row->tokens;
                $type = $resolveType($row->provider_used);
                $rate = $providerCostMap[$type] ?? 0.0;
                $totalTokens += $tok;
                $totalCost   += round($tok / 1_000_000 * $rate, 6);
            }

            // Autopilot tokens
            $apQ = Capsule::table('tblsahdev_autopilot_log')->whereNotNull('tokens_used')->where('tokens_used', '>', 0);
            if ($since) $apQ->where('created_at', '>=', $since);
            $apTok = (int) (clone $apQ)->sum('tokens_used');
            $totalTokens += $apTok;
            // Estimate autopilot cost via blended rate
            if ($totalTokens > 0 && $totalCost > 0) {
                $blendedRate = $totalCost / max(1, $totalTokens - $apTok);
                $totalCost   = round($totalCost + ($apTok / 1_000_000 * $blendedRate), 6);
            }

            // Replied/drafted count
            $apRepliesQ = Capsule::table('tblsahdev_autopilot_log')->whereIn('ai_decision', ['replied', 'drafted']);
            if ($since) $apRepliesQ->where('created_at', '>=', $since);
            $apReplied = (clone $apRepliesQ)->count();

            // Autopilot processed
            $apProcQ = Capsule::table('tblsahdev_autopilot_log');
            if ($since) $apProcQ->where('created_at', '>=', $since);
            $apProcessed = (clone $apProcQ)->count();

            // Unique tickets
            $atQ = Capsule::table('tblsahdev_audit_trail')->whereNotNull('ticket_id');
            if ($since) $atQ->where('created_at', '>=', $since);
            $auditTickets = (clone $atQ)->distinct()->pluck('ticket_id')->toArray();
            $apTQ = Capsule::table('tblsahdev_autopilot_log');
            if ($since) $apTQ->where('created_at', '>=', $since);
            $apTickets = (clone $apTQ)->distinct()->pluck('ticket_id')->toArray();
            $tickets   = count(array_unique(array_merge($auditTickets, $apTickets)));

            $timeSaved = round(($apReplied * $avgHandleMin) / 60, 1);

            return json_encode([
                'period'         => $selectedPeriod,
                'period_label'   => $periodLabel,
                'tokens'         => $totalTokens,
                'cost'           => round($totalCost, 6),
                'tickets'        => $tickets,
                'ap_processed'   => $apProcessed,
                'ap_replied'     => $apReplied,
                'time_saved'     => $timeSaved,
                'avg_handle_min' => $avgHandleMin,
            ]);
        } catch (\Exception $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Ticket Insights Tab
    // =========================================================================

    /**
     * Ticket Insights — settings, manual trigger, and paginated insights table.
     */
    public function ticket_insights()
    {
        $successMessage = '';
        $errorMessage   = '';

        // --- Inline schema migration ---
        try {
            Capsule::table('tblsahdev_settings')->select('cron_insights_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('cron_insights_enabled')->default(1);
                $table->integer('cron_insights_interval_hours')->default(6);
                $table->integer('cron_insights_max_per_run')->default(20);
            });
        }
        try {
            Capsule::table('tblsahdev_sentiment')->select('client_tone')->first();
        } catch (\Exception $e) {
            if (Capsule::schema()->hasTable('tblsahdev_sentiment')) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->string('client_tone', 64)->nullable();
                    $table->text('ticket_summary')->nullable();
                    $table->integer('admin_reply_count')->unsigned()->default(0);
                    $table->string('last_admin_name', 128)->nullable();
                    $table->timestamp('ticket_last_reply_at')->nullable();
                    $table->timestamp('analyzed_at')->nullable();
                });
            }
        }
        try {
            Capsule::table('tblsahdev_sentiment')->select('ticket_last_reply_at')->first();
        } catch (\Exception $e) {
            if (Capsule::schema()->hasTable('tblsahdev_sentiment')) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->timestamp('ticket_last_reply_at')->nullable();
                });
            }
        }
        try {
            Capsule::table('tblsahdev_sentiment')->select('ai_tags_json')->first();
        } catch (\Exception $e) {
            if (Capsule::schema()->hasTable('tblsahdev_sentiment')) {
                Capsule::schema()->table('tblsahdev_sentiment', function ($table) {
                    $table->text('ai_tags_json')->nullable();
                });
            }
        }

        // --- Handle clear all action (must run before data fetch) ---
        if (isset($_GET['clear_insights']) && $_GET['clear_insights'] == '1') {
            Capsule::table('tblsahdev_sentiment')->truncate();
            header('Location: ' . $this->moduleVars['modulelink'] . '&action=ticket_insights');
            exit;
        }

        // --- Ensure cron_insights_statuses column exists ---
        try {
            Capsule::table('tblsahdev_settings')->select('cron_insights_statuses')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('cron_insights_statuses', 512)->nullable()->comment('Comma-separated ticket statuses to analyze');
            });
        }

        try {
            Capsule::table('tblsahdev_settings')->select('insights_cron_last_run_at')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->timestamp('insights_cron_last_run_at')->nullable();
                $table->integer('insights_cron_last_found')->unsigned()->default(0);
                $table->integer('insights_cron_last_analyzed')->unsigned()->default(0);
                $table->integer('insights_cron_last_skipped')->unsigned()->default(0);
                $table->string('insights_cron_last_message', 512)->nullable();
            });
        }

        try {
            Capsule::table('tblsahdev_settings')->select('insights_cron_http_url')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('insights_cron_http_url', 2048)->nullable();
                $table->text('insights_cron_cli_command')->nullable();
            });
        }

        // --- Handle settings save ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_insights_settings'])) {
            check_token("WHMCS.admin.default");

            $enabled       = !empty($_POST['cron_insights_enabled']) ? 1 : 0;
            $intervalHours = max(1, (int) ($_POST['cron_insights_interval_hours'] ?? 6));
            $maxPerRun     = max(1, min(100, (int) ($_POST['cron_insights_max_per_run'] ?? 20)));
            // Sanitize statuses: comma-separated, strip extra whitespace
            $rawStatuses   = $_POST['cron_insights_statuses'] ?? '';
            $statuses      = implode(', ', array_filter(array_map('trim', explode(',', $rawStatuses))));
            $cronHttpUrl   = substr(trim((string) ($_POST['insights_cron_http_url'] ?? '')), 0, 2048);

            Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'cron_insights_enabled'        => $enabled,
                'cron_insights_interval_hours' => $intervalHours,
                'cron_insights_max_per_run'    => $maxPerRun,
                'cron_insights_statuses'       => $statuses ?: null,
                'insights_cron_http_url'       => $cronHttpUrl !== '' ? $cronHttpUrl : null,
                'insights_cron_cli_command'    => $cronCliCmd !== '' ? $cronCliCmd : null,
                'updated_at'                   => \Carbon\Carbon::now(),
            ]);
            $successMessage = 'Ticket Insights settings saved successfully.';
        }

        // --- Fetch settings ---
        $settings          = Capsule::table('tblsahdev_settings')->first();
        $cronEnabled       = $settings ? (int) ($settings->cron_insights_enabled ?? 1) : 1;
        $cronInterval      = $settings ? (int) ($settings->cron_insights_interval_hours ?? 6) : 6;
        $cronMax           = $settings ? (int) ($settings->cron_insights_max_per_run ?? 20) : 20;
        $cronStatuses      = ($settings && !empty($settings->cron_insights_statuses))
                             ? $settings->cron_insights_statuses
                             : 'Customer-Reply, Awaiting Reply, Open';

        $insightsCronLastAt = $settings && !empty($settings->insights_cron_last_run_at)
            ? $settings->insights_cron_last_run_at
            : null;
        $insightsCronFound    = $settings ? (int) ($settings->insights_cron_last_found ?? 0) : 0;
        $insightsCronAnalyzed = $settings ? (int) ($settings->insights_cron_last_analyzed ?? 0) : 0;
        $insightsCronSkipped  = $settings ? (int) ($settings->insights_cron_last_skipped ?? 0) : 0;
        $insightsCronMsg      = $settings && !empty($settings->insights_cron_last_message)
            ? (string) $settings->insights_cron_last_message
            : '';

        $cronStaleHours = 48;
        $cronLastCarbon = null;
        if ($insightsCronLastAt) {
            try {
                $cronLastCarbon = \Carbon\Carbon::parse($insightsCronLastAt);
            } catch (\Exception $e) {
                $cronLastCarbon = null;
            }
        }
        $cronIsStale = $cronEnabled && $cronLastCarbon && $cronLastCarbon->lt(\Carbon\Carbon::now()->subHours($cronStaleHours));
        $cronNeverRan = $cronEnabled && !$cronLastCarbon;

        require_once __DIR__ . '/../lib/CronUrlHelper.php';
        $whmcsCronDefaultHttp = \Sahdev\Lib\CronUrlHelper::whmcsDefaultCronHttpUrl();
        $cronHttpSaved       = ($settings && !empty($settings->insights_cron_http_url))
            ? trim((string) $settings->insights_cron_http_url)
            : '';
        $cronCliSaved        = ($settings && !empty($settings->insights_cron_cli_command))
            ? (string) $settings->insights_cron_cli_command
            : '';
        $cronHttpEffective   = \Sahdev\Lib\CronUrlHelper::effectiveHttpUrl($cronHttpSaved ?: null);
        $cronUrlHint         = $cronHttpEffective !== ''
            ? $cronHttpEffective
            : '/crons/cron.php (set SystemURL in WHMCS or paste URL below)';

        // Fetch WHMCS ticket statuses from its own table for the helper hint
        $whmcsStatuses = [];
        try {
            $whmcsStatuses = Capsule::table('tblticketstatuses')
                ->orderBy('sortorder', 'asc')
                ->pluck('title')
                ->toArray();
        } catch (\Exception $e) {
            // tblticketstatuses may not exist on very old WHMCS versions — ignore
        }

        // --- Pagination ---
        $page    = max(1, (int) ($_GET['ipage'] ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $total   = Capsule::table('tblsahdev_sentiment')->count();
        $pages   = max(1, (int) ceil($total / $perPage));

        $rows = Capsule::table('tblsahdev_sentiment')
            ->orderBy('analyzed_at', 'desc')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // Enrich with ticket subject & client name
        $enriched = [];
        foreach ($rows as $row) {
            $ticket = Capsule::table('tbltickets')
                ->select('id', 'tid', 'userid', 'name', 'title', 'status')
                ->where('id', $row->ticket_id)
                ->first();

            $clientName = $ticket ? ($ticket->name ?? 'Unknown') : 'Unknown';
            if ($ticket && $ticket->userid) {
                $client = Capsule::table('tblclients')
                    ->select('firstname', 'lastname')
                    ->where('id', $ticket->userid)
                    ->first();
                if ($client) {
                    $clientName = trim($client->firstname . ' ' . $client->lastname) ?: $clientName;
                }
            }

            $enriched[] = [
                'row'         => $row,
                'ticket'      => $ticket,
                'client_name' => $clientName,
            ];
        }

        $csrfToken  = generate_token("form");
        $actionUrl  = htmlspecialchars($this->moduleVars['modulelink'] . '&action=ticket_insights');
        $ajaxUrlBase = htmlspecialchars($this->moduleVars['modulelink']);

        $intervalOptions = [1 => '1 hour', 3 => '3 hours', 6 => '6 hours', 12 => '12 hours', 24 => '24 hours'];

        ob_start();
        ?>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <?php echo $this->getNavigationMarkup('ticket_insights'); ?>

        <div class="sahdev-page-container">

            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 25px;">
                <div>
                    <h2 style="margin: 0 0 4px 0;"><i class="fas fa-brain" style="color:#0d6efd;"></i> Ticket Insights</h2>
                    <p class="text-muted" style="margin:0; font-size:13px;">AI-powered sentiment, urgency, and tone analysis for configured ticket statuses.</p>
                </div>
            </div>
            <div class="alert alert-info" style="margin-bottom:22px;">
                Cron configuration, setup instructions, and cron testing have been moved to
                <a href="<?php echo htmlspecialchars($this->moduleVars['modulelink'] . '&action=cron_center'); ?>"><strong>Separate Cron</strong></a>.
            </div>

            <!-- Insights Table -->
            <div style="background:#fff; border:1px solid #e9ecef; border-radius:8px; padding:24px; box-shadow:0 1px 4px rgba(0,0,0,0.05);">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
                    <h4 style="margin:0; font-size:16px;"><i class="fas fa-table" style="color:#6c757d;"></i> Analyzed Tickets
                        <span style="font-size:13px; font-weight:400; color:#6c757d; margin-left:8px;"><?php echo number_format($total); ?> total</span>
                    </h4>
                    <?php if ($total > 0): ?>
                        <a href="<?php echo $actionUrl; ?>&clear_insights=1" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Clear all sentiment analysis data? This cannot be undone.');">
                            <i class="fas fa-trash"></i> Clear All
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($enriched)): ?>
                    <div style="text-align:center; padding:40px; color:#6c757d;">
                        <i class="fas fa-inbox" style="font-size:36px; margin-bottom:12px; display:block; opacity:0.4;"></i>
                        <p style="margin:0;">No tickets have been analyzed yet.</p>
                        <p style="font-size:13px; margin-top:6px;">Run cron from the <strong>Separate Cron</strong> section, or wait for your scheduled server cron run.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="table table-hover" style="font-size:13px; margin-bottom:0;">
                            <thead style="background:#f8f9fa;">
                                <tr>
                                    <th style="width:70px;">Ticket</th>
                                    <th>Client</th>
                                    <th>Subject</th>
                                    <th style="width:90px;">Urgency</th>
                                    <th style="width:100px;">Sentiment</th>
                                    <th style="width:120px;">Client Tone</th>
                                    <th style="width:80px; text-align:center;">Admin Replies</th>
                                    <th style="width:140px;">Last Admin</th>
                                    <th style="width:130px;">Analyzed</th>
                                    <th>Summary</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enriched as $entry): ?>
                                    <?php
                                    $row = $entry['row'];
                                    $ticket = $entry['ticket'];
                                    $clientName = $entry['client_name'];

                                    $urgencyColors = [
                                        'critical' => ['bg' => '#dc3545', 'text' => '#fff'],
                                        'high'     => ['bg' => '#fd7e14', 'text' => '#fff'],
                                        'medium'   => ['bg' => '#ffc107', 'text' => '#343a40'],
                                        'low'      => ['bg' => '#198754', 'text' => '#fff'],
                                    ];
                                    $urg = strtolower($row->urgency ?? 'medium');
                                    $urgStyle = $urgencyColors[$urg] ?? $urgencyColors['medium'];
                                    $scoreColor = '#6c757d';
                                    $score = (int)($row->score ?? 5);
                                    if ($score >= 8) $scoreColor = '#dc3545';
                                    elseif ($score >= 6) $scoreColor = '#fd7e14';
                                    elseif ($score <= 3) $scoreColor = '#198754';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($ticket): ?>
                                                <a href="supporttickets.php?action=view&id=<?php echo (int)$row->ticket_id; ?>" target="_blank" style="font-weight:600;">
                                                    #<?php echo htmlspecialchars($ticket->tid ?? $row->ticket_id); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">#<?php echo (int)$row->ticket_id; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($clientName); ?></td>
                                        <td>
                                            <?php if ($ticket): ?>
                                                <a href="supporttickets.php?action=view&id=<?php echo (int)$row->ticket_id; ?>" target="_blank">
                                                    <?php echo htmlspecialchars(mb_substr($ticket->title ?? '', 0, 60)); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">(ticket deleted)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700;
                                                background:<?php echo $urgStyle['bg']; ?>; color:<?php echo $urgStyle['text']; ?>;">
                                                <?php echo htmlspecialchars(ucfirst($row->urgency ?? 'Medium')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight:700; color:<?php echo $scoreColor; ?>;">
                                                <?php echo $score; ?>/10
                                            </span>
                                            <br><small class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($row->label ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $toneIcons = [
                                                'Angry' => '😡', 'Threatening' => '⚠️', 'Demanding' => '😤',
                                                'Impatient' => '⏳', 'Neutral' => '😐', 'Confused' => '😕',
                                                'Polite' => '🙂', 'Appreciative' => '😊'
                                            ];
                                            $tone = $row->client_tone ?? '';
                                            $icon = $toneIcons[$tone] ?? '';
                                            ?>
                                            <?php echo $icon ? $icon . ' ' : ''; ?><?php echo htmlspecialchars($tone ?: '—'); ?>
                                        </td>
                                        <td style="text-align:center; font-weight:600;">
                                            <?php echo (int)($row->admin_reply_count ?? 0); ?>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($row->last_admin_name ?? '—'); ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo $row->analyzed_at ? date('M j, H:i', strtotime($row->analyzed_at)) : '—'; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if (!empty($row->ticket_summary)): ?>
                                                <details>
                                                    <summary style="cursor:pointer; font-size:12px; color:#0d6efd; user-select:none;">View summary</summary>
                                                    <p style="margin:6px 0 0; font-size:12px; line-height:1.6; color:#495057; max-width:380px; white-space:pre-wrap;"><?php echo htmlspecialchars($row->ticket_summary); ?></p>
                                                </details>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size:11px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($pages > 1): ?>
                        <div style="display:flex; justify-content:center; margin-top:20px; gap:4px;">
                            <?php for ($p = 1; $p <= $pages; $p++): ?>
                                <a href="<?php echo $actionUrl . '&ipage=' . $p; ?>"
                                    style="padding:5px 12px; border-radius:4px; border:1px solid <?php echo ($p == $page) ? '#0d6efd' : '#dee2e6'; ?>;
                                        background:<?php echo ($p == $page) ? '#0d6efd' : '#fff'; ?>;
                                        color:<?php echo ($p == $page) ? '#fff' : '#0d6efd'; ?>;
                                        text-decoration:none; font-size:13px;">
                                    <?php echo $p; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function() {
            var btn    = document.getElementById('sahdev-trigger-cron');
            var msgBox = document.getElementById('sahdev-cron-msg');
            var dbgOut = document.getElementById('sahdev-cron-debug-out');

            var AJAX = '<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>&sahdev_act=ajax_handler';

            function showMsg(html, type) {
                if (!msgBox) return;
                msgBox.style.display = 'block';
                msgBox.className = 'alert alert-' + type;
                msgBox.innerHTML = html;
            }

            function post(action, extra) {
                var fd = new FormData();
                fd.append('action', action);
                if (extra) { Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); }); }
                return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) {
                        // Guard: ensure response is JSON (not an HTML error/timeout page)
                        var ct = r.headers.get('content-type') || '';
                        if (!ct.includes('application/json') && !ct.includes('text/plain')) {
                            throw new Error('Server returned non-JSON (possible timeout or PHP error). Status: ' + r.status);
                        }
                        return r.json();
                    });
            }

            var testHttpBtn = document.getElementById('sahdev-test-cron-http');
            if (testHttpBtn) {
                testHttpBtn.addEventListener('click', function() {
                    var urlField = document.querySelector('[name="insights_cron_http_url"]');
                    var testUrl = urlField ? urlField.value.trim() : '';
                    if (dbgOut) { dbgOut.style.display = 'block'; dbgOut.textContent = 'Requesting…'; }
                    post('test_whmcs_cron_http', { test_url: testUrl })
                        .then(function(d) { if (dbgOut) dbgOut.textContent = JSON.stringify(d, null, 2); })
                        .catch(function(e) { if (dbgOut) dbgOut.textContent = 'Error: ' + e.message; });
                });
            }

            var dbgBtn = document.getElementById('sahdev-debug-insights-cron');
            if (dbgBtn) {
                dbgBtn.addEventListener('click', function() {
                    dbgBtn.disabled = true;
                    if (dbgOut) { dbgOut.style.display = 'block'; dbgOut.textContent = 'Running batch…'; }
                    post('trigger_cron_run')
                        .then(function(d) {
                            dbgBtn.disabled = false;
                            if (dbgOut) dbgOut.textContent = JSON.stringify(d, null, 2);
                        })
                        .catch(function(e) {
                            dbgBtn.disabled = false;
                            if (dbgOut) dbgOut.textContent = 'Error: ' + e.message;
                        });
                });
            }

            if (!btn) return;

            btn.addEventListener('click', function() {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting queue...';
                showMsg('<i class="fas fa-spinner fa-spin"></i> Fetching tickets that need analysis...', 'info');

                post('get_insights_queue')
                .then(function(data) {
                    if (data.status !== 'success') {
                        throw new Error(data.message || 'Could not fetch queue');
                    }
                    var queue = data.queue || [];
                    if (!queue.length) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-play-circle"></i> Run Analysis Now';
                        showMsg('<i class="fas fa-info-circle"></i> No tickets need analysis right now — all up to date.', 'info');
                        return;
                    }
                    // Process tickets one at a time so each request stays under timeout
                    processQueue(queue, 0, 0, []);
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-play-circle"></i> Run Analysis Now';
                    showMsg('<i class="fas fa-exclamation-triangle"></i> ' + err.message, 'danger');
                });
            });

            function processQueue(queue, index, doneCount, errors) {
                var total = queue.length;
                if (index >= total) {
                    // All done
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Done!';
                    var msg = '<i class="fas fa-check-circle"></i> Analysis complete: <strong>' + doneCount + ' of ' + total + '</strong> ticket(s) analyzed.';
                    if (errors.length) {
                        msg += ' <strong>' + errors.length + '</strong> error(s):<br><small>' + errors.join('<br>') + '</small>';
                    }
                    showMsg(msg, doneCount > 0 ? 'success' : 'warning');
                    if (doneCount > 0) { setTimeout(function() { window.location.reload(); }, 2000); }
                    return;
                }

                var tid   = queue[index];
                var pct   = Math.round(((index) / total) * 100);
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + index + '/' + total;
                showMsg(
                    '<i class="fas fa-spinner fa-spin"></i> Analyzing ticket <strong>#' + tid + '</strong> &nbsp;(' + (index + 1) + ' of ' + total + ')' +
                    '<div style="background:#dee2e6;border-radius:4px;height:6px;margin-top:8px;">' +
                    '<div style="background:#0d6efd;height:6px;border-radius:4px;width:' + pct + '%;transition:width .3s;"></div></div>',
                    'info'
                );

                post('analyze_single_insight', { ticket_id: tid })
                .then(function(data) {
                    if (data.status === 'success') {
                        processQueue(queue, index + 1, doneCount + 1, errors);
                    } else {
                        errors.push('Ticket #' + tid + ': ' + (data.message || 'unknown error'));
                        processQueue(queue, index + 1, doneCount, errors);
                    }
                })
                .catch(function(err) {
                    errors.push('Ticket #' + tid + ': ' + err.message);
                    processQueue(queue, index + 1, doneCount, errors);
                });
            }
        })();
        </script>

        <?php
        return ob_get_clean();
    }

    /**
     * Tools Execution settings tab.
     */
    public function tools()
    {
        try {
            Capsule::table('tblsahdev_settings')->select('tools_execution_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('tools_execution_enabled')->default(0);
                $table->string('tools_api_base_url', 255)->default('https://toolsapi.2hs.in');
                $table->string('tools_openapi_url', 2048)->default('https://toolsapi.2hs.in/openapi.json');
                $table->text('tools_api_key_encrypted')->nullable();
                $table->integer('tools_max_tools_per_ticket')->default(50);
                $table->integer('tools_request_timeout_sec')->default(60);
                $table->integer('tools_request_retry_count')->default(3);
                $table->integer('tools_cron_max_per_run')->default(10);
                $table->string('tools_cron_statuses', 512)->nullable();
                $table->text('tools_filter_domains')->nullable();
                $table->text('tools_filter_ips')->nullable();
                $table->text('tools_filter_emails')->nullable();
                $table->boolean('tools_normalize_enabled')->default(1);
                $table->boolean('tools_include_raw_fallback')->default(1);
            });
        }

        // Upgrade-safe: ensure newer filter columns exist even on partially migrated installs
        try {
            Capsule::table('tblsahdev_settings')->select('tools_filter_domains')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->text('tools_filter_domains')->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('tools_filter_ips')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->text('tools_filter_ips')->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('tools_filter_emails')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->text('tools_filter_emails')->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('tools_normalize_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('tools_normalize_enabled')->default(1);
                $table->boolean('tools_include_raw_fallback')->default(1);
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('tools_openapi_url')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('tools_openapi_url', 2048)->default('https://toolsapi.2hs.in/openapi.json');
            });
        }

        $successMessage = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tools_settings'])) {
            check_token("WHMCS.admin.default");
            $enabled = !empty($_POST['tools_execution_enabled']) ? 1 : 0;
            $baseUrl = trim((string) ($_POST['tools_api_base_url'] ?? 'https://toolsapi.2hs.in'));
            $openApiUrl = trim((string) ($_POST['tools_openapi_url'] ?? 'https://toolsapi.2hs.in/openapi.json'));
            $maxTools = max(1, min(50, (int) ($_POST['tools_max_tools_per_ticket'] ?? 50)));
            $timeout = max(5, min(60, (int) ($_POST['tools_request_timeout_sec'] ?? 60)));
            $retry = max(0, min(3, (int) ($_POST['tools_request_retry_count'] ?? 3)));
            $maxPerRun = max(1, min(50, (int) ($_POST['tools_cron_max_per_run'] ?? 10)));
            $statuses = trim((string) ($_POST['tools_cron_statuses'] ?? 'Customer-Reply, Awaiting Reply, Open'));
            $filterDomains = trim((string) ($_POST['tools_filter_domains'] ?? ''));
            $filterIps = trim((string) ($_POST['tools_filter_ips'] ?? ''));
            $filterEmails = trim((string) ($_POST['tools_filter_emails'] ?? ''));
            $normalizeEnabled = !empty($_POST['tools_normalize_enabled']) ? 1 : 0;
            $includeRawFallback = !empty($_POST['tools_include_raw_fallback']) ? 1 : 0;

            $update = [
                'tools_execution_enabled' => $enabled,
                'tools_api_base_url' => $baseUrl !== '' ? $baseUrl : 'https://toolsapi.2hs.in',
                'tools_openapi_url' => $openApiUrl !== '' ? $openApiUrl : 'https://toolsapi.2hs.in/openapi.json',
                'tools_max_tools_per_ticket' => $maxTools,
                'tools_request_timeout_sec' => $timeout,
                'tools_request_retry_count' => $retry,
                'tools_cron_max_per_run' => $maxPerRun,
                'tools_cron_statuses' => $statuses,
                'tools_filter_domains' => $filterDomains,
                'tools_filter_ips' => $filterIps,
                'tools_filter_emails' => $filterEmails,
                'tools_normalize_enabled' => $normalizeEnabled,
                'tools_include_raw_fallback' => $includeRawFallback,
                'updated_at' => \Carbon\Carbon::now(),
            ];

            $newKey = trim((string) ($_POST['tools_api_key'] ?? ''));
            if ($newKey !== '') {
                $update['tools_api_key_encrypted'] = encrypt($newKey);
            }

            Capsule::table('tblsahdev_settings')->where('id', 1)->update($update);
            $successMessage = 'Tools execution settings saved.';
        }

        $settings = Capsule::table('tblsahdev_settings')->first();
        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink'] . '&action=tools');

        ob_start();
        if ($successMessage !== '') {
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($successMessage) . '</div>';
        }
        echo $this->getNavigationMarkup('tools');
        ?>
        <div class="sahdev-page-container">
            <h2 style="margin-top:0;"><i class="fas fa-tools" style="color:#0d6efd;"></i> Tools Execution</h2>
            <p class="text-muted">Configure automatic AI-selected tool execution. You can edit both API base URL and OpenAPI spec URL.</p>
            <div class="alert alert-info" style="margin-bottom:14px;">
                <strong><i class="fas fa-clock"></i> Separate Sahdev Cron (recommended)</strong><br>
                Run this command from server cron:
                <code>php /path/to/whmcs/modules/addons/sahdev/cron/sahdev-cron.php</code><br>
                Suggested frequency: <strong>every 5 minutes</strong> (default), or <strong>every 1 minute</strong> for high-volume queues.
            </div>
            <form method="post" action="<?php echo $actionUrl; ?>">
                <?php echo $csrfToken; ?>
                <div class="checkbox">
                    <label><input type="checkbox" name="tools_execution_enabled" <?php echo !empty($settings->tools_execution_enabled) ? 'checked' : ''; ?>> Enable tools execution module</label>
                </div>
                <div class="checkbox">
                    <label><input type="checkbox" name="tools_normalize_enabled" <?php echo !array_key_exists('tools_normalize_enabled', (array) $settings) || !empty($settings->tools_normalize_enabled) ? 'checked' : ''; ?>> Enable readable evidence extraction layer</label>
                </div>
                <div class="checkbox" style="margin-top:-6px;">
                    <label><input type="checkbox" name="tools_include_raw_fallback" <?php echo !array_key_exists('tools_include_raw_fallback', (array) $settings) || !empty($settings->tools_include_raw_fallback) ? 'checked' : ''; ?>> Always fallback to raw tool output if readable extraction fails</label>
                </div>
                <div class="form-group">
                    <label>Tools API Base URL</label>
                    <input type="text" class="form-control" name="tools_api_base_url" value="<?php echo htmlspecialchars((string) ($settings->tools_api_base_url ?? 'https://toolsapi.2hs.in')); ?>">
                </div>
                <div class="form-group">
                    <label>Tools OpenAPI URL</label>
                    <input type="text" class="form-control" name="tools_openapi_url" value="<?php echo htmlspecialchars((string) ($settings->tools_openapi_url ?? 'https://toolsapi.2hs.in/openapi.json')); ?>">
                    <small class="text-muted">Used to fetch allowed operations and build AI tool-selection prompt.</small>
                </div>
                <div class="form-group">
                    <label>Tools API Key</label>
                    <input type="password" class="form-control" name="tools_api_key" placeholder="Enter new key to update">
                    <small class="text-muted">Stored encrypted. Leave blank to keep current key.</small>
                </div>
                <div class="row">
                    <div class="col-md-3"><div class="form-group"><label>Max tools per ticket</label><input type="number" min="1" max="50" class="form-control" name="tools_max_tools_per_ticket" value="<?php echo (int) ($settings->tools_max_tools_per_ticket ?? 50); ?>"></div></div>
                    <div class="col-md-3"><div class="form-group"><label>Request timeout (sec)</label><input type="number" min="5" max="60" class="form-control" name="tools_request_timeout_sec" value="<?php echo (int) ($settings->tools_request_timeout_sec ?? 60); ?>"></div></div>
                    <div class="col-md-3"><div class="form-group"><label>Retry count</label><input type="number" min="0" max="3" class="form-control" name="tools_request_retry_count" value="<?php echo (int) ($settings->tools_request_retry_count ?? 3); ?>"></div></div>
                    <div class="col-md-3"><div class="form-group"><label>Cron max per run</label><input type="number" min="1" max="50" class="form-control" name="tools_cron_max_per_run" value="<?php echo (int) ($settings->tools_cron_max_per_run ?? 10); ?>"></div></div>
                </div>
                <div class="form-group">
                    <label>Ticket statuses for cron</label>
                    <input type="text" class="form-control" name="tools_cron_statuses" value="<?php echo htmlspecialchars((string) ($settings->tools_cron_statuses ?? 'Customer-Reply, Awaiting Reply, Open')); ?>">
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Domain filter patterns (wildcards)</label>
                            <textarea class="form-control" rows="4" name="tools_filter_domains" placeholder="*.nslookup.io&#10;*.whynopadlock.com"><?php echo htmlspecialchars((string) ($settings->tools_filter_domains ?? '*.nslookup.io,*.whynopadlock.com')); ?></textarea>
                            <small class="text-muted">Comma or newline separated. Supports * and ? wildcards.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>IP filter patterns (wildcards)</label>
                            <textarea class="form-control" rows="4" name="tools_filter_ips" placeholder="127.*&#10;10.0.*"><?php echo htmlspecialchars((string) ($settings->tools_filter_ips ?? '')); ?></textarea>
                            <small class="text-muted">Use for internal/noise IP ranges.</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Email filter patterns (wildcards)</label>
                            <textarea class="form-control" rows="4" name="tools_filter_emails" placeholder="*@example.com"><?php echo htmlspecialchars((string) ($settings->tools_filter_emails ?? '')); ?></textarea>
                            <small class="text-muted">Reserved for future email-entity planning.</small>
                        </div>
                    </div>
                </div>
                <button type="submit" name="save_tools_settings" value="1" class="btn btn-primary"><i class="fas fa-save"></i> Save Tools Settings</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Dedicated cron setup + testing page.
     */
    public function cron_center()
    {
        $successMessage = '';
        $errorMessage = '';

        try {
            Capsule::table('tblsahdev_settings')->select('cron_insights_enabled')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->boolean('cron_insights_enabled')->default(1);
                $table->integer('cron_insights_interval_hours')->default(6);
                $table->integer('cron_insights_max_per_run')->default(20);
                $table->string('cron_insights_statuses', 512)->nullable();
            });
        }
        try {
            Capsule::table('tblsahdev_settings')->select('insights_cron_http_url')->first();
        } catch (\Exception $e) {
            Capsule::schema()->table('tblsahdev_settings', function ($table) {
                $table->string('insights_cron_http_url', 2048)->nullable();
                $table->text('insights_cron_cli_command')->nullable();
            });
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_insights_settings'])) {
            check_token("WHMCS.admin.default");
            $enabled       = !empty($_POST['cron_insights_enabled']) ? 1 : 0;
            $intervalHours = max(1, (int) ($_POST['cron_insights_interval_hours'] ?? 6));
            $maxPerRun     = max(1, min(100, (int) ($_POST['cron_insights_max_per_run'] ?? 20)));
            $rawStatuses   = $_POST['cron_insights_statuses'] ?? '';
            $statuses      = implode(', ', array_filter(array_map('trim', explode(',', (string) $rawStatuses))));
            $cronHttpUrl   = substr(trim((string) ($_POST['insights_cron_http_url'] ?? '')), 0, 2048);
            $cronCliCmd    = trim((string) ($_POST['insights_cron_cli_command'] ?? ''));

            Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                'cron_insights_enabled'        => $enabled,
                'cron_insights_interval_hours' => $intervalHours,
                'cron_insights_max_per_run'    => $maxPerRun,
                'cron_insights_statuses'       => $statuses ?: null,
                'insights_cron_http_url'       => $cronHttpUrl !== '' ? $cronHttpUrl : null,
                'updated_at'                   => \Carbon\Carbon::now(),
            ]);
            $successMessage = 'Cron settings saved.';
        }

        $settings = Capsule::table('tblsahdev_settings')->first();
        $cronEnabled = $settings ? (int) ($settings->cron_insights_enabled ?? 1) : 1;
        $cronInterval = $settings ? (int) ($settings->cron_insights_interval_hours ?? 6) : 6;
        $cronMax = $settings ? (int) ($settings->cron_insights_max_per_run ?? 20) : 20;
        $cronStatuses = ($settings && !empty($settings->cron_insights_statuses))
            ? (string) $settings->cron_insights_statuses
            : 'Customer-Reply, Awaiting Reply, Open';
        $cronHttpSaved = ($settings && !empty($settings->insights_cron_http_url)) ? (string) $settings->insights_cron_http_url : '';

        $insightsCronLastAt = $settings ? (string) ($settings->insights_cron_last_run_at ?? '') : '';
        $insightsCronFound = $settings ? (int) ($settings->insights_cron_last_found ?? 0) : 0;
        $insightsCronAnalyzed = $settings ? (int) ($settings->insights_cron_last_analyzed ?? 0) : 0;
        $insightsCronSkipped = $settings ? (int) ($settings->insights_cron_last_skipped ?? 0) : 0;
        $insightsCronMsg = $settings && !empty($settings->insights_cron_last_message) ? (string) $settings->insights_cron_last_message : '';
        $whmcsStatuses = [];
        try {
            $whmcsStatuses = Capsule::table('tblticketstatuses')
                ->orderBy('sortorder', 'asc')
                ->pluck('title')
                ->toArray();
        } catch (\Exception $e) {
            $whmcsStatuses = [];
        }

        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink'] . '&action=cron_center');
        $ajaxUrlBase = htmlspecialchars($this->moduleVars['modulelink']);
        $intervalOptions = [1 => '1 hour', 3 => '3 hours', 6 => '6 hours', 12 => '12 hours', 24 => '24 hours'];

        ob_start();
        if ($successMessage !== '') {
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($successMessage) . '</div>';
        }
        if ($errorMessage !== '') {
            echo '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($errorMessage) . '</div>';
        }
        echo $this->getNavigationMarkup('cron_center');
        ?>
        <div class="sahdev-page-container">
            <h2 style="margin-top:0;"><i class="fas fa-clock" style="color:#0d6efd;"></i> Separate Cron</h2>
            <p class="text-muted">Configure, test, and monitor Sahdev cron execution from one place.</p>

            <div id="sahdev-cron-msg" class="alert" style="display:none; margin-bottom:16px;"></div>

            <div class="alert alert-info">
                <strong>Recommended server cron</strong><br>
                <code>*/5 * * * * /usr/bin/php -q /path/to/whmcs/modules/addons/sahdev/cron/sahdev-cron.php >/dev/null 2>&1</code><br>
                Use every <strong>1 minute</strong> only for high-volume queues.
            </div>

            <div style="margin-bottom:12px;">
                <button type="button" id="sahdev-trigger-cron" class="btn btn-primary">
                    <i class="fas fa-play-circle"></i> Run Analysis Now
                </button>
                <button type="button" id="sahdev-debug-insights-cron" class="btn btn-info" style="margin-left:6px;">
                    <i class="fas fa-bug"></i> Debug Ticket Insights Batch
                </button>
                <button type="button" id="sahdev-test-cron-http" class="btn btn-default" style="margin-left:6px;">
                    <i class="fas fa-vial"></i> Test HTTP Cron URL
                </button>
            </div>
            <pre id="sahdev-cron-debug-out" style="display:none; max-height:280px; overflow:auto; font-size:11px; padding:12px; background:#212529; color:#f8f9fa; border-radius:6px; white-space:pre-wrap;"></pre>

            <div style="background:#fff; border:1px solid #e9ecef; border-radius:8px; padding:18px; margin-top:14px;">
                <table class="table table-condensed" style="margin-bottom:0;">
                    <tbody>
                        <tr><td style="width:240px;"><strong>Last run</strong></td><td><?php echo $insightsCronLastAt !== '' ? htmlspecialchars($insightsCronLastAt) : '—'; ?></td></tr>
                        <tr><td><strong>Tickets in batch</strong></td><td><?php echo (int) $insightsCronFound; ?></td></tr>
                        <tr><td><strong>Analyzed OK</strong></td><td><?php echo (int) $insightsCronAnalyzed; ?></td></tr>
                        <tr><td><strong>Failed in batch</strong></td><td><?php echo (int) $insightsCronSkipped; ?></td></tr>
                        <tr><td><strong>Summary</strong></td><td><?php echo $insightsCronMsg !== '' ? htmlspecialchars($insightsCronMsg) : '—'; ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div style="background:#fff; border:1px solid #e9ecef; border-radius:8px; padding:24px; margin-top:16px;">
                <h4 style="margin-top:0;">Cron Settings</h4>
                <form method="post" action="<?php echo $actionUrl; ?>">
                    <?php echo $csrfToken; ?>
                    <input type="hidden" name="save_insights_settings" value="1">
                    <div class="row">
                        <div class="col-md-3"><div class="form-group"><label><input type="checkbox" name="cron_insights_enabled" value="1" <?php echo $cronEnabled ? 'checked' : ''; ?>> Enable cron ticket insights</label></div></div>
                        <div class="col-md-3"><div class="form-group"><label>Re-analyze interval</label><select name="cron_insights_interval_hours" class="form-control"><?php foreach ($intervalOptions as $val => $label): ?><option value="<?php echo $val; ?>" <?php echo ($cronInterval == $val) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></div></div>
                        <div class="col-md-3"><div class="form-group"><label>Max tickets per run</label><input type="number" name="cron_insights_max_per_run" class="form-control" min="1" max="100" value="<?php echo (int) $cronMax; ?>"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Statuses</label>
                                <textarea name="cron_insights_statuses" class="form-control" rows="3" style="font-family:monospace;"><?php echo htmlspecialchars($cronStatuses); ?></textarea>
                                <?php if (!empty($whmcsStatuses)): ?>
                                    <small class="text-muted" style="display:block; margin-top:4px;">
                                        Existing statuses:
                                        <?php foreach ($whmcsStatuses as $ws): ?>
                                            <code style="cursor:pointer; margin-right:4px;" onclick="
                                                var f=document.querySelector('[name=cron_insights_statuses]');
                                                var v=(f && f.value ? f.value.trim() : '');
                                                var s='<?php echo addslashes(htmlspecialchars($ws)); ?>';
                                                if (f) { f.value = v ? v + ', ' + s : s; }
                                            " title="Click to append"><?php echo htmlspecialchars($ws); ?></code>
                                        <?php endforeach; ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted" style="display:block; margin-top:4px;">Comma-separated statuses.</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group"><label>HTTP cron URL (override)</label><input type="text" name="insights_cron_http_url" class="form-control" value="<?php echo htmlspecialchars($cronHttpSaved); ?>"></div>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Cron Settings</button>
                </form>
            </div>
        </div>
        <script>
        (function(){
            var ajaxUrl = <?php echo json_encode($ajaxUrlBase . '&sahdev_act=ajax_handler'); ?>;
            var tokenEl = document.querySelector('input[name="token"]');
            var token = tokenEl ? tokenEl.value : '';
            var msg = document.getElementById('sahdev-cron-msg');
            var out = document.getElementById('sahdev-cron-debug-out');
            function post(action, extra){
                var data = Object.assign({ action: action, token: token }, extra || {});
                return jQuery.post(ajaxUrl, data, null, 'json');
            }
            function show(kind, text){
                if (!msg) return;
                msg.style.display = 'block';
                msg.className = 'alert ' + (kind === 'ok' ? 'alert-success' : 'alert-danger');
                msg.textContent = text;
            }
            jQuery(document).on('click', '#sahdev-trigger-cron', function(){
                post('trigger_cron_run').done(function(res){
                    show((res && res.status === 'success') ? 'ok' : 'err', (res && (res.message || 'Cron trigger completed.')) || 'Cron trigger failed.');
                }).fail(function(xhr){
                    show('err', 'Cron trigger failed: HTTP ' + (xhr && xhr.status ? xhr.status : 'error'));
                });
            });
            jQuery(document).on('click', '#sahdev-test-cron-http', function(){
                var urlField = document.querySelector('[name="insights_cron_http_url"]');
                var testUrl = urlField ? urlField.value : '';
                post('test_whmcs_cron_http', { test_url: testUrl }).done(function(res){
                    var ok = res && res.status === 'success';
                    show(ok ? 'ok' : 'err', (res && (res.message || res.status)) || 'HTTP cron test finished.');
                    if (out) { out.style.display = 'block'; out.textContent = JSON.stringify(res, null, 2); }
                }).fail(function(xhr){
                    show('err', 'HTTP cron test failed: HTTP ' + (xhr && xhr.status ? xhr.status : 'error'));
                });
            });
            jQuery(document).on('click', '#sahdev-debug-insights-cron', function(){
                post('get_insights_queue').done(function(res){
                    if (out) { out.style.display = 'block'; out.textContent = JSON.stringify(res, null, 2); }
                    show((res && res.status === 'success') ? 'ok' : 'err', (res && res.status === 'success') ? 'Queue fetched.' : 'Queue fetch failed.');
                }).fail(function(xhr){
                    show('err', 'Queue debug failed: HTTP ' + (xhr && xhr.status ? xhr.status : 'error'));
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Autopilot Mode settings tab
    // =========================================================================

    public function autopilot(): string
    {
        // Ensure schema exists for new installs without reactivation
        require_once dirname(__DIR__) . '/lib/AutopilotProcessor.php';

        // Hot-migrate autopilot columns & table
        $autopilotCols = [
            'autopilot_enabled' => ['boolean', 0], 'autopilot_admin_id' => ['integer_nullable', null],
            'autopilot_max_replies' => ['integer', 3], 'autopilot_delay_min_sec' => ['integer', 180],
            'autopilot_delay_max_sec' => ['integer', 900], 'autopilot_allowed_depts' => ['text', null],
            'autopilot_blocked_depts' => ['text', null], 'autopilot_blocked_topics' => ['text', null],
            'autopilot_max_urgency' => ['string_col', 'High'], 'autopilot_max_sentiment' => ['integer', 7],
            'autopilot_only_first_reply' => ['boolean', 1], 'autopilot_tone' => ['string_col', 'Friendly'],
            'autopilot_cron_max_per_run' => ['integer', 5], 'autopilot_tag_skipped' => ['boolean', 1],
            'autopilot_draft_mode'       => ['boolean', 0],
            'autopilot_cron_last_run_at' => ['timestamp', null], 'autopilot_cron_last_message' => ['text', null],
        ];
        foreach ($autopilotCols as $col => [$type, $default]) {
            try { Capsule::table('tblsahdev_settings')->select($col)->first(); } catch (\Exception $e) {
                try {
                    Capsule::schema()->table('tblsahdev_settings', function ($tbl) use ($col, $type, $default) {
                        switch ($type) {
                            case 'boolean': $tbl->boolean($col)->default($default ?? 0); break;
                            case 'integer': $c = $tbl->integer($col)->nullable(); if ($default !== null) $c->default($default); break;
                            case 'integer_nullable': $tbl->integer($col)->nullable(); break;
                            case 'text': $tbl->text($col)->nullable(); break;
                            case 'timestamp': $tbl->timestamp($col)->nullable(); break;
                            default: $c = $tbl->string($col, 128)->nullable(); if ($default !== null) $c->default($default);
                        }
                    });
                } catch (\Exception $ex) {}
            }
        }
        try {
            if (!Capsule::schema()->hasTable('tblsahdev_autopilot_log')) {
                Capsule::schema()->create('tblsahdev_autopilot_log', function ($table) {
                    $table->increments('id');
                    $table->integer('ticket_id')->unsigned()->index();
                    $table->integer('reply_id')->unsigned()->nullable();
                    $table->timestamp('ticket_lastreply_snapshot')->nullable();
                    $table->string('ai_decision', 16)->index();
                    $table->string('skip_reason', 128)->nullable();
                    $table->integer('tokens_used')->nullable();
                    $table->timestamp('created_at')->useCurrent();
                });
            }
        } catch (\Throwable $e) {}

        $settings = (array) Capsule::table('tblsahdev_settings')->first();
        $successMsg = '';
        $errorMsg = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_autopilot'])) {
            check_token('WHMCS.admin.default');
            try {
                $allowedDepts = implode(',', array_filter(array_map('intval', (array) ($_POST['autopilot_allowed_depts'] ?? []))));
                $blockedDepts = implode(',', array_filter(array_map('intval', (array) ($_POST['autopilot_blocked_depts'] ?? []))));

                Capsule::table('tblsahdev_settings')->where('id', 1)->update([
                    'autopilot_enabled'          => !empty($_POST['autopilot_enabled']) ? 1 : 0,
                    'autopilot_admin_id'         => (int) ($_POST['autopilot_admin_id'] ?? 0) ?: null,
                    'autopilot_max_replies'       => max(1, min(10, (int) ($_POST['autopilot_max_replies'] ?? 3))),
                    'autopilot_delay_min_sec'     => max(0, (int) ($_POST['autopilot_delay_min_sec'] ?? 180)),
                    'autopilot_delay_max_sec'     => max(0, (int) ($_POST['autopilot_delay_max_sec'] ?? 900)),
                    'autopilot_allowed_depts'     => $allowedDepts,
                    'autopilot_blocked_depts'     => $blockedDepts,
                    'autopilot_blocked_topics'    => trim($_POST['autopilot_blocked_topics'] ?? ''),
                    'autopilot_max_urgency'       => in_array($_POST['autopilot_max_urgency'] ?? '', ['Low','Medium','High','Critical']) ? $_POST['autopilot_max_urgency'] : 'High',
                    'autopilot_max_sentiment'     => max(1, min(10, (int) ($_POST['autopilot_max_sentiment'] ?? 7))),
                    'autopilot_only_first_reply'  => !empty($_POST['autopilot_only_first_reply']) ? 1 : 0,
                    'autopilot_tone'              => in_array($_POST['autopilot_tone'] ?? '', ['Professional','Technical','Friendly','Strict']) ? $_POST['autopilot_tone'] : 'Friendly',
                    'autopilot_cron_max_per_run'  => max(1, min(20, (int) ($_POST['autopilot_cron_max_per_run'] ?? 5))),
                    'autopilot_tag_skipped'       => !empty($_POST['autopilot_tag_skipped']) ? 1 : 0,
                    'autopilot_draft_mode'        => !empty($_POST['autopilot_draft_mode']) ? 1 : 0,
                    'updated_at'                  => \Carbon\Carbon::now(),
                ]);
                $successMsg = 'Autopilot settings saved successfully.';
                $settings = (array) Capsule::table('tblsahdev_settings')->first();
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
            }
        }

        // Load data for form
        $allAdmins = Capsule::table('tbladmins')
            ->select('id', 'firstname', 'lastname', 'username', 'disabled')
            ->where('disabled', 0)
            ->orderBy('firstname')
            ->get();

        $allDepts = Capsule::table('tblticketdepartments')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $allowedDeptIds = array_filter(array_map('intval', explode(',', $settings['autopilot_allowed_depts'] ?? '')));
        $blockedDeptIds = array_filter(array_map('intval', explode(',', $settings['autopilot_blocked_depts'] ?? '')));

        // Recent autopilot log
        $recentLog = Capsule::table('tblsahdev_autopilot_log as al')
            ->leftJoin('tbltickets as t', 't.id', '=', 'al.ticket_id')
            ->select('al.*', 't.tid as ticket_tid', 't.title as ticket_title')
            ->orderBy('al.id', 'desc')
            ->limit(50)
            ->get();

        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=autopilot';
        $csrfToken = generate_token('form');

        ob_start();
        ?>
        <?php echo $this->getNavigationMarkup('autopilot'); ?>

        <style>
            .autopilot-card { background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:22px 28px; margin-bottom:22px; }
            .autopilot-card h4 { margin-top:0; margin-bottom:16px; color:#333; font-size:15px; font-weight:700; border-bottom:1px solid #f0f0f0; padding-bottom:10px; }
            .autopilot-card h4 i { margin-right:7px; }
            .ap-master-switch { display:flex; align-items:center; gap:14px; padding:18px 22px; background:linear-gradient(90deg,#0d6efd10,#0d6efd05); border-radius:8px; margin-bottom:22px; border:1px solid #0d6efd30; }
            .ap-master-switch .ap-toggle-label { font-size:17px; font-weight:700; color:#0d6efd; }
            .ap-badge-on  { background:#198754; color:#fff; padding:3px 10px; border-radius:12px; font-size:12px; }
            .ap-badge-off { background:#6c757d; color:#fff; padding:3px 10px; border-radius:12px; font-size:12px; }
            .ap-dept-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:8px; max-height:220px; overflow-y:auto; padding:8px; background:#f8f9fa; border-radius:6px; border:1px solid #dee2e6; }
            .ap-dept-check { display:flex; align-items:center; gap:6px; font-size:13px; padding:4px 0; }
            .ap-log-table { font-size:12px; }
            .ap-log-replied { color:#198754; font-weight:600; }
            .ap-log-skipped { color:#6c757d; }
        </style>

        <div class="sahdev-page-container">
            <h2 style="margin-bottom:4px;"><i class="fas fa-robot"></i> Autopilot Mode</h2>
            <p class="text-muted" style="margin-bottom:20px;">Automatically reply to qualifying tickets via the cron job. Reuses the existing insights analysis — no extra AI credits are spent on analysis.</p>

            <?php if ($successMsg): ?><div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div><?php endif; ?>
            <?php if ($errorMsg): ?><div class="alert alert-danger"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

            <form method="post" action="<?php echo $actionUrl; ?>">
                <?php echo $csrfToken; ?>
                <input type="hidden" name="save_autopilot" value="1">

                <!-- Master Switch -->
                <div class="ap-master-switch">
                    <div>
                        <div class="ap-toggle-label"><i class="fas fa-robot"></i> Autopilot Auto-Reply</div>
                        <div class="text-muted" style="font-size:13px;">When enabled, Sahdev will automatically post replies to eligible tickets during each cron run.</div>
                    </div>
                    <div style="margin-left:auto;">
                        <?php $apOn = !empty($settings['autopilot_enabled']); ?>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;">
                            <input type="checkbox" name="autopilot_enabled" value="1" <?php echo $apOn ? 'checked' : ''; ?> style="width:20px;height:20px;">
                            <span class="<?php echo $apOn ? 'ap-badge-on':'ap-badge-off'; ?>"><?php echo $apOn ? 'ENABLED':'DISABLED'; ?></span>
                        </label>
                    </div>
                </div>

                <!-- Identity -->
                <div class="autopilot-card">
                    <h4><i class="fas fa-user-tie"></i> Reply Identity</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Reply as admin account <span class="text-danger">*</span></label>
                                <select name="autopilot_admin_id" class="form-control">
                                    <option value="0">— Select an admin account —</option>
                                    <?php foreach ($allAdmins as $adm): ?>
                                        <option value="<?php echo (int)$adm->id; ?>" <?php echo ((int)($settings['autopilot_admin_id'] ?? 0) === (int)$adm->id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(trim($adm->firstname . ' ' . $adm->lastname) . ' (' . $adm->username . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="help-block">Used for authentication and back-end "Operator" badge.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Display Name Override (Hardcoded)</label>
                                <input type="text" name="autopilot_display_name" class="form-control" value="<?php echo htmlspecialchars($settings['autopilot_display_name'] ?? ''); ?>" placeholder="e.g. Sahdev AI">
                                <p class="help-block"><strong>Recommended.</strong> This forces the name to appear as "Sahdev AI" regardless of WHMCS profile settings.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reply Limits -->
                <div class="autopilot-card">
                    <h4><i class="fas fa-sliders-h"></i> Reply Limits</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Max consecutive AI replies per ticket <small class="text-muted">(1–10)</small></label>
                                <input type="number" name="autopilot_max_replies" class="form-control" min="1" max="10" value="<?php echo (int)($settings['autopilot_max_replies'] ?? 3); ?>">
                                <p class="help-block">After this many auto-replies, the ticket is left for a human regardless of follow-up messages.</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Max auto-replies per cron run</label>
                                <input type="number" name="autopilot_cron_max_per_run" class="form-control" min="1" max="20" value="<?php echo (int)($settings['autopilot_cron_max_per_run'] ?? 5); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label style="display:block;">Only reply to first-contact tickets</label>
                                <label class="text-muted" style="font-weight:normal;">
                                    <input type="checkbox" name="autopilot_only_first_reply" value="1" <?php echo !empty($settings['autopilot_only_first_reply']) ? 'checked' : ''; ?>>
                                    Skip if any human admin has already replied
                                </label>
                                <p class="help-block">Recommended. Prevents autopilot from interrupting active human conversations.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delay -->
                <div class="autopilot-card">
                    <h4><i class="fas fa-hourglass-half"></i> Reply Delay (simulate human response time)</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Minimum delay (seconds)</label>
                                <input type="number" name="autopilot_delay_min_sec" class="form-control" min="0" max="86400" value="<?php echo (int)($settings['autopilot_delay_min_sec'] ?? 180); ?>">
                                <p class="help-block">e.g. 180 = 3 min minimum wait</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Maximum delay (seconds)</label>
                                <input type="number" name="autopilot_delay_max_sec" class="form-control" min="0" max="86400" value="<?php echo (int)($settings['autopilot_delay_max_sec'] ?? 900); ?>">
                                <p class="help-block">e.g. 900 = up to 15 min max wait</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Reply tone</label>
                                <select name="autopilot_tone" class="form-control">
                                    <?php foreach (['Professional','Technical','Friendly','Strict'] as $t): ?>
                                        <option <?php echo (($settings['autopilot_tone'] ?? 'Friendly') === $t) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Department Filters -->
                <div class="autopilot-card">
                    <h4><i class="fas fa-filter"></i> Department Filters</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <label><strong>Allow only these departments</strong> <small class="text-muted">(leave all unchecked to allow all)</small></label>
                            <div class="ap-dept-grid">
                                <?php foreach ($allDepts as $dept): ?>
                                    <label class="ap-dept-check">
                                        <input type="checkbox" name="autopilot_allowed_depts[]" value="<?php echo (int)$dept->id; ?>" <?php echo in_array((int)$dept->id, $allowedDeptIds) ? 'checked' : ''; ?>>
                                        <?php echo htmlspecialchars($dept->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label><strong>Always block these departments</strong> <small class="text-muted">(e.g. Abuse, Legal)</small></label>
                            <div class="ap-dept-grid">
                                <?php foreach ($allDepts as $dept): ?>
                                    <label class="ap-dept-check">
                                        <input type="checkbox" name="autopilot_blocked_depts[]" value="<?php echo (int)$dept->id; ?>" <?php echo in_array((int)$dept->id, $blockedDeptIds) ? 'checked' : ''; ?>>
                                        <?php echo htmlspecialchars($dept->name); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:16px;">
                        <label>Blocked topic keywords (comma-separated)</label>
                        <textarea name="autopilot_blocked_topics" class="form-control" rows="2" placeholder="abuse, hack, fraud, legal, chargeback, phishing, dmca"><?php echo htmlspecialchars($settings['autopilot_blocked_topics'] ?? ''); ?></textarea>
                        <p class="help-block">Tickets whose subject contains any of these keywords will be skipped automatically.</p>
                    </div>
                </div>

                <!-- Safety Thresholds -->
                <div class="autopilot-card">
                    <h4><i class="fas fa-shield-alt"></i> Safety Thresholds</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Maximum allowed urgency level</label>
                                <select name="autopilot_max_urgency" class="form-control">
                                    <?php foreach (['Low','Medium','High','Critical'] as $u): ?>
                                        <option <?php echo (($settings['autopilot_max_urgency'] ?? 'High') === $u) ? 'selected' : ''; ?>><?php echo $u; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="help-block">Tickets at higher urgency than this will be skipped and left for human support.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Maximum sentiment score (frustration) <small class="text-muted">1–10, skip if &ge; this</small></label>
                                <input type="number" name="autopilot_max_sentiment" class="form-control" min="1" max="10" value="<?php echo (int)($settings['autopilot_max_sentiment'] ?? 7); ?>">
                                <p class="help-block">Score 1 = very calm, 10 = extremely angry. Tickets with score &ge; this value are skipped. Recommended: 7.</p>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="autopilot_tag_skipped" value="1" <?php echo !empty($settings['autopilot_tag_skipped']) ? 'checked' : ''; ?>>
                            Tag skipped tickets with <code>ai-needs-human</code> in WHMCS Tag Cloud
                        </label>
                    </div>

                    <!-- Draft Mode -->
                    <div class="form-group" style="background:<?php echo !empty($settings['autopilot_draft_mode']) ? '#fff8e1' : '#f8f9fa'; ?>;border:1px solid <?php echo !empty($settings['autopilot_draft_mode']) ? '#f39c12' : '#dee2e6'; ?>;border-radius:8px;padding:14px 18px;">
                        <label style="margin:0;display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="autopilot_draft_mode" value="1" <?php echo !empty($settings['autopilot_draft_mode']) ? 'checked' : ''; ?> style="margin-top:3px">
                            <div>
                                <strong><i class="fas fa-edit" style="color:#f39c12"></i> Draft Mode — Post Internal Notes Instead of Public Replies</strong><br>
                                <small class="text-muted">When enabled, Autopilot will create a private admin note prefixed with <code>🤖 Sahdev Autopilot Draft</code> instead of sending a live reply to the client. Use this to review AI responses before publishing.</small>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Save Autopilot Settings</button>
            </form>

            <hr style="margin:40px 0;">

            <!-- Manual Test Runner -->
            <div class="autopilot-card" style="border-left:4px solid #f39c12;">
                <h4><i class="fas fa-vial"></i> Manual Autopilot Test Runner</h4>
                <p class="text-muted" style="margin-bottom:15px;">Force Autopilot to attempt a reply on a specific ticket right now. This skips the cron job and replies immediately if eligible.</p>
                <div class="form-inline" style="display:flex; gap:10px; align-items:center;">
                    <input type="number" id="ap_test_ticket_id" class="form-control" placeholder="Ticket ID (e.g. 1234)" style="width:200px;">
                    <div class="checkbox" style="margin:0; padding-top:4px;">
                        <label>
                            <input type="checkbox" id="ap_test_bypass" value="1"> Bypass Safety Rules (urgency/sentiment)
                        </label>
                    </div>
                    <button type="button" class="btn btn-warning" id="btn_run_ap_test"><i class="fas fa-play"></i> Run Test</button>
                </div>
                <div id="ap_test_result" style="display:none; margin-top:15px; padding:12px; border-radius:6px; font-size:13px; background:#f8f9fa; border:1px solid #ddd;"></div>
            </div>

            <!-- Status & Run Log -->
            <div class="autopilot-card" style="margin-top:28px;">
                <h4><i class="fas fa-history"></i> Autopilot Run Log
                    <?php if (!empty($settings['autopilot_cron_last_run_at'])): ?>
                        <small class="text-muted" style="font-weight:400;font-size:12px;"> — Last run: <?php echo htmlspecialchars($settings['autopilot_cron_last_run_at']); ?> | <?php echo htmlspecialchars($settings['autopilot_cron_last_message'] ?? ''); ?></small>
                    <?php endif; ?>
                </h4>

                <?php if ($recentLog->count() === 0): ?>
                    <p class="text-muted">No autopilot activity yet. Enable autopilot and run the cron job.</p>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                    <table class="table table-condensed table-striped ap-log-table">
                        <thead><tr><th>Time</th><th>Ticket</th><th>Decision</th><th>Reason</th><th>Tokens</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentLog as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log->created_at); ?></td>
                                <td>
                                    <?php if ($log->ticket_tid): ?>
                                        <a href="<?php echo $GLOBALS['CONFIG']['SystemURL'] ?? ''; ?>/admin/supporttickets.php?action=view&id=<?php echo htmlspecialchars($log->ticket_id); ?>" target="_blank">
                                            #<?php echo htmlspecialchars($log->ticket_tid); ?>
                                        </a>
                                        <?php echo $log->ticket_title ? ' — ' . htmlspecialchars(substr($log->ticket_title, 0, 50)) : ''; ?>
                                    <?php else: ?>
                                        ID #<?php echo (int)$log->ticket_id; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log->ai_decision === 'replied'): ?>
                                        <span class="ap-log-replied"><i class="fas fa-check-circle"></i> replied</span>
                                    <?php else: ?>
                                        <span class="ap-log-skipped"><i class="fas fa-minus-circle"></i> <?php echo htmlspecialchars($log->ai_decision); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($log->skip_reason ?? '—'); ?></td>
                                <td><?php echo $log->tokens_used ? number_format((int)$log->tokens_used) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        document.getElementById('btn_run_ap_test').addEventListener('click', function() {
            var tid = document.getElementById('ap_test_ticket_id').value;
            var bypass = document.getElementById('ap_test_bypass').checked ? '1' : '0';
            var out = document.getElementById('ap_test_result');
            
            if (!tid) {
                alert('Please enter a Ticket ID.');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running...';
            out.style.display = 'block';
            out.innerHTML = 'Executing test run...';
            out.style.borderLeft = '4px solid #ddd';

            var data = new URLSearchParams();
            data.append('action', 'autopilot_test_run');
            data.append('force_ticket_id', tid);
            data.append('bypass_safety', bypass);

            fetch('<?php echo htmlspecialchars($this->moduleVars['modulelink']); ?>&sahdev_act=ajax_handler', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString()
            })
            .then(r => r.json())
            .then(res => {
                let html = '';
                if (res.status === 'success') {
                    out.style.borderLeft = '4px solid #198754';
                    html = '<strong style="color:#198754;"><i class="fas fa-check-circle"></i> Success:</strong> ' + res.message;
                    if (res.preview) {
                        html += '<hr style="margin:10px 0;"><pre style="margin:0; background:transparent; border:none; padding:0; white-space:pre-wrap; font-family:inherit;">' + res.preview + '</pre>';
                    }
                } else {
                    out.style.borderLeft = '4px solid #dc3545';
                    html = '<strong style="color:#dc3545;"><i class="fas fa-times-circle"></i> Error/Skipped:</strong> ' + (res.message || 'Unknown error');
                }
                out.innerHTML = html;
            })
            .catch(err => {
                out.style.borderLeft = '4px solid #dc3545';
                out.innerHTML = '<strong style="color:#dc3545;"><i class="fas fa-times-circle"></i> Exception:</strong> ' + err;
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-play"></i> Run Test';
            });
        });
        </script>

        <?php
        return ob_get_clean();
    }

    public function logs_maintenance()
    {
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        if ($adminId <= 0) {
            return '<div class="alert alert-danger">Not logged in.</div>';
        }

        $successMessage = '';
        $errorMessage = '';

        $logTables = [
            'tblsahdev_tool_runs' => 'Tool Execution Raw Logs',
            'tblsahdev_audit_trail' => 'AI Audit Trail (General)',
            'tblsahdev_autopilot_log' => 'Autopilot Run Logs',
            'tblsahdev_tool_suggestions' => 'Tool Suggestions AI Checks'
        ];

        $selectedTable = $_GET['log_table'] ?? $_POST['log_table'] ?? 'tblsahdev_tool_runs';
        if (!isset($logTables[$selectedTable])) {
            $selectedTable = 'tblsahdev_tool_runs';
        }

        // 1. Check Schema for the table
        $tableExists = true;
        try {
            Capsule::table($selectedTable)->first();
        } catch (\Exception $e) {
            $tableExists = false;
        }

        // 2. Handle POST Actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
            check_token('WHMCS.admin.default');

            if (isset($_POST['delete_logs'])) {
                $days = (int)($_POST['delete_days'] ?? -1);
                try {
                    if ($days === 0) {
                        Capsule::table($selectedTable)->truncate();
                        $successMessage = "All records truncated successfully from {$selectedTable}.";
                    } elseif ($days > 0) {
                        $cutoffDate = \Carbon\Carbon::now()->subDays($days);
                        $deleted = Capsule::table($selectedTable)
                            ->where('created_at', '<', $cutoffDate)
                            ->delete();
                        $successMessage = "Deleted {$deleted} records older than {$days} days from {$selectedTable}.";
                    }
                } catch (\Exception $e) {
                    $errorMessage = "Deletion failed: " . $e->getMessage();
                }
            }

            if (isset($_POST['download_logs'])) {
                $format = $_POST['download_format'] ?? 'json';
                $days = (int)($_POST['download_days'] ?? 0);
                
                try {
                    $query = Capsule::table($selectedTable)->orderBy('id', 'desc');
                    if ($days > 0) {
                        $query->where('created_at', '>=', \Carbon\Carbon::now()->subDays($days));
                    }
                    $results = $query->get();

                    if ($format === 'csv') {
                        header('Content-Type: text/csv');
                        header('Content-Disposition: attachment;filename="' . $selectedTable . '_' . date('Ymd_His') . '.csv"');
                        $out = fopen('php://output', 'w');
                        $first = true;
                        foreach ($results as $row) {
                            $rowArr = (array) $row;
                            if ($first) {
                                fputcsv($out, array_keys($rowArr));
                                $first = false;
                            }
                            fputcsv($out, array_values($rowArr));
                        }
                        fclose($out);
                        exit;
                    } else {
                        header('Content-Type: application/json');
                        header('Content-Disposition: attachment;filename="' . $selectedTable . '_' . date('Ymd_His') . '.json"');
                        echo json_encode($results, JSON_PRETTY_PRINT);
                        exit;
                    }
                } catch (\Exception $e) {
                    $errorMessage = "Download failed: " . $e->getMessage();
                }
            }
        }

        // 3. Fetch Paginated Data
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        $results = [];
        $totalRecords = 0;
        $totalPages = 1;
        $columns = [];

        if ($tableExists) {
            try {
                $totalRecords = Capsule::table($selectedTable)->count();
                $totalPages = ceil($totalRecords / $limit) ?: 1;
                $results = Capsule::table($selectedTable)
                    ->orderBy('id', 'desc')
                    ->offset($offset)
                    ->limit($limit)
                    ->get();
                
                if (count($results) > 0) {
                    $columns = array_keys((array)$results[0]);
                }
            } catch (\Exception $e) {
                $errorMessage = "Error fetching data: " . $e->getMessage();
            }
        } else {
            $errorMessage = "Table {$selectedTable} does not exist in the database.";
        }

        // 4. Render HTML
        $csrfToken = generate_token("form");
        $actionUrl = htmlspecialchars($this->moduleVars['modulelink']) . '&action=logs_maintenance';
        
        ob_start();
        ?>
        <style>
            .log-table-wrapper { width: 100%; overflow-x: auto; background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 6px; }
            .log-table th { background: #f8f9fa; border-bottom: 2px solid #ddd; padding: 10px; white-space: nowrap; }
            .log-table td { padding: 8px 10px; border-bottom: 1px solid #eee; vertical-align: top; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .log-table-row:hover td { background-color: #f1f8ff; }
            .log-td-expandable { cursor: pointer; color: #0d6efd; text-decoration: underline; font-family: monospace; font-size: 12px; }
            .modal-content-pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 500px; }
        </style>

        <?php echo $this->getNavigationMarkup('logs_maintenance'); ?>
        <div class="sahdev-page-container" style="max-width: 100% !important;">

            <div class="row">
                <div class="col-md-9">
                    <h2 style="margin-top:0;">Logs & Maintenance</h2>
                    <p class="text-muted">Inspect raw API execution outputs, track Autopilot behavior, and clean up historical module data to free up database space.</p>
                </div>
            </div>

            <?php if ($successMessage): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- Table Selector & Actions -->
            <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; margin-bottom: 20px;">
                <div class="row">
                    <!-- Left: Selector -->
                    <div class="col-md-4">
                        <form method="get" action="" class="form-inline">
                            <input type="hidden" name="module" value="sahdev">
                            <input type="hidden" name="action" value="logs_maintenance">
                            <label><strong>Select Log to View:</strong></label><br>
                            <select name="log_table" class="form-control" onchange="this.form.submit()" style="max-width:300px;">
                                <?php foreach ($logTables as $tbl => $label): ?>
                                    <option value="<?php echo $tbl; ?>" <?php echo $tbl === $selectedTable ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <!-- Right: Maintenance Actions -->
                    <div class="col-md-8 text-right" style="border-left: 1px solid #ddd;">
                        <form method="post" action="<?php echo $actionUrl; ?>&log_table=<?php echo $selectedTable; ?>" class="form-inline" style="display:inline-block; margin-right: 15px;">
                            <input type="hidden" name="token" value="<?php echo $csrfToken; ?>">
                            <label><strong>Export Data:</strong></label><br>
                            <select name="download_days" class="form-control input-sm">
                                <option value="0">All Time</option>
                                <option value="7">Last 7 Days</option>
                                <option value="30">Last 30 Days</option>
                            </select>
                            <select name="download_format" class="form-control input-sm">
                                <option value="json">JSON</option>
                                <option value="csv">CSV</option>
                            </select>
                            <button type="submit" name="download_logs" class="btn btn-default btn-sm"><i class="fas fa-download"></i> Download</button>
                        </form>

                        <form method="post" action="<?php echo $actionUrl; ?>&log_table=<?php echo $selectedTable; ?>" class="form-inline" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to permanently delete these logs?');">
                            <input type="hidden" name="token" value="<?php echo $csrfToken; ?>">
                            <label><strong>Cleanup Table:</strong></label><br>
                            <select name="delete_days" class="form-control input-sm">
                                <option value="-1">-- select age --</option>
                                <option value="30">Older than 30 Days</option>
                                <option value="7">Older than 7 Days</option>
                                <option value="0">TRUNCATE ENTIRE TABLE</option>
                            </select>
                            <button type="submit" name="delete_logs" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete Clean</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom: 10px;">
                <div>
                    <strong>Total Records:</strong> <?php echo number_format($totalRecords); ?> 
                    | <strong>Page:</strong> <?php echo $page; ?> of <?php echo $totalPages; ?>
                </div>
                <div>
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $actionUrl; ?>&log_table=<?php echo $selectedTable; ?>&page=<?php echo $page - 1; ?>" class="btn btn-default btn-sm"><i class="fas fa-chevron-left"></i> Prev</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $actionUrl; ?>&log_table=<?php echo $selectedTable; ?>&page=<?php echo $page + 1; ?>" class="btn btn-default btn-sm">Next <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- The Grid -->
            <div class="log-table-wrapper">
                <?php if (count($results) > 0): ?>
                    <table class="table log-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $col): ?>
                                    <th><?php echo htmlspecialchars($col); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $row): ?>
                                <tr class="log-table-row">
                                    <?php foreach ($columns as $col): ?>
                                        <?php 
                                            // Format cell content
                                            $val = $row->$col;
                                            $isLargeText = false;
                                            $preview = htmlspecialchars((string)$val);
                                            
                                            // Handle JSON or long text
                                            if (is_string($val) && (strpos($val, '{') === 0 || strpos($val, '[') === 0 || strlen($val) > 80)) {
                                                $isLargeText = true;
                                                $preview = htmlspecialchars(substr($val, 0, 80)) . '...';
                                            }
                                        ?>
                                        <td>
                                            <?php if ($isLargeText): ?>
                                                <span class="log-td-expandable" onclick="viewLogModal(this)" data-full="<?php echo base64_encode((string)$val); ?>"><?php echo $preview; ?></span>
                                            <?php else: ?>
                                                <?php echo $preview; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="padding: 30px; text-align: center; color: #777;">
                        <i class="fas fa-inbox fa-3x" style="color:#ddd; margin-bottom:15px; display:block;"></i>
                        No records found in <?php echo htmlspecialchars($selectedTable); ?>.
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Raw View Modal -->
        <div class="modal fade" id="logViewModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg" style="width: 90%; max-width: 1200px;">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                        <h4 class="modal-title">Raw Log Data Viewer</h4>
                    </div>
                    <div class="modal-body" style="background:#f8f9fa;">
                        <pre class="modal-content-pre" id="logViewRawOutput"></pre>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function viewLogModal(el) {
            var rawBase64 = el.getAttribute('data-full');
            var rawText = '';
            try {
                // b64 decode
                rawText = decodeURIComponent(escape(window.atob(rawBase64)));
                // Attempt JSON prettify
                var parsed = JSON.parse(rawText);
                rawText = JSON.stringify(parsed, null, 2);
            } catch (e) {
                // Keep raw text if not JSON or decode fails
            }
            document.getElementById('logViewRawOutput').textContent = rawText;
            $('#logViewModal').modal('show');
        }
        </script>

        <?php
        return ob_get_clean();
    }

}

