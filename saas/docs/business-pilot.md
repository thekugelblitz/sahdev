# Dokploy pilot acceptance

The existing Sahdev SaaS Compose stack owns the public origin, Laravel sessions, PostgreSQL ledger and AI workers. Its internal business container serves the Vue workspace, visitor widget and portal. Support conversations and files use a separate persistent volume. Back up that volume as well as PostgreSQL. Run one business replica until support state moves to a shared transactional store.

| Flow | Inputs and authorization | Outcome or remaining gate |
| --- | --- | --- |
| Staff sign-in | Verified SaaS account, active organization and session cookie | The server resolves the organization and user on each staff request. New users receive their own empty support workspace. Workspace roles must be granted separately. |
| Guest support | Workspace ID, exact website origin, widget settings, visitor token | Chat, human replies, public help, and ticket handoff. Allow the website origin and use the widget snippet for the Dokploy workspace. Prior Sites tenants are separate. |
| Brand AI test | Active provider driver, HTTPS endpoint, encrypted API key, remote model ID, token-limit parameter, input/output limits, immutable usage rates, plan-model grant | In Command Station configure the provider and model. Provision the brand database, grant credits, set a monthly budget, and assign an operator. Under Brands use Test AI. |
| Request | Model ID, message, maximum output, unique request key, organization and brand membership | Reserve credits before dispatch. Poll the request ID for the answer, actual charge or reconciliation status. Do not retry unknown outcomes as a new request. |
| Billing | Pooled or allocated wallet, invoice, verified payment event, funded usage | The hosted SaaS ledger powers model tests. The support-store billing UI and hosted funding are separate. |
| Launch | SMTP, verified domain, operator credentials, backups and readiness review | Public signup and purchases remain subject to the existing readiness gates. |
 
## Blind spots requiring acceptance

- An actual provider credential and funded end-to-end call are still required to certify a chosen provider and model. Verify reply, returned usage, ledger settlement and failure handling with one short request.
- The AI model-test console and WHMCS inference API use the provider queue. The standalone visitor widget currently routes to humans. Automatic customer replies, production BYOK routing and unified billing need integration work.
- Branding, workspace creation and permissions in standalone support are independent of a Cloud brand. A suspended Cloud organization blocks authenticated support access; coordinate anonymous widget suspension separately.
- A business-store migration from ChatGPT Sites has not been performed. Email replies, automated source crawling and customer database execution are outside this pilot.
- Check a real mobile keyboard, cross-origin chat from an allowlisted site, private-note filtering, login/logout, database restore and queue recovery on the deployed origin.
