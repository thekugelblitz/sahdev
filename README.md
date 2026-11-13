# Sahdev thin connector — acceptance preview

This directory is the **only** source permitted in the new distribution archive. It is not the production replacement for the standard Sahdev module yet. The full migration register is in `saas/config/migration.php`.

The `sahdev_cloud` addon is intentionally separate from `sahdev`: installing a preview must not overwrite the current module or its rollback journals. Extract the archive into the WHMCS root; it contains `modules/addons/sahdev_cloud/`. Activate the addon, explicitly assign its allowed admin roles, and enroll a staging brand using its addon page. Enrollment additionally requires WHMCS's Configure Addon Modules permission. Ticket requests require an active admin, addon-role access, CSRF and an explicitly assigned support department. Empty department assignments fail closed.

PHP 7.4+ with cURL, JSON and mbstring is required, subject to the PHP versions supported by the installed WHMCS version. Run on a staging installation first. The admin also needs the native View Support Ticket permission. Enable `THIN_CONNECTOR_PREVIEW=true` on the cloud acceptance environment. The brand needs a verified domain, provisioned database, funds, a budget and an entitled model.

## Installation and acceptance

1. Download the `moduleV1` branch archive. Copy `modules/addons/sahdev_cloud` into WHMCS. With the standalone connector ZIP, extract directly into the WHMCS root.
2. Activate **Sahdev Cloud Connector (acceptance preview)** under System Settings → Addon Modules. Select permitted roles and assign the testing admin to the ticket's department.
3. Create and verify a staging brand in SaaS. Have its database provisioned and test credits granted. Issue an enrollment token; enter it with the installation ID and `https://sahdev.hostingspell.com` on the addon page within 15 minutes.
4. Open a sanitized staging ticket, load models, choose one and run each workflow. Rewrite, scoring and canned drafts require a draft. Check formatted output, copy draft and related ticket/client links.
5. Verify disallowed roles/departments cannot load context. Remove access while a request is pending and verify polling is denied. A retry after a timeout must retain the request reference and produce one charge.
6. Report WHMCS/PHP versions, workflow and request reference for failures. Never include keys, enrollment tokens or customer ticket bodies in bug reports.

Implemented preview: asynchronous ticket analysis, summary, rewrite, scoring and canned draft generation. Drafts are copied for human review, never automatically sent. Context includes bounded ticket messages and public staff replies; attachments, private notes and other account tables are not yet migrated. The cloud owns prompts, validation and Markdown rendering. The connector holds only connection credentials, a local request journal, fixed record extraction, WHMCS permission checks and UI transport. Deactivation removes local connection credentials but deliberately retains request references; revoke the installation in the SaaS dashboard too.

Do not call this full feature parity: chat, Copilot tools, approval/rollback migration, knowledge, diagnostics, automation, analytics, mobile, and existing-data migration remain release blockers. Browser UI code and the transport protocol are inspectable; source exclusion is not DRM and cannot revoke copies of earlier full-module distributions.
