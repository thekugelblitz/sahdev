# Sahdev — AI Ticket Intelligence Assistant
## Developer Codebase Reference

> **Version:** 2.1.0 — Updated: 2026-03-03
> **Auto-update policy:** This file should be updated whenever a new feature, table, class method, AJAX action, prompt key, or architectural decision is added/changed.
> **Purpose:** Full A-to-Z developer and AI-IDE reference. Covers architecture, data flow, all files, all DB tables, all AJAX endpoints, all prompt keys, and extension guide.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Directory Structure](#2-directory-structure)
3. [Architecture Overview](#3-architecture-overview)
4. [File-by-File Reference](#4-file-by-file-reference)
   - 4.1 [sahdev.php — Module Bootstrap & DB Schema](#41-sahdevphp--module-bootstrap--db-schema)
   - 4.2 [ajax.php — AJAX Endpoint](#42-ajaxphp--ajax-endpoint)
   - 4.3 [hooks.php — Frontend Panel](#43-hooksphp--frontend-panel)
   - 4.4 [lib/AIProviderInterface.php](#44-libaiproviderinterfacephp)
   - 4.5 [lib/GoogleAIProvider.php](#45-libgoogleaiprovidephp)
   - 4.6 [lib/LMStudioAIProvider.php](#46-liblmstudioaiprovidephp)
   - 4.7 [lib/ReplicateAIProvider.php](#47-libreplicateaiprovidephp)
   - 4.8 [lib/TicketDataExtractor.php](#48-libtickeddataextractorphp)
   - 4.9 [lib/AIController.php](#49-libaicontrollerphp)
   - 4.10 [controllers/AdminController.php](#410-controllersadmincontrollerphp)
5. [Database Schema](#5-database-schema)
6. [AJAX API Reference](#6-ajax-api-reference)
7. [Prompt Template Library](#7-prompt-template-library)
8. [Frontend JavaScript Architecture](#8-frontend-javascript-architecture)
9. [Data Flow Diagrams](#9-data-flow-diagrams)
10. [Settings Reference](#10-settings-reference)
11. [Adding a New AI Provider](#11-adding-a-new-ai-provider)
12. [Adding a New Feature](#12-adding-a-new-feature)
13. [Security Design](#13-security-design)
14. [Version History](#14-version-history)

---

## 1. Project Overview

**Sahdev** is a WHMCS Addon Module that injects AI intelligence directly into the WHMCS admin ticket view. It connects to large language model (LLM) providers (Google Gemini, local LM Studio / OpenAI-compatible endpoints, or Replicate) to:

- Analyze support tickets and generate structured JSON analysis (`ROOT_CAUSE`, `RESPONSIBILITY`, `RISK_LEVEL`, `INTERNAL_ACTION_PLAN`, `CLIENT_REPLY`)
- Generate and manage AI ticket summaries (token optimization)
- Analyze client history for context-aware replies
- Rewrite/expand admin draft replies
- Score reply quality (QA)
- Search and save canned responses and KB articles
- Manage all prompt templates from the backend (Prompt Library)
- Track analytics, token usage, and AI audit trails

**Requirements:** WHMCS 8.x, PHP 7.4+, MySQL

---

## 2. Directory Structure

```
sahdev/                          ← WHMCS module root (modules/addons/sahdev/)
├── CODEBASE.md                  ← This file (developer reference)
├── README.md                    ← End-user setup guide
├── NextUpgradePlan.md           ← Future feature ideas backlog
├── sahdev.php                   ← WHMCS module entry: config, activate, deactivate, output, routing
├── ajax.php                     ← Secured AJAX endpoint (all frontend-to-backend calls)
├── hooks.php                    ← WHMCS hook: injects AI panel HTML+JS into ticket view
├── lib/
│   ├── AIProviderInterface.php  ← Contract interface all AI providers must implement
│   ├── GoogleAIProvider.php     ← Google Gemini (generateContent API) implementation
│   ├── LMStudioAIProvider.php   ← OpenAI-compatible local/remote API implementation
│   ├── ReplicateAIProvider.php  ← Replicate.com Predictions API implementation
│   ├── TicketDataExtractor.php  ← Secure WHMCS ticket context extractor
│   ├── AdminPreferences.php     ← Per-admin feature flags, default provider/tone; used by ajax + hooks
│   └── AIController.php         ← Core orchestrator: all public feature methods
├── controllers/
│   └── AdminController.php      ← Admin backend UI: settings, providers, prompt manager, analytics
└── knowledgebase/               ← .txt rule files appended to system prompt automatically
    └── *.txt
```

---

## 3. Architecture Overview

```
Browser (WHMCS Admin Ticket Page)
         │
         │ hooks.php injects HTML panel + JavaScript
         │
         ▼
┌─────────────────────────────────────────┐
│  sahdev-ai-panel (HTML)                 │
│  + JavaScript in hooks.php              │
│  - Intent selector, tone, intensity      │
│  - Summary panel, History panel          │
│  - Canned/KB search panel                │
└───────────────┬─────────────────────────┘
                │ AJAX POST to ajax.php?sahdev_act=ajax_handler
                ▼
┌─────────────────────────────────────────┐
│  ajax.php                               │
│  - Validates session (admin only)        │
│  - Routes action → AIController method  │
└───────────────┬─────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────┐
│  AIController (lib/AIController.php)    │
│  - loadSettings() → loads DB settings   │
│  - loadPromptTemplates() → prompt lib   │
│  - TicketDataExtractor → ticket context │
│  - Cache check (tblsahdev_cache)        │
│  - AI Provider call                      │
│  - Audit + Quality Score logging        │
└────────┬────────────────────────────────┘
         │
         ├── GoogleAIProvider   → Google Gemini REST API
         ├── LMStudioAIProvider → OpenAI-compatible (local or remote)
         └── ReplicateAIProvider → Replicate.com Predictions API

Admin Backend (sahdev.php → AdminController.php)
         │
         └── Settings, Providers, Prompt Manager, Summaries, Audit Trail, Analytics
```

### Key Design Principles

| Principle | Implementation |
|---|---|
| Database-driven prompts | All 7 prompt types stored in `tblsahdev_prompt_templates` |
| Response caching | SHA-256 hash of inputs → `tblsahdev_cache` |
| Token optimization | AI Summary replaces full message history in prompts |
| Provider abstraction | All providers implement `AIProviderInterface` |
| Inline DB migrations | `ensureSchemaIntegrity()` + `try/catch` column checks |
| CSRF protection | `generate_token()` / `check_token()` on all forms |
| PII scrubbing | Optional compliance mode via `TicketDataExtractor::scrubPII()` |
| Fallback providers | Primary fails → automatic switch to configured fallback |

---

## 4. File-by-File Reference

### 4.1 `sahdev.php` — Module Bootstrap & DB Schema

**Role:** WHMCS addon module entry point. Defines 4 required module functions and handles admin backend routing.

#### Key Functions

| Function | Description |
|---|---|
| `sahdev_config()` | Returns WHMCS module metadata (name, version `2.0.0`, access control) |
| `sahdev_activate()` | Creates ALL database tables on first activation. Also handles inline migrations for updates |
| `sahdev_deactivate()` | Currently a no-op (tables preserved intentionally) |
| `sahdev_output($vars)` | Admin backend entry point — loads `AdminController`. Routes via `$_GET['action']` |

#### Tables Created on Activation (see §5 for full schema)

- `tblsahdev_settings`
- `tblsahdev_providers`
- `tblsahdev_logs`
- `tblsahdev_cache`
- `tblsahdev_rate_limit`
- `tblsahdev_summaries`
- `tblsahdev_historical_context`
- `tblsahdev_audit_trail`
- `tblsahdev_quality_scores`
- `tblsahdev_canned_responses`
- `tblsahdev_client_context_cache`

#### Admin Backend Routing

`sahdev.php` routes `$_GET['action']` to `AdminController` methods:

```
action (none / '')       → AdminController::index()         [Dashboard]
action=settings          → AdminController::settings()
action=providers         → AdminController::providers()
action=add_provider      → AdminController::addProvider()
action=edit_provider     → AdminController::editProvider()
action=delete_provider   → AdminController::deleteProvider()
action=prompt_manager    → AdminController::prompt_manager()
action=knowledgebase     → AdminController::knowledgebase()
action=summaries         → AdminController::summaries()
action=audit_trail       → AdminController::auditTrail()
action=analytics         → AdminController::analytics()
```

---

### 4.2 `ajax.php` — AJAX Endpoint

**URL:** `addonmodules.php?module=sahdev&sahdev_act=ajax_handler`

**Auth:** Requires `$_SESSION['adminid']` — returns 403 if not set.
**Method:** POST only — returns 405 on GET.
**Output:** Always `Content-Type: application/json`.

#### Input Parameters

| Parameter | Type | Description |
|---|---|---|
| `action` | string | Route selector (see AJAX API Reference §6) |
| `ticket_id` | int | WHMCS ticket ID (required for most actions) |
| `tone` | string | AI tone: Professional, Technical, Friendly, Strict, Custom |
| `instruction` | string | Optional admin override instruction |
| `intensity` | int (1–5) | Dive intensity; 4–5 appends detailed instruction to prompt |
| `intent` | string | Reply intent: AUTO, RESOLVE, INVESTIGATE, MORE_INFO, GUIDE, OUT_OF_SCOPE, DUPLICATE |
| `use_summary` | bool | Whether to use AI summary instead of full history |
| `include_historical_context` | bool | Whether to inject historical client context |
| `force_regenerate` | bool string | Skip cache and regenerate |
| `force_fallback` | bool string | Skip primary provider and use fallback |

#### Auto-migration Guard

`ajax.php` runs a quick `tblsahdev_providers/tblsahdev_settings` existence check and calls `sahdev_activate()` if tables are missing — ensuring AJAX never crashes after a code update without re-activation.

---

### 4.3 `hooks.php` — Frontend Panel

**Hook:** `AdminAreaViewTicketPage` — injects HTML + JS directly into WHMCS admin ticket pages.

#### Panel Sections

| Section | Element ID | Description |
|---|---|---|
| Main AI Panel | `#sahdev-ai-panel` | Intent buttons, tone, intensity, custom instruction, Analyze button |
| AI Snapshot | `#sahdev-snapshot-outer` | Auto-loads cached analysis on page load (if `auto_analyze_on_load=1`) |
| AI Summarizer | `#sahdev-summarizer-outer` | Generate/view/delete AI ticket summary |
| Canned/KB Search | `#sahdev-canned-outer` | Fuzzy search across canned responses, WHMCS predefined replies, and KB articles |
| Historical Context | `#sahdev-history-outer` | Analysis of client's past N tickets |

#### Key JavaScript Functions

| Function | Description |
|---|---|
| `insertSahdevToTinyMce()` | Inserts generated reply HTML into TinyMCE editor or raw textarea |
| `copySahdevReply()` | Copies reply to clipboard (HTML → plain text) |
| `executeBackendGoogleCall()` | Makes server-side call (`action=analyze_ticket`) for Google/Replicate providers |
| `executeLocalLMStudioCall()` | Makes direct browser-to-LMStudio `fetch()` call — avoids PHP timeout for local models |
| `saveResponseToBackend()` | POSTs LM Studio result to `action=save_response` for caching and logging |
| `renderSahdevResults()` | Populates all output elements with AI response fields |
| `robustJsonParse()` | 5-stage JSON recovery parser: direct → strip fences → brace extract → repair truncated → regex fallback |
| `repairTruncatedJson()` | Stack-based incomplete JSON fixer (closes open strings/arrays/objects) |
| `buildPromptText()` | Replicates server-side prompt building in JavaScript for LM Studio `get_payload` flow |

#### LM Studio Context Window Guard

Before calling LM Studio, the JS enforces a conservative 4096 token context budget:
- Reserves `max_tokens` for output
- Truncates `systemMessage` if > 40% of budget
- Truncates `promptText` if remainder overflows
- Logs a console warning when trimming occurs

---

### 4.4 `lib/AIProviderInterface.php`

**Namespace:** `Sahdev\Lib`

**Contract** all AI providers must implement:

```php
interface AIProviderInterface {
    generateResponse(array $context, array $settings, string $tone, string $customInstruction): array
    getLastTokenUsage(): int
    getLastTokenDetails(): array          // ['input' => int, 'output' => int]
    getProviderType(): string             // 'google' | 'lmstudio' | 'replicate'
    getName(): string
    getApiUrl(): string
    getAvailableModels(string $apiKey): array
}
```

**`generateResponse()` contract:** Must return an associative array with at minimum these keys:

```php
[
    'ROOT_CAUSE'           => string,
    'RESPONSIBILITY'       => string,   // Client | Host | 3rd Party
    'RISK_LEVEL'           => string,   // Low | Medium | High | Critical
    'INTERNAL_ACTION_PLAN' => string,
    'CLIENT_REPLY'         => string,
]
```

Optional scoring fields: `SCORE`, `CLARITY`, `TONE_SCORE`, `COMPLETENESS`, `REPLY_NOTES` (all int or string).

---

### 4.5 `lib/GoogleAIProvider.php`

**Class:** `Sahdev\Lib\GoogleAIProvider implements AIProviderInterface`

**API:** Google Generative Language REST API
**Endpoint pattern:** `https://generativelanguage.googleapis.com/v1beta/{model}:generateContent?key={apiKey}`

#### Key Behaviors

- Sends `systemInstruction.parts[0].text` as system prompt (Gemini API format)
- Sends `contents[0].parts` as array of text/inline_data items (supports images as base64 inline_data)
- Requests `responseMimeType: "application/json"` for structured output
- Reads `candidates[0].content.parts[0].text` for the response
- Retry loop: 1 retry on failure with 2s sleep
- Strips `<think>...</think>` blocks (DeepSeek model compat)
- Strips markdown code fences from response

#### Model Support

Any `models/gemini-*` identifier. Flash models recommended for speed; Pro models for accuracy.

---

### 4.6 `lib/LMStudioAIProvider.php`

**Class:** `Sahdev\Lib\LMStudioAIProvider implements AIProviderInterface`

**API:** OpenAI-compatible chat completions (`/v1/chat/completions`)
**Auth:** `Authorization: Bearer {apiKey}` (defaults to `'local'` if key is empty)
**Timeout:** 120 seconds (local models can be slow)

#### Key Behaviors

- Auto-appends `/v1/chat/completions` if user only provides base URL
- Sends `response_format: {"type": "json_object"}` for LM Studio v1-compatible endpoints
- Reads `choices[0].message.content`
- Strips `<think>...</think>` (DeepSeek-R1 format)
- Strips markdown code fences
- Model discovery via `/v1/models` endpoint

**Important:** For LMStudio type providers, the actual AI call is made **by the browser** (JavaScript `fetch()`), not from the PHP backend, to avoid server-side timeouts on long local model inference. The PHP backend returns a payload via `get_payload`, the browser calls LM Studio directly, then POSTs the result back via `save_response`.

---

### 4.7 `lib/ReplicateAIProvider.php`

**Class:** `Sahdev\Lib\ReplicateAIProvider implements AIProviderInterface`

**API:** Replicate Predictions API (`POST /v1/models/{owner}/{model}/predictions`)
**Auth:** `Authorization: Bearer {apiKey}` + `Prefer: wait=60` (sync mode)
**Timeout:** 120s per request; poll interval 2s, max 60 polls (120s max wait)

#### Key Behaviors

- Uses `Prefer: wait=60` header for synchronous response (avoids polling when model responds fast)
- Falls back to polling `result['urls']['get']` if status is `starting` or `processing`
- Gemini models on Replicate: strips `system_prompt` (unsupported), maps `max_tokens` → `max_output_tokens`, prepends system instruction into prompt
- Output can be string, array of string tokens (joined), or structured JSON
- Estimates token usage via character count ÷ 4 (Replicate doesn't always report usage)
- Returns `['__raw_text__' => ...]` if output is not JSON (freeform rewrite mode)

---

### 4.8 `lib/TicketDataExtractor.php`

**Class:** `Sahdev\Lib\TicketDataExtractor`
**Namespace:** `Sahdev\Lib`

Extracts all ticket context securely using Capsule ORM (no raw SQL).

#### Constructor

```php
__construct(int $ticketId, int $adminId = null)
```

#### Main Method

```php
getContext(bool $scrubPII = false): array
```

Returns:

```php
[
    'subject'             => string,    // tbltickets.title
    'priority'            => string,    // tbltickets.urgency
    'department'          => string,    // tblticketdepartments.name
    'client_name'         => string,    // tblclients.firstname + lastname (or ticket.name)
    'services_summary'    => string,    // Active services (tblhosting JOIN tblproducts), max 5
    'messages'            => array,     // [{date, message, admin: bool}], newest 10, capped 3k chars each
    'attachments_text'    => string,    // Content of .txt/.log/.csv/.json etc. files ≤1MB
    'attachments_images'  => array,     // [{url: base64DataUri, source: string}], max 3
    'admin_signature'     => string,    // tbladmins.signature (sanitized HTML)
]
```

#### Attachment Directory Resolution (5-step fallback)

1. `tblsahdev_settings.custom_attachments_dir` (admin override)
2. `$attachments_dir` global PHP variable
3. `configuration.php` include
4. `tblconfiguration WHERE setting = 'Attachments_Dir'`
5. `{whmcs_root}/attachments`

#### Image Extraction

- Parses attachment files for `.png/.jpg/.jpeg/.gif/.webp` — reads and base64-encodes (≤5MB)
- Extracts direct image URLs from message text via regex
- Parses `prnt.sc` screenshot links (fetches the page and extracts the actual image URL)
- Fetches remote URLs via cURL `User-Agent: Chrome` (prevents 403 from CDNs)

#### PII Scrubber (`scrubPII()`)

Controlled by `tblsahdev_settings` columns:

| Column | Redacts |
|---|---|
| `scrub_cc` | Credit card numbers (13–16 digits) |
| `scrub_emails` | Email addresses |
| `scrub_ips` | IPv4 addresses |
| `scrub_passwords` | `password: xxx`, `pass: xxx`, `pwd: xxx` patterns |

---

### 4.9 `lib/AIController.php`

**Class:** `Sahdev\Lib\AIController`
**Namespace:** `Sahdev\Lib`

The core business logic orchestrator. Instantiated per-request in `ajax.php`.

#### Constructor

```php
__construct(int $ticketId, int $adminId)
```

Calls `loadSettings()` which:
1. Runs auto-migration check (calls `sahdev_activate()` if tables are missing)
2. Loads `$this->settings` from `tblsahdev_settings` (cast to array)
3. Calls `loadPromptTemplates()` — loads prompt library from DB
4. Initializes primary AI provider from `tblsahdev_providers`
5. Initializes optional fallback provider

#### Properties

| Property | Type | Description |
|---|---|---|
| `$ticketId` | int | Current ticket ID |
| `$adminId` | int | Active admin ID |
| `$provider` | AIProviderInterface | Primary AI provider instance |
| `$fallbackProvider` | AIProviderInterface\|null | Fallback provider instance |
| `$settings` | array | Global settings from `tblsahdev_settings` + injected provider fields |
| `$promptTemplates` | array | All prompt keys loaded from `tblsahdev_prompt_templates` |

#### Public Methods

| Method | Returns | Description |
|---|---|---|
| `getAnalysis(tone, instruction, forceRegen, forceFallback, intent, useSummary, includeHistory)` | array | Full server-side ticket analysis (Google/Replicate). Checks cache, calls provider, saves cache, logs audit |
| `getPayload(tone, instruction, forceRegen, intent, useSummary, includeHistory)` | array | Returns config + context for browser-side LM Studio execution. Cache-first. |
| `saveResponse(hashSignature, response, tokenUsage, execTime, tokenDetails)` | array | Persists LM Studio response (browser-generated) to cache + logs |
| `generateSummary()` | array | AI-summarizes full ticket conversation. Saves to `tblsahdev_summaries` |
| `getSummary()` | string\|null | Returns existing summary text for ticket |
| `deleteSummary()` | array | Deletes saved summary |
| `generateHistoricalContext(int $limit)` | array | Analyzes client's past N tickets. Saves to `tblsahdev_historical_context` |
| `getHistoricalContext()` | string\|null | Returns saved historical context |
| `deleteHistoricalContext()` | array | Deletes saved historical context |
| `rewriteReply(draft, tone, instruction)` | array | Server-side draft expansion (Google/Replicate only) |
| `getRewritePayload(draft, tone, instruction)` | array | Returns rewrite prompt payload for browser LM Studio call |
| `scoreReply(replyText, isAiGenerated)` | array | QA scoring of a reply — returns JSON with SCORE, CLARITY, TONE_SCORE, COMPLETENESS, REPLY_NOTES |
| `generateCannedTemplate(draftText)` | array | Generalizes a draft into a reusable canned response template |
| `saveCannedResponse(title, templateText)` | array | Saves generalized template to `tblsahdev_canned_responses` |
| `saveKbArticle(title, templateText)` | array | Saves template as WHMCS KB article (creates Sahdev-tagged category) |
| `searchCannedResponses(query)` | array | Fuzzy LIKE search across canned responses, WHMCS predefined replies, and KB |
| `getAnalyticsData()` | array | Returns analytics object for dashboard charts |

#### Private Methods

| Method | Description |
|---|---|
| `loadSettings()` | Boots settings + providers. Auto-migrates DB if needed |
| `loadPromptTemplates()` | Loads all 7 prompt types from `tblsahdev_prompt_templates` with hardcoded fallback |
| `initializeProvider($providerData)` | Factory — creates GoogleAIProvider, LMStudioAIProvider, or ReplicateAIProvider |
| `checkRateLimit()` | Checks per-admin rate limit (currently soft, logs only) |
| `incrementRateLimit()` | Upserts `tblsahdev_rate_limit` counter |
| `buildIntentDirective(string $intent): string` | Returns a text directive block for the given intent (injected as top-priority instruction) |
| `logRequest(context, response, tokens, time, error)` | Inserts row to `tblsahdev_logs` |
| `logAuditEntry(type, prompt, response, tokens, time, provider)` | Inserts full prompt+response to `tblsahdev_audit_trail` |

#### Intent Directives (built by `buildIntentDirective`)

| Intent | Text Injected |
|---|---|
| `AUTO` | (none — AI decides) |
| `RESOLVE` | "Issue confirmed resolved. CLIENT_REPLY must close the ticket professionally." |
| `INVESTIGATE` | "Currently investigating. CLIENT_REPLY must acknowledge and set expectations." |
| `MORE_INFO` | "CLIENT_REPLY must request specific additional information." |
| `GUIDE` | "CLIENT_REPLY must guide the client step-by-step to self-resolve." |
| `OUT_OF_SCOPE` | "Issue is outside scope. CLIENT_REPLY must decline politely and redirect." |
| `DUPLICATE` | "This is a duplicate ticket. CLIENT_REPLY must note this and reference original." |

#### Caching Logic

Hash inputs: `[subject, messages[], tone, customInstruction, intent, model_name, system_prompt]`
→ SHA-256 → check `tblsahdev_cache WHERE ticket_id = X AND hash_signature = Y`

If found and `force_regenerate=false` → return cached `ai_response` JSON.
If not found → call provider → insert new cache row.

---

### 4.10 `controllers/AdminController.php`

**Class:** `AdminController` (no namespace)

Renders all admin backend pages. Loaded and instantiated in `sahdev.php::sahdev_output()`.

#### Constructor

```php
__construct(array $moduleVars)
```

Calls `ensureSchemaIntegrity()` on every page load — runs inline migrations for missing columns/tables.

#### Page Methods

| Method | URL Action | Description |
|---|---|---|
| `index()` | (default) | Dashboard: stats cards, quick settings toggle |
| `settings()` | `action=settings` | General settings: temperature, max tokens, tone default, system prompt, feature toggles |
| `providers()` | `action=providers` | List all AI providers; set primary/fallback |
| `addProvider()` | `action=add_provider` | Add new provider (google, lmstudio, replicate) |
| `editProvider()` | `action=edit_provider` | Edit existing provider config |
| `deleteProvider()` | `action=delete_provider` | Remove provider |
| `prompt_manager()` | `action=prompt_manager` | **Prompt Template Library** — see §7 |
| `knowledgebase()` | `action=knowledgebase` | Manage `.txt` rule files in `knowledgebase/` dir |
| `summaries()` | `action=summaries` | CRUD view of all saved AI summaries |
| `auditTrail()` | `action=audit_trail` | View + delete audit trail entries |
| `analytics()` | `action=analytics` | Charts + stats: token usage, response times, quality scores |

#### Schema Integrity (`ensureSchemaIntegrity()`)

Called on constructor. Uses try/catch to check if critical columns exist and creates them if not. Also creates `tblsahdev_prompt_templates` and `tblsahdev_prompt_presets` tables if missing, seeding defaults.

---

## 5. Database Schema

### `tblsahdev_settings`

Global configuration. Always has exactly 1 row (id=1).

| Column | Type | Default | Description |
|---|---|---|---|
| `id` | int PK | auto | — |
| `primary_provider_id` | int | 1 | FK → tblsahdev_providers.id |
| `fallback_provider_id` | int | null | FK → tblsahdev_providers.id |
| `temperature` | decimal(3,2) | 0.70 | LLM temperature |
| `max_tokens` | int | 2048 | Max output tokens |
| `tone_default` | varchar | Professional | Default tone |
| `system_prompt` | text | (see sahdev_activate) | Global system persona (synced from prompt library) |
| `user_prompt_template` | longtext | (default template) | Main analysis prompt (synced from prompt library) |
| `max_messages` | int | 10 | Max recent messages to send |
| `max_attachment_chars` | int | 5000 | Max extracted characters from attachments |
| `max_images` | int | 3 | Max images analyzed per ticket |
| `auto_analyze_on_load` | boolean | 0 | Auto-snapshot on ticket open |
| `summarizer_enabled` | boolean | 1 | Feature toggle: AI Summarizer |
| `summarizer_threshold` | int | 20 | Min messages to trigger summarizer |
| `compliance_mode` | boolean | 0 | PII scrubbing before sending to AI |
| `pii_scrub_enabled` | boolean | 0 | Additional PII scrub toggle |
| `scrub_cc` | boolean | 1 | Scrub credit card numbers |
| `scrub_emails` | boolean | 1 | Scrub email addresses |
| `scrub_ips` | boolean | 1 | Scrub IPv4 addresses |
| `scrub_passwords` | boolean | 1 | Scrub password patterns |
| `translation_enabled` | boolean | 0 | (Reserved, not yet implemented) |
| `auto_sentiment` | boolean | 0 | (Reserved) |
| `auto_tagging` | boolean | 0 | (Reserved) |
| `quality_scorer_enabled` | boolean | 1 | Auto-score AI replies |
| `custom_attachments_dir` | varchar(255) | null | Override WHMCS attachments path |
| `context_enrichment_enabled` | boolean | 1 | Global toggle for read-only WHMCS account data enrichment |
| `context_enrichment_max_chars` | int | 2500 | Character cap for the entire services & account enrichment block |
| `context_enrichment_client_profile` | boolean | 1 | Include client status, credit balance, currency, last login, client group |
| `context_enrichment_hosting` | boolean | 1 | Include hosting services, server info, suspend reasons, billing cycles |
| `context_enrichment_domains` | boolean | 1 | Include domains, expiry dates, auto-renew status, DNS add-ons |
| `context_enrichment_addons` | boolean | 1 | Include hosting product addons and status |
| `context_enrichment_ssl` | boolean | 1 | Include SSL certificate orders and issue dates |
| `context_enrichment_invoices` | boolean | 1 | Include recent invoice totals, status, and due dates |
| `context_enrichment_invoice_items` | boolean | 1 | Include line-item breakdown of latest unpaid/recent invoice |
| `context_enrichment_transactions` | boolean | 1 | Include transaction history and lifetime customer spend |
| `context_enrichment_orders` | boolean | 1 | Include recent orders, gateways, linked invoices, fraud check flags |
| `context_enrichment_cancellations` | boolean | 1 | Include active cancellation requests and churn reasons |
| `context_enrichment_quotes` | boolean | 1 | Include active sales quotes, stage, total, and validity dates |
| `context_enrichment_ticket_log` | boolean | 1 | Include ticket journey, department transfers, and status change log |
| `context_enrichment_emails` | boolean | 1 | Include recent system email subjects sent to client |
| `context_enrichment_contacts` | boolean | 1 | Include authorized sub-accounts and portal access contacts |
| `context_enrichment_client_notes` | boolean | 0 | Include internal staff notes on client profile |
| `context_enrichment_activity_log` | boolean | 0 | Include recent client portal activity logs |
| `context_enrichment_custom_fields` | boolean | 1 | Include non-sensitive custom fields on client/product |
| `context_enrichment_custom_field_allowlist` | text | null | Optional comma-separated allowlist for custom fields |
| `created_at`, `updated_at` | timestamps | — | — |

---

### `tblsahdev_providers`

One row per AI provider configuration.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `name` | varchar | Display name |
| `provider_type` | varchar | `google` \| `lmstudio` \| `replicate` |
| `api_key` | text (encrypted) | API key, stored encrypted via WHMCS `encrypt()` |
| `api_url` | varchar | Base URL for lmstudio/replicate |
| `model_name` | varchar | Model identifier |
| `cost_input_1m` | decimal(10,4) | Cost per 1M input tokens |
| `cost_output_1m` | decimal(10,4) | Cost per 1M output tokens |
| `is_active` | boolean | 1 = active |
| `created_at`, `updated_at` | timestamps | — |

> **Security:** API keys are stored using WHMCS `encrypt()` and decrypted at runtime with `decrypt()`.

---

### `tblsahdev_admin_preferences`

Per WHMCS admin (`admin_id` PK → `tbladmins.id`). Stores JSON in `preferences_json`: default AI provider id, optional tone override, and per-feature booleans (see `lib/AdminPreferences.php`). Organization-wide toggles in `tblsahdev_settings` are a ceiling — staff cannot enable a feature the org disabled. Enforced in `ajax.php` and reflected on the ticket panel in `hooks.php`. Admin UI: **Addons → Sahdev → My Preferences** (`AdminController::my_preferences`).

| Column | Type | Description |
|---|---|---|
| `admin_id` | int PK | WHMCS admin user id |
| `preferences_json` | longtext | JSON preferences blob |
| `created_at`, `updated_at` | timestamps | — |

---

### `tblsahdev_cache`

Stores AI analysis responses keyed by ticket + input hash.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `ticket_id` | int (indexed) | — |
| `hash_signature` | varchar(64) | SHA-256 of serialized inputs |
| `ai_response` | mediumtext | JSON-encoded AI response |
| `created_at` | timestamp | — |

---

### `tblsahdev_logs`

Request log (lightweight — no full prompt/response, just metadata).

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `ticket_id` | int (indexed) | — |
| `admin_id` | int (indexed) | — |
| `request_payload` | mediumtext | JSON with subject + message_count |
| `response_payload` | mediumtext | JSON AI response |
| `token_usage` | int | Total tokens consumed |
| `execution_time_ms` | int | Response time |
| `provider_used` | varchar(32) | Provider name string |
| `used_fallback` | boolean | Was fallback provider used? |
| `is_cached` | boolean | Was this a cache hit? |
| `created_at` | timestamp | — |

---

### `tblsahdev_audit_trail`

Full prompts + responses for accountability. Admin-deletable by date range.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `ticket_id` | int | — |
| `admin_id` | int | — |
| `action_type` | varchar | `analysis`, `frontend_analysis`, `rewrite`, `summary`, `history`, `score`, `canned` |
| `prompt_sent` | longtext | Full prompt (system + user context JSON) |
| `response_received` | longtext | Full AI response |
| `token_usage` | int | — |
| `execution_time_ms` | int | — |
| `provider_used` | varchar | — |
| `created_at` | timestamp | — |

---

### `tblsahdev_summaries`

AI-generated ticket summaries (for token optimization).

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `ticket_id` | int (unique) | One summary per ticket |
| `admin_id` | int | Admin who generated |
| `summary` | longtext | AI summary text |
| `created_at`, `updated_at` | timestamps | — |

---

### `tblsahdev_historical_context`

AI-generated client history analysis (cached per ticket).

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `ticket_id` | int (unique) | — |
| `admin_id` | int | — |
| `context_text` | longtext | Markdown analysis of past tickets |
| `created_at`, `updated_at` | timestamps | — |

---

### `tblsahdev_quality_scores`

AI-assigned quality scores for replies.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `ticket_id` | int | — |
| `admin_id` | int | — |
| `score` | int | Overall (0–100) |
| `clarity` | int | Clarity score (0–100) |
| `tone_score` | int | Tone score (0–100) |
| `completeness` | int | Completeness (0–100) |
| `notes` | varchar(500) | AI explanation |
| `is_ai_generated` | boolean | AI reply (1) vs admin draft (0) |
| `created_at`, `updated_at` | timestamps | — |

---

### `tblsahdev_canned_responses`

Sahdev-specific canned response templates (separate from WHMCS predefined replies).

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `title` | varchar | Template title |
| `template_text` | longtext | Template body |
| `created_at`, `updated_at` | timestamps | — |

---

### `tblsahdev_rate_limit`

Per-admin request counter.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `admin_id` | int (unique) | — |
| `requests_count` | int | Lifetime request count |
| `last_request_at` | timestamp | — |

---

### `tblsahdev_client_context_cache`

(Reserved for future per-client context caching)

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `client_id` | int | — |
| `context_data` | longtext | JSON cache |
| `created_at`, `updated_at` | timestamps | — |

---

### `tblsahdev_prompt_templates` *(Added v2.1)*

All editable AI prompts. One row per prompt key.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `prompt_key` | varchar(100) UNIQUE | Machine key (see §7) |
| `label` | varchar(200) | Human-readable name |
| `description` | text | What this prompt does |
| `default_content` | longtext | Factory default (never overwritten by edits) |
| `content` | longtext | Currently active prompt text |
| `created_at`, `updated_at` | timestamps | — |

---

### `tblsahdev_prompt_presets` *(Added v2.1)*

Named snapshots of entire prompt configurations.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | — |
| `name` | varchar(200) | Preset label |
| `prompt_snapshots` | longtext | JSON object: `{prompt_key: content, ...}` |
| `created_at`, `updated_at` | timestamps | — |

---

## 6. AJAX API Reference

**Base URL:** `addonmodules.php?module=sahdev&sahdev_act=ajax_handler`
**Method:** POST

All responses return `{"status": "success"|"error", ...}`.

### `get_payload`

Returns config + context required for browser-side LM Studio call OR serves cache hit.

**Request:** `action=get_payload`, `ticket_id`, `tone`, `intent`, `instruction`, `intensity`, `use_summary`, `include_historical_context`, `force_regenerate`

**Success Response:**
```json
{
  "status": "success",
  "cached": false,
  "hash_signature": "...",
  "provider": "lmstudio|google|replicate",
  "api_url": "http://localhost:1234/v1/chat/completions",
  "api_key": "...",
  "model": "local-model",
  "temperature": 0.7,
  "max_tokens": 2048,
  "system_prompt": "...",
  "user_prompt_template": "...",
  "context": { "subject": "...", "messages": [...], ... },
  "tone": "Professional",
  "custom_instruction": "...",
  "intent": "AUTO",
  "has_fallback": true,
  "summary_used": false,
  "summary_available": true,
  "message_count": 5,
  "summarizer_threshold": 20
}
```

### `analyze_ticket`

Server-side analysis for Google/Replicate providers.

**Request:** `action=analyze_ticket`, same params as `get_payload`

**Success Response:**
```json
{
  "status": "success",
  "cached": false,
  "data": { "ROOT_CAUSE": "...", "RESPONSIBILITY": "...", "RISK_LEVEL": "...", "INTERNAL_ACTION_PLAN": "...", "CLIENT_REPLY": "..." },
  "tokens_used": 1200,
  "execution_time_ms": 3400,
  "tokens_details": { "input": 900, "output": 300 }
}
```

### `save_response`

Persists browser-generated LM Studio response.

**Request:** `action=save_response`, `hash_signature`, `ai_response` (JSON or base64), `token_usage`, `exec_time`, `token_details`

### `auto_analyze`

Same as `analyze_ticket` but cache-first, no rate limit increment on hit. Used for auto-load snapshot.

### `get_payload` (for rewrite)

See `get_rewrite_payload`.

### `rewrite_reply`

Server-side draft expansion (Google/Replicate).

**Request:** `action=rewrite_reply`, `draft_text`, `tone`, `instruction`

### `get_rewrite_payload`

Returns payload for browser-side LM Studio rewrite.

### `generate_summary`

**Request:** `action=generate_summary`, `ticket_id`

### `get_summary`

**Request:** `action=get_summary`, `ticket_id`

### `delete_summary`

**Request:** `action=delete_summary`, `ticket_id`

### `generate_historical_context`

**Request:** `action=generate_historical_context`, `ticket_id`, `limit` (default 7)

### `get_historical_context`

**Request:** `action=get_historical_context`, `ticket_id`

### `delete_historical_context`

**Request:** `action=delete_historical_context`, `ticket_id`

### `score_reply`

**Request:** `action=score_reply`, `ticket_id`, `reply_text`, `is_ai_generated=true|false`

### `search_canned_responses`

**Request:** `action=search_canned_responses`, `query` (min 3 chars)

### `generate_canned_template`

**Request:** `action=generate_canned_template`, `draft_text`

### `save_canned_response`

**Request:** `action=save_canned_response`, `title`, `template_text`

### `save_kb_article`

**Request:** `action=save_kb_article`, `title`, `template_text`

### `delete_audit_entries`

**Request:** `action=delete_audit_entries`, `days` (int > 0)

### `get_analytics`

**Request:** `action=get_analytics`

---

## 7. Prompt Template Library

All AI prompts are managed in `tblsahdev_prompt_templates` with the following keys:

| `prompt_key` | Used In | Controls |
|---|---|---|
| `system_default` | All providers via `settings['system_prompt']` | AI identity / persona |
| `user_prompt_template` | `buildPrompt()` in all 3 providers | Main analysis prompt structure. Supports `{{CUSTOM_INSTRUCTION_BLOCK}}`, `{{TONE}}`, `{{CLIENT_NAME}}`, `{{DEPARTMENT}}`, `{{SUBJECT}}`, `{{SERVICES_BLOCK}}`, `{{MESSAGES}}`, `{{ATTACHMENTS_BLOCK}}` |
| `summarizer` | `generateSummary()` | Ticket condensation system prompt |
| `historical_context` | `generateHistoricalContext()` | Client history analysis system prompt |
| `rewrite_reply` | `rewriteReply()`, `getRewritePayload()` | Draft polish prompt. Supports `{{DRAFT}}`, `{{TONE}}`, `{{SUBJECT}}`, `{{CLIENT_NAME}}`, `{{EXTRA_INSTRUCTION}}` |
| `score_reply` | `scoreReply()` | QA scoring system prompt |
| `canned_template` | `generateCannedTemplate()` | Canned response generalization. Supports `{{DRAFT}}` |

### Prompt Loading Priority

1. DB row in `tblsahdev_prompt_templates` (if non-empty `content`) — **wins**
2. Hardcoded fallback in `AIController::loadPromptTemplates()` — used on fresh installs or if table missing

### `system_default` / `user_prompt_template` Sync

When either of these is updated in the Prompt Library, `loadPromptTemplates()` pushes the new value into `$this->settings['system_prompt']` and `$this->settings['user_prompt_template']` so all providers receive the correct value without any additional plumbing.

### Preset System

A **preset** is a named snapshot of all 7 prompt keys saved as JSON to `tblsahdev_prompt_presets`. Loading a preset applies each key's stored content to `tblsahdev_prompt_templates`. Presets allow switching between prompt configurations (e.g., "Conservative Mode" vs "Technical Deep Dive").

---

## 8. Frontend JavaScript Architecture

`hooks.php` outputs all JavaScript inline. There is no external JS file.

### JS Flow for LM Studio Provider

```
User clicks Analyze
  → AJAX: action=get_payload
    ← Response: {provider: "lmstudio", api_url, system_prompt, context, ...}
  → executeLocalLMStudioCall()
    → buildPromptText(context, tone, instruction, template)
    → Context budget guard (truncate if > 4096 tokens)
    → fetch(api_url) with messages = [system, user]
    ← LM Studio JSON response
    → Strip <think>, extract JSON via brace counting
    → robustJsonParse() — 5-stage parser
    → AJAX: action=save_response (base64 encoded)
  → renderSahdevResults()
```

### JS Flow for Google/Replicate Provider

```
User clicks Analyze
  → AJAX: action=get_payload
    ← Response: {provider: "google", cached: false, ...}  OR {cached: true, data: {...}}
  → If cached: renderSahdevResults() immediately
  → executeBackendGoogleCall()
    → AJAX: action=analyze_ticket (full PHP-side call)
    ← Response: {data: {...}, tokens_used, execution_time_ms}
  → renderSahdevResults()
```

---

## 9. Data Flow Diagrams

### Ticket Analysis (Complete Flow)

```
Admin opens ticket → hooks.php injects panel
  [if auto_analyze_on_load=1] → AJAX auto_analyze → cache hit → render snapshot

Admin clicks "Analyze & Generate Reply"
  → AJAX: get_payload(tone, intent, instruction, use_summary, include_history)
    → TicketDataExtractor::getContext()
       ├─ Fetch tbltickets, tblticketreplies, tblclients, tblhosting
       ├─ Extract attachment text
       ├─ Extract/fetch images (base64)
       └─ PII scrub if compliance_mode=1
    → [if use_summary=1 && summary exists] swap messages[] with summary
    → [if include_history=1] append historical context to system_prompt
    → knowledgebase/ .txt files → append to system_prompt
    → SHA-256 hash → check tblsahdev_cache
    ← {hash_signature, provider, context, system_prompt, ...}

  [if provider=lmstudio]
    → Browser: fetch(api_url) directly
    → AJAX: save_response → tblsahdev_cache + tblsahdev_logs + tblsahdev_audit_trail

  [if provider=google/replicate]
    → AJAX: analyze_ticket → AIController::getAnalysis()
       → AI Provider::generateResponse()
       → Insert tblsahdev_cache
       → [if quality_scorer_enabled] Insert tblsahdev_quality_scores
       → Insert tblsahdev_audit_trail
       → Insert tblsahdev_logs
       ← {data: {...}, tokens_used, execution_time_ms}

→ renderSahdevResults(data) → populate DOM panels
```

---

## 10. Settings Reference

Managed at `Admin → Addons → Sahdev → Settings`:

| Setting | Key | Control |
|---|---|---|
| Primary Provider | `primary_provider_id` | Dropdown of configured providers |
| Fallback Provider | `fallback_provider_id` | Dropdown; used if primary fails |
| Temperature | `temperature` | 0.0–1.0; higher = more creative |
| Max Tokens | `max_tokens` | Max output length in tokens |
| Default Tone | `tone_default` | Professional / Technical / Friendly / Strict / Custom |
| Recent Messages Limit | `max_messages` | Number of most recent ticket replies to send |
| Text Attachment Limits | `max_attachment_chars` | Max characters extracted from .txt/.log |
| Max Images Analyzed | `max_images` | Number of recent images to send |
| Auto-analyze on load | `auto_analyze_on_load` | Loads cached analysis when ticket opens |
| AI Summarizer | `summarizer_enabled` | Toggle feature on/off globally |
| Summarizer Threshold | `summarizer_threshold` | Min messages before summary is useful |
| Quality Scorer | `quality_scorer_enabled` | AI scores every reply 0–100 |
| Compliance Mode | `compliance_mode` | Enables PII scrubbing before AI call |
| Custom Attachments Dir | `custom_attachments_dir` | Override auto-detected attachments path |

---

## 11. Adding a New AI Provider

1. **Create class** `lib/MyProvider.php` implementing `Sahdev\Lib\AIProviderInterface`
2. **Implement all 6 interface methods** — especially `generateResponse()` which must return the standard 5-key array
3. **Register in `AIController::initializeProvider()`:**
   ```php
   } elseif ($providerData->provider_type === 'myprovider') {
       return new MyProvider($providerData->api_url, decrypt($providerData->api_key));
   }
   ```
4. **Add to `AdminController::addProvider()` / `editProvider()`** — add option to provider type dropdown in the UI
5. **Add `require_once`** in `ajax.php` if not already auto-loaded
6. **Decide execution path:** Server-side (like Google) or client-side (like LM Studio). If client-side, add a branch in `hooks.php` JS that checks `config.provider === 'myprovider'`

---

## 12. Adding a New Feature

### Pattern (Example: New AI Feature "Auto-Tag")

**Step 1 — DB:** If the feature needs persistent storage, add a table in `sahdev_activate()` with try/catch migration guard. Also add any required `tblsahdev_settings` columns.

**Step 2 — AIController:** Add a `public function generateAutoTag(): array` method. Follow the existing pattern:
- Check rate limit
- Extract context
- Build fake context with custom `system_prompt` and `user_prompt_template = '{{MESSAGES}}'`
- Call `$this->provider->generateResponse()`
- Log to audit trail
- Return `['status' => 'success', ...]`

**Step 3 — ajax.php:** Add an `elseif ($action === 'generate_auto_tag')` branch calling the new method.

**Step 4 — hooks.php:** Add a new panel section with button(s), AJAX call, and result rendering.

**Step 5 — AdminController:** Add a backend CRUD view method for the new table.

**Step 6 — Prompt Library:** Add the new prompt key to `tblsahdev_prompt_templates` default seeding in `AdminController::getDefaultPromptDefinitions()` and to `AIController::loadPromptTemplates()` hardcoded defaults.

**Step 7 — CODEBASE.md:** Update §6 (AJAX API), §5 (DB Schema), §7 (Prompt keys) and §4.9 (AIController methods).

---

## 13. Security Design

| Layer | Mechanism |
|---|---|
| **Admin session** | `$_SESSION['adminid']` checked in both `ajax.php` and `hooks.php` |
| **CSRF** | `generate_token("form")` in all HTML forms; `check_token("WHMCS.admin.default")` on POST |
| **API key storage** | WHMCS `encrypt()` / `decrypt()` functions |
| **DB queries** | 100% Capsule ORM — no raw SQL injection surface |
| **Attachment reading** | Extension whitelist + 1MB size limit; no binary/executable parsing |
| **PII scrubbing** | Optional regex-based redaction before AI call |
| **JSON output** | `ob_clean()` before `echo json_encode()` to prevent partial HTML bleed |
| **No direct file access** | All files check `!defined("WHMCS")` and die |
| **Base64 transport** | LM Studio responses passed back as base64 to prevent JSON corruption by WHMCS sanitizers |

---

## 14. Version History

| Version | Date | Changes |
|---|---|---|
| 1.0 | ~2025-12 | Initial release: Google Gemini integration, basic ticket analysis |
| 1.5 | 2026-01 | LM Studio local AI support, fallback provider, knowledge base, admin signatures |
| 2.0 | 2026-02 | AI Summarizer, Historical Context, Canned Responses, Quality Scorer, Audit Trail, Analytics, Rewrite Reply, Intent System, Replicate provider, Compliance Mode |
| 2.1 | 2026-03-03 | **Prompt Template Library** — all 7 prompt types editable from backend via `tblsahdev_prompt_templates`; Preset save/load/delete system via `tblsahdev_prompt_presets`; JSON import/export; Reset to factory defaults; `AIController::loadPromptTemplates()` added |
| 2.1.1 | 2026-03-06 | Added configuration fields for context limits (`max_messages`, `max_attachment_chars`, `max_images`) and AI cost tracking |

---

*End of CODEBASE.md — Sahdev v2.1.1*
*Last updated: 2026-03-06 | Update this file whenever you add tables, methods, AJAX actions, or prompt keys.*
