# Sahdev Cloud Connector 0.3 — acceptance preview

This directory is the **only** source permitted in the new distribution archive. It is not the production replacement for the standard Sahdev module yet. The full migration register is in `saas/config/migration.php`.

The `sahdev_cloud` addon installs beside `sahdev` and uses separate settings and a separate journal. Extract the archive into the WHMCS root; it contains `modules/addons/sahdev_cloud/`. Activate the addon, explicitly assign its allowed admin roles, and enroll a verified brand using its addon page. Enrollment additionally requires WHMCS's Configure Addon Modules permission. Ticket requests require an active admin, addon-role access, CSRF and an explicitly assigned support department. Empty department assignments fail closed.

PHP 7.4+ with cURL, JSON and mbstring is required, subject to the PHP versions supported by the installed WHMCS version. Run on a staging installation first. The admin also needs the native View Support Ticket permission. Enable `THIN_CONNECTOR_PREVIEW=true` on the cloud acceptance environment. The brand needs a verified domain, provisioned database, funds, a budget and an entitled model.

## Installation and acceptance

1. Download the `moduleV1` branch archive. Copy `modules/addons/sahdev_cloud` into WHMCS. With the standalone connector ZIP, extract directly into the WHMCS root.
2. Activate **Sahdev Cloud Connector (acceptance preview)** under System Settings → Addon Modules. Select permitted roles and assign the testing admin to the ticket's department.
3. Create and verify a brand in SaaS using the installation's exact WHMCS System URL. Issue an enrollment token; enter it with the installation ID and `https://sahdev.hostingspell.com` on the addon page within 15 minutes. Click **Check connection**. For ticket AI, provision the brand database, fund the wallet and assign an active priced model. Record lookup can run without model access or credits.
4. Open a sanitized staging ticket, load models, choose one and run each workflow. Rewrite, scoring and canned drafts require a draft. Check formatted output, copy draft and related ticket/client links.
5. Verify disallowed roles/departments cannot load context. Remove access while a request is pending and verify polling is denied. A retry after a timeout must retain the request reference and produce one charge.
6. Report WHMCS/PHP versions, workflow and request reference for failures. Never include keys, enrollment tokens or customer ticket bodies in bug reports.

## Try Copilot records

1. In Addon Modules, configure the connector and enable **Copilot record lookup** for the roles participating in the test. For client search, the admin needs both **List Clients** and **View Clients Summary**.
2. Open **Sahdev Cloud Copilot** in the bottom corner of an admin page. Search a test client's ID, full name or exact company name. Select a match explicitly when multiple clients share a name.
3. Choose **Invoices** or **Services** and click **Show records**. Invoice access requires **View Invoice**. Services require **View Clients Products/Services**. Product configuration links require **Configure Products/Services**. Click the client, an invoice and a service link, and check navigation in your actual admin path. Verify that a staff role without invoice permission cannot access that tab.
4. Try previous/next page and an account with no records. The widget performs bounded reads. It sends the client ID, display name and selected fields for the current page to Sahdev Cloud for formatting. The cloud audits actor, brand, resource and row count without retaining the card or sending it to an AI provider.

For a limited test on production WHMCS, use a test client and sanitized support ticket on that installation. Start with one approved admin role. Upgrading from version 0.2 is a file replacement within `modules/addons/sahdev_cloud`; the previously enrolled connection and journal are reused. Use a single consistent version of the folder. Disabling **Copilot record lookup** turns off that workflow without disabling ticket drafting.

Implemented preview: asynchronous ticket analysis, summary, rewrite, scoring and canned draft generation. Drafts are copied for human review, never automatically sent. Context includes bounded ticket messages and public staff replies; attachments, private notes and other account tables are not yet migrated. The cloud owns prompts, validation and Markdown rendering. The connector holds only connection credentials, a local request journal, fixed record extraction, WHMCS permission checks and UI transport. Deactivation removes local connection credentials but deliberately retains request references; revoke the installation in the SaaS dashboard too.

Public launch still requires chat, conversational Copilot, approval and atomic rollback, knowledge, diagnostics, automation, analytics, mobile and existing-data migration.
