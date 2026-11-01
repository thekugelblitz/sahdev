# Sahdev 5.0: shared diagnostic evidence

This release builds on the settings overhaul and dark, black and white palettes already on `feature/live-chat-tool-calling`. It moves automated ticket and live-chat checks into a shared, bounded workflow. It uses the existing ToolsAPI REST endpoints; no ToolsAPI deployment, new AI subscription or MCP server is required.

## Request flow

1. Parse domains, nested subdomains, URLs, email domains and IPv4/IPv6 locally. Email diagnostics use the domain, not the mailbox local part.
2. Preserve the current target and issue per authenticated customer and conversation. Ask for missing or ambiguous targets. Live-chat state expires after 30 minutes; ticket state after seven days.
3. Match only that customer's active/suspended hosting and registered domains. Include allowlisted service/server facts. Subdomain and shared-IP matches are candidates, not ownership proof. Configuration, management IPs and timestamped telemetry snapshots do not establish live health.
4. Select a small local recipe. An optional separately configured planner may propose a JSON plan for complex requests. Its tools and target are validated by the executor; bad output falls back to the local recipe. The full OpenAPI document is never sent to the planner.
5. Execute a bounded number of checks within the diagnostic deadline, validate results against the bundled response contracts, and produce compact evidence with freshness information. The main reply model receives this evidence once.

Ordinary recipes incur no preliminary model call. Planner and final-reply usage is logged when the provider exposes it. No percentage cost reduction is claimed without production measurements. Public diagnostic results share a cache; private WHMCS enrichment and conversation state do not. Endpoint TTLs, reported DNS TTLs and known upstream cache age constrain local caching. A fresh/recheck request bypasses the application cache, but cannot force ToolsAPI to bypass its own cache.

HTTP/model calls use the remaining request budget. PHP's synchronous system DNS resolver and database calls cannot be interrupted by this budget; configure their host-level timeouts on staging. A timed-out Replicate prediction may continue remotely and incur provider charges; Sahdev does not automatically submit a replacement prediction within a bounded call.

## Controls

Open **Tools Execution → v5.0 Diagnostic workflow**. Existing channel enable switches remain effective, so previously disabled installations remain disabled.

- Enable/disable the shared workflow, tickets, live chat, service enrichment and telemetry independently.
- Select local-only, automatic or always-planned reconnaissance. Choose primary/fallback providers from the existing provider library. No planner provider means local recipes.
- Limit checks (1–5) and diagnostic duration (5–30 seconds). The existing live-chat per-turn tool cap also applies. The final reply has its separate provider timeout.
- Enable issue categories and disable individual registered tools. Category controls govern automatic routing; individual tool blocks apply to matching manual operations as well.
- Emergency stop blocks automatic and manual execution. Turning automation off retains manually requested tools under the existing Tools Execution enable switch.
- Edit `diagnostic_planner`, `diagnostic_reply` and `diagnostic_clarification` in Prompt Manager. Prompts cannot grant executable permissions.

Final ticket replies keep the existing Ticket Reply provider route. Live chat keeps its dedicated primary/fallback route. Manual ticket tools and custom technical context remain available. Matching manual GET operations use the shared executor/cache; other explicitly selected admin operations retain their existing OpenAPI allowlist. Payload previews and Ticket Insights reuse recorded evidence without launching an additional diagnostic pipeline. New ticket reply generation can refresh evidence.

## Reliability and security changes

- Fixed response interpretation for ping, WHOIS, PageSpeed, DNS propagation and traceroute; failed checks cannot be presented as healthy observations.
- Preserved requested DNS record types and URL paths. Extra, invalid and conflicting arguments fail before HTTP execution.
- Added connection-owned MySQL advisory locks for conversation turns and tool calls, bounded upstream retries, diagnostic deadlines and a 1 MiB response cap on the shared executor.
- Added completed-message replay keys in the live-chat widget and scoped evidence reuse, and fixed old-history joins that repeatedly queued unchanged tickets.
- Removed the widget's fabricated billing/server-health fallback and the provider retry that silently dropped tools.
- Separated stored historical evidence from current observations. Error responses and schema mismatches are not stored as successful tool results.

The PHP destination check is a preflight. **ToolsAPI must validate the final resolved destination and every redirect itself**, including IPv4/IPv6 private, loopback and link-local addresses; the remote service owns DNS resolution at connection time. This cannot be guaranteed from Sahdev alone. Direct internal server probing is not added; existing server telemetry is reused for matched services.

## Upgrade and validation

Back up the module and database, then install this branch on a WHMCS staging instance. The normal schema guard adds `diagnostic_policy_json`, `tblsahdev_diagnostic_state`, `tblsahdev_diagnostic_runs` and missing prompt templates idempotently. Existing prompt customizations are retained. This requires the supported WHMCS MySQL/MariaDB connection to permit multiple connection-owned `GET_LOCK` calls. Locks fail closed when unavailable. Test connection pooling/proxy behavior if applicable.

State and replay records are short-lived logical caches. Writes remove up to 20 records expired for more than a day; low-traffic installations may physically retain expired rows longer, so include these tables in the installation's retention/cleanup policy. Existing ticket tool history follows existing module retention.

Validation included PHP syntax checks, published-contract fixtures, SQLite-backed tenant/state/planner tests, the legacy live-chat tool suite, settings save/rollback checks and headless-browser theme/navigation checks. The lifecycle harness emulates advisory-lock responses; it does not validate MySQL lock semantics. No production database migration, paid provider/tool calls or production deployment was performed.

Before production enablement, test: upgrade twice; missing target followed by a domain; email and SSL follow-ups; registered-only domains; unrelated customer and guest requests; provider timeout/malformed JSON; tool timeout/schema drift; parallel requests and lock release; manual tools and emergency stop; ticket cron completion; and the exact configured provider credentials. Compare measured reply latency, planner frequency, cache hits, failures and token usage against the previous release.

Rollback by restoring the prior module files and disabling the new automation. Additive tables/columns may remain. No remote MCP/Ropewalk integration is enabled: that remains a separate capability/authentication validation task.
