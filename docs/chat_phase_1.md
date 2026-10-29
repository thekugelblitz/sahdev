# Task: Optimize AI Live Chat Tool Calling in the WHMCS Module

Analyze the existing WHMCS module codebase and modify the current AI-powered live chat integration to implement intelligent, cost-efficient tool calling for our ToolsAPI.

ToolsAPI OpenAPI specification: https://toolsapi.2hs.in/openapi.json

## Objective

Users can send arbitrary messages at any time in live chat. The system must determine whether a message requires an external API tool without making a separate AI classification request for every message.

*Inspect the existing implementation first, then modify the actual code. Do not create a separate demo, rewrite the entire module, or replace existing functionality unnecessarily.*

## Required Message Flow

Implement the following flow in the existing WHMCS module.

1. A user sends a message in live chat.
2. The existing backend receives the message and loads the minimum required conversation history.
3. A lightweight local pre-router checks whether the message clearly falls into a category that does not need external data, such as greetings, acknowledgements, general knowledge questions, or explanations of already retrieved results.
4. If no external tool is required, send the message directly to the configured AI provider without exposing ToolsAPI functions.
5. If the message may require external data, send a single AI request with only the relevant tool definitions from the cached ToolsAPI registry. Let the AI decide whether to answer normally or request a tool call.
6. If the AI returns a normal answer, display it directly in live chat.
7. If the AI requests a tool, validate the tool name, arguments, permissions, target, and execution budget on the server.
8. Check the result cache and in-flight request deduplication mechanism.
9. If a valid cached result exists, reuse it. Otherwise, execute the appropriate ToolsAPI endpoint once and cache its result according to the endpoint's caching policy.
10. Return only the relevant, compact API result to the AI and obtain its final natural-language response.
11. Display the final response and record usage metrics.

Preserve conversation context for follow-up questions. For example, after checking the DNS records of a domain, the user might ask, "What about its SSL certificate?" The system should understand the domain from the conversation and select the SSL tool without asking the user to repeat it.

## OpenAPI Registry and Tool Selection

- Inspect the supplied OpenAPI specification and identify the actual operations, parameters, request schemas, authentication requirements, and response structures.
- Generate or normalize tool definitions from the OpenAPI document instead of manually duplicating endpoint definitions.
- Cache the parsed registry locally or in the existing cache infrastructure. Do not fetch and parse the OpenAPI document during every chat request.
- Group tools by relevant categories, such as DNS, WHOIS, SSL, PageSpeed, and network diagnostics, based on the actual specification.
- Expose only the relevant category of tools when possible.
- Never expose the entire API specification or every endpoint to the AI on every message.
- Support the existing AI provider or providers through the module's current abstraction layer. Do not hard-code a single provider or break provider-specific function-calling formats.
- Use native function calling when supported. If a configured provider uses a different tool-calling mechanism, adapt it through the existing provider integration.
- Do not execute an external API request merely because a keyword appears in the user's message.
- Do not add a separate AI classifier request for every incoming message.

## Cost and API Traffic Controls

Implement these controls using the module's existing architecture wherever possible.

*1. AI request optimization*
- Avoid separate AI classification requests.
- Avoid sending tools for obvious non-tool questions.
- Keep conversation history concise while preserving relevant context.
- Reuse conversation summaries or existing state when supported by the current implementation.
- Compact tool outputs before sending them to the AI.
- Reuse stable prompt prefixes where appropriate for provider-side prompt caching.

*2. API result caching*
- Cache results by normalized tool name, normalized arguments, and relevant user or tenant scope.
- Apply configurable TTLs appropriate to each endpoint and its data freshness requirements.
- Reuse valid results rather than repeating identical requests.
- Handle failed requests separately from successful cached responses.
- Never share user-specific or authorization-sensitive cached results across users.

*3. Duplicate request prevention*
- Prevent concurrent identical requests from generating duplicate upstream API calls.
- Use the existing Redis or cache infrastructure if available. Otherwise, select a suitable WHMCS-compatible alternative.
- Implement atomic locks or equivalent request coalescing.
- Handle lock timeouts and concurrent execution safely.

*4. Execution budgets*
- Start with a configurable maximum of two tool executions per user turn and two tool-selection rounds.
- Disable parallel tool execution initially unless the existing architecture requires it.
- Enforce limits in backend code, not through AI instructions alone.
- Prevent repeated identical tool calls within the same turn.
- Provide graceful responses when a tool or execution budget is exhausted.
- Use endpoint-specific timeouts, rate limits, and retry policies.
- Do not automatically retry non-idempotent operations without a safe idempotency mechanism.

These are configurable initial limits. Adapt them to the module's existing behavior and actual API requirements.

## Security Requirements

- Keep ToolsAPI credentials exclusively on the server.
- Never include API keys, internal credentials, or secrets in prompts, tool schemas, or user-visible responses.
- Allow execution only for registered and enabled tools.
- Validate every argument against the operation's schema before execution.
- Do not allow AI-generated arbitrary URLs, HTTP methods, headers, or unrestricted network targets.
- Enforce authentication, authorization, and per-user or per-IP rate limits as appropriate.
- Apply SSRF protections to user-controlled network targets, including private and loopback IP addresses, link-local ranges, DNS rebinding, and unsafe redirects.
- Require explicit user intent and stricter controls for expensive or potentially sensitive diagnostic operations.
- Preserve existing WHMCS authentication, permission checks, configuration settings, and security conventions.

## Logging and Monitoring

Add or extend logging to measure:

- AI requests per conversation turn.
- Input and output token usage, including cached tokens where available.
- Tool selection attempts and successful executions.
- ToolsAPI requests per turn.
- Cache hit and miss rates.
- Deduplicated requests.
- Endpoint latency, failures, and timeouts.
- Execution-budget exhaustion.
- Estimated AI and ToolsAPI cost where pricing data is configured.

Avoid logging sensitive credentials or unnecessary personal data.

## Implementation Instructions

1. Inspect the complete existing WHMCS module structure, including chat handlers, AI provider integrations, prompts, tool-calling loops, configuration, caching, database tables, and logging.
2. Identify exactly where the module currently decides whether to call tools and where redundant AI or ToolsAPI requests originate.
3. Document the existing request flow briefly before making changes.
4. Implement the routing, registry, cache, deduplication, and execution-budget improvements in the existing codebase.
5. Reuse existing classes, configuration conventions, database abstractions, and coding style where practical.
6. Add or update configuration options only when needed. Provide sensible defaults and preserve backward compatibility.
7. Add tests for direct-answer routing, tool selection, follow-up questions, cache hits, concurrent duplicate requests, invalid tool arguments, provider errors, and execution-budget limits.
8. Verify that normal chat messages still work, tool calls execute correctly, and provider-specific response formats are handled properly.
9. Run the available tests and relevant syntax checks. Fix regressions introduced by the changes.
10. Provide a concise summary of modified files, implementation decisions, configuration changes, test results, and any remaining limitations.

## Acceptance Criteria

The implementation is complete when:

- General questions can be answered without executing ToolsAPI.
- A separate AI classification request is not made for every message.
- Relevant tool definitions are provided to the AI only when appropriate.
- The AI can choose between a normal response and a tool call.
- Follow-up messages retain enough context to select the correct tool.
- Identical requests reuse valid cached results.
- Concurrent duplicate requests do not unnecessarily hit ToolsAPI multiple times.
- Tool execution limits are enforced server-side.
- The integration works with the module's existing supported AI providers.
- Existing WHMCS functionality remains intact.
- Tests and syntax checks pass, or any failures are clearly documented.

*Important:* Make the changes directly in the existing module. Do not merely provide recommendations, pseudocode, or a proposed architecture. Inspect the current implementation, identify the correct integration points, and complete the modification with minimal disruption to existing functionality.