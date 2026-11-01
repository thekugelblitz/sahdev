# Sahdev admin appearance and settings

The addon workspace, both ticket-panel layouts, and injected admin surfaces use
`ui/admin-theme.css` and `ui/admin-theme.js`, loaded through `lib/AdminTheme.php`.
All rules are scoped to `.sahdev-ui`; the surrounding WHMCS theme and customer
widget design settings remain independent.

Black uses a `#000000` canvas and `#222222` card surfaces. Dark uses graphite
surfaces; White uses neutral light surfaces. The palette covers navigation,
cards, controls, tables, dialogs, code blocks, and dynamically inserted content.
Legacy addon CSS now references the same tokens.

Settings has eight sections grouped into Workspace, AI & Tickets, Safety &
Context, and Support & Operations. Search indexes explanatory copy, excluding
field values. Existing field names, POST route, and section IDs are retained;
`#tab-ticket` remains an alias. Organization tone now lives in Ticket assistance.
All categories belong to one form, so hidden controls still submit normally.
Without JavaScript, all sections remain visible and their anchor links work.

Appearance selections in Settings preview locally and persist with Save. The
page shows unsaved changes, supports discard, and reveals invalid fields before
native validation focuses them. Ctrl/Cmd+S uses the same validated submit path
as the Save button. Appearance buttons on other pages save only the current
admin's preference, include the WHMCS CSRF token, check the response, and restore
the previous palette on failure. Workspace defaults and personal appearance /
layout updates save together in a database transaction.

New administrators inherit `tblsahdev_settings.ui_theme_mode`. Existing explicit
personal choices are preserved. My Preferences offers “Use workspace default”.
The existing appearance column is now covered by the idempotent SchemaManager
migration; no activation reset or data rewrite is needed. Browser localStorage
is no longer authoritative for the theme, avoiding stale or shared-account
overrides. Global appearance changes require settings-management permission.

## Validation

- `php scratch/test_settings_theme.php` renders the actual controller against
  an in-memory database boundary and exercises preference inheritance, POST
  persistence, hidden checkboxes, invalid sections, and transaction rollback.
- `node scratch/test_settings_ui.cjs` exercises the rendered controller in Chrome
  using Playwright. Install Playwright outside the deployment directory and set
  `SAHDEV_PLAYWRIGHT` to its package path if it is not on Node's module path.
  Set `SAHDEV_SCREENSHOTS` to an output directory for Black, Dark, White, and
  mobile screenshots. It uses only a localhost fixture and synthetic data.
- Lint changed PHP files and run `node --check` on the UI scripts.

Deploy the changed PHP files together with the new UI assets. Local fixtures do
not replace a staging check inside the deployed WHMCS theme, with a real database,
restricted staff role, and active chat/ticket data. This change does not deploy
or alter production settings by itself.
