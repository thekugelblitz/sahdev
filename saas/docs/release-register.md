# Release readiness register

`/command/release` is a read-only operator report. It combines the versioned
`config/release-register.json` inventory with the current launch configuration
checks. `/command/release/export` downloads the complete JSON snapshot; filters
never reduce the scope of the release decision or export. Both routes use the
same verified-operator and optional MFA policy as Command Station.

The register covers legacy functionality as well as the cloud control plane.
References to legacy source and tests locate existing behavior; they do not mean
that behavior has moved to SaaS. `implemented` means code exists, not that the
feature has passed a real WHMCS acceptance run. Do not mark inherited legacy
features verified simply because a standalone legacy test passes.

## Updating acceptance

Keep capability IDs stable because journeys and direct links reference them.
Update the current behavior, acceptance criteria and source/test references when
implementation changes. Add journeys for new cross-feature behavior, including
denied-access, failure and recovery checks. Refresh `reviewed_on` after an inventory
review, not merely a page deployment.

After acceptance, record `evidence` objects with five nonempty string fields:
`reference`, `reviewer`, `reviewed_on`, `environment`, and `version`. The reference
should identify a durable acceptance record. The version identifies the tested
commit or artifact. Keep personal information, transcripts, secrets and payment
payloads in an appropriately protected evidence system, outside this repository.

A domain, every capability within it, and every required journey must each have
`status: verified` and complete evidence. `MigrationRegister::complete()` is shared
by the runtime launch gate and the standalone connector packager. Changing only a
parent status, setting an environment flag, or filtering the report cannot bypass
incomplete acceptance. Stable downloads additionally require signature, artifact
integrity and allowlist validation. Preview packaging remains separate.

## Verification

Run the PHP regression suite and `tests/browser.cjs` through the SaaS CI workflow.
The report tests cover access/MFA, filters, escaped search values, export redaction,
inventory references, journey relationships and the acceptance gate. Browser tests
cover filters, disclosure controls, deep links, export and responsive layout.
These tests validate the readiness feature; they do not accept the listed WHMCS,
mobile, payment or disaster-recovery journeys on behalf of their reviewers.
