---
name: whmcs-module-code-rules
description: Generate and refactor WHMCS addon/module code using WHMCS conventions, safe database patterns, upgrade-safe architecture, and backward-compatible behavior. Use when building, rewriting, or maintaining WHMCS addons, provisioning modules, hooks, admin/client pages, or module APIs.
---

# WHMCS Module Code Rules

## Purpose

Produce production-ready WHMCS addon/module code that is upgrade-safe, maintainable, and consistent with WHMCS conventions.

## When to Apply

Apply this skill when:
- Implementing or rewriting WHMCS addons/modules
- Creating or editing WHMCS hooks
- Building admin/client area output for WHMCS modules
- Adding provisioning lifecycle functions
- Updating module configuration, activation, upgrade, or uninstall flows
- User explicitly requests `/whmcs-module-code-rules`

## Core Principles

- Follow WHMCS-native patterns before introducing custom architecture
- Preserve backward compatibility unless user explicitly approves breakage
- Keep business logic separated from WHMCS entrypoint glue code
- Make smallest safe change that solves the task
- Avoid framework-heavy abstractions that fight WHMCS lifecycle

## Required Workflow

1. Identify module type and execution path (addon, provisioning, hook, admin/client page)
2. Inspect existing WHMCS-facing contracts and expected return shapes
3. Implement with strict compatibility to WHMCS expectations
4. Add or adjust migrations or upgrade logic if schema changes
5. Validate syntax and affected flows
6. Report changed files, assumptions, and test plan

## WHMCS-Specific Coding Rules

### Module Contracts

- Respect exact WHMCS function names and signatures required for module type
- Return arrays/values in the format WHMCS expects
- Do not change contract keys without migration or compatibility layer

### Configuration

- Keep module config declarations explicit and stable
- Use clear defaults and document impact of each option
- Avoid hidden behavior controlled by undocumented config

### Database Access

- Prefer WHMCS-supported DB patterns (`Capsule`) and parameterized queries
- Never build SQL via unsafe concatenation with user input
- Keep schema updates idempotent and reversible where possible

### Hooks and Side Effects

- Keep hook handlers focused and deterministic
- Guard against duplicate side effects on repeated hook execution
- Log meaningful context for operational troubleshooting

### Logging and Observability

- Use WHMCS-compatible logging utilities for module actions/errors
- Include actionable context (module, operation, identifier)
- Never log secrets, tokens, or raw credentials

### Security

- Validate and sanitize input at boundaries (request params, admin settings, callbacks)
- Enforce auth/permission checks for admin actions
- Protect callback/webhook endpoints against tampering/replay where applicable

### Error Handling

- Fail gracefully with useful operator-facing messages
- Distinguish transient failures from permanent validation failures
- Preserve existing behavior where downstream automation depends on it

### External API Integrations

- Isolate API client logic from WHMCS entrypoint methods
- Use timeouts, retry policy (where safe), and clear error mapping
- Keep request/response normalization centralized

## Rewrite Rules

When rewriting legacy code:
- Maintain old behavior by default, then improve incrementally
- Introduce compatibility shims where contract changes are required
- Split monolithic functions into smaller internal services without changing public WHMCS entrypoints
- Migrate risky changes behind explicit config flags when needed

## File and Architecture Guidance

- Keep WHMCS entrypoint files thin (routing/contract handling only)
- Put domain logic in internal classes/services
- Put data access in dedicated repository/helper classes
- Centralize shared constants and status mappings

## Dependency Policy

- Do not add new dependencies unless necessary
- If needed, justify why native WHMCS/PHP facilities are insufficient
- Prefer lightweight, stable packages compatible with deployed PHP/WHMCS environments

## Output Expectations

For each coding task, provide:
- Short implementation rationale
- Files changed
- Compatibility notes (especially contract/schema behavior)
- Validation checklist (syntax, key flows, edge cases)
- Follow-up risks or migration steps

## Escalation Conditions

Ask before proceeding when:
- Changes may break existing automation or integrations
- Public module contracts/response shapes must change
- Data migration may alter existing production records
- Security constraints conflict with requested behavior
