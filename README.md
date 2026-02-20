# Sahdev - AI Ticket Intelligence Assistant

Sahdev is a powerful, production-ready WHMCS Addon Module that integrates Google Gemini (or equivalent LLMs) to analyze your WHMCS support tickets, extract client context securely, and propose professional replies.

## Version
Current Version: 2.0

## Features
- **Smart Analysis:** Provides Root Cause, Responsibility, Risk Level, and an Internal Action Plan.
- **AI Suggested Replies:** Click a button to generate a fully contextual reply based on previous ticket messages and attachments.
- **Secure Context Extraction:** Strictly read-only queries against WHMCS core tables via Capsule ORM. Safe fallback parsing for `txt`, `log`, `csv` attachments.
- **Performance Optimized:** Hashes AI responses so reloading the ticket doesn't waste tokens. Async AJAX ensures zero impact on admin page load speeds.
- **Data Privacy:** Parses minimal context (client first name, active packages) without loading full user payloads. Limits attachment text scanning to 1MB max.

## Requirements
- WHMCS 8.x or later
- PHP 7.4 or later
- Valid API Key for Google Gemini (Generative Language API)

## Installation Instructions

1. **Upload the files**
   Upload the entire `sahdev` folder to your WHMCS directory under `/modules/addons/`.
   The path should look like this: `yourwhmcs.com/modules/addons/sahdev/sahdev.php`

2. **Activate the Module**
   - Log into your WHMCS Admin Area.
   - Go to **System Settings > Addon Modules** (or `Setup > Addon Modules` in older WHMCS).
   - Find "Sahdev AI Intelligence" and click **Activate**.
   - Click **Configure** to manage user roles and check the box for "Full Administrator" (or your preferred admin group).
   - Click **Save Changes**.

3. **Configure API Settings**
   - In the WHMCS Admin navbar, go to **Addons > Sahdev**.
   - Type or paste your Google Gemini API Key.
   - Adjust options like AI Model (e.g. `models/gemini-1.5-pro` or `flash`), Max Tokens, Default Tone, and the custom System Prompt.
   - Click **Save Settings**.

## Usage
- Open any support ticket in the WHMCS Admin area.
- Scroll down slightly and you will see the **Sahdev AI Ticket Intelligence** panel just below the ticket body (or above the replies).
- Select your desired tone, provide any optional custom instructions like "Ask for their root password", and click **Analyze & Generate Reply**.
- Click **Insert to Editor** to throw the finalized reply right into TinyMCE.

## Security Notes
Do NOT alter the core `ajax.php` to bypass the session check (`$_SESSION['adminid']`). The endpoint is strictly locked down to administrators only to prevent token hijacking.
