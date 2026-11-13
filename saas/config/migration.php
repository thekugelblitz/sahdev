<?php
// A domain is complete only after its old entry points, permissions, data migration,
// failure paths and UI flows have been exercised through the thin connector.
return [
    'distribution' => 'thin-connector',
    'features' => [
        'accounts' => ['label'=>'Accounts, brands, budgets and staff access','status'=>'implemented','source'=>['saas/app/Services/OrganizationService.php','saas/app/Services/WalletService.php']],
        'ai_gateway' => ['label'=>'Provider management and metered inference','status'=>'implemented','source'=>['saas/app/Services/InferenceService.php','saas/app/Services/ProviderGateway.php']],
        'billing' => ['label'=>'Invoices, payment delivery and reconciliation','status'=>'awaiting_live_validation','source'=>['saas/app/Services/BillingService.php']],
        'ticket_ai' => ['label'=>'Ticket analysis, summaries, rewrite and quality scoring','status'=>'partial','source'=>['lib/AIController.php','lib/TicketDataExtractor.php','hooks.php']],
        'case_timeline' => ['label'=>'Case timeline, historical context and diagnostic evidence','status'=>'pending','source'=>['lib/CaseTimeline.php','lib/DiagnosticWorkflow.php']],
        'live_chat' => ['label'=>'Customer chat, visitor identity, takeover and escalation','status'=>'pending','source'=>['lib/ChatService.php','lib/ClientChatScopeService.php']],
        'copilot' => ['label'=>'Admin Copilot, related records and action cards','status'=>'pending','source'=>['lib/ChatService.php','lib/CopilotRecords.php','lib/CopilotPresentation.php']],
        'safe_operations' => ['label'=>'CRUD policy, atomic approval, rollback and journals','status'=>'pending','source'=>['lib/CopilotDatabasePolicy.php','lib/CopilotOperationService.php']],
        'knowledge' => ['label'=>'Knowledge, canned replies and website data sources','status'=>'pending','source'=>['lib/RagKnowledgeService.php','lib/WebsiteDataSourcesService.php']],
        'automation' => ['label'=>'Autopilot, cron and notifications','status'=>'pending','source'=>['lib/AutopilotProcessor.php','lib/CronProcessor.php']],
        'diagnostics' => ['label'=>'Tools, server telemetry and incidents','status'=>'pending','source'=>['modules/ToolsExecution/ToolsExecutionService.php','lib/ServerTelemetryService.php','lib/IncidentDetectionService.php']],
        'analytics' => ['label'=>'Metrics, reports and audit views','status'=>'pending','source'=>['lib/MetricsIntelligenceService.php','controllers/AdminController.php']],
        'mobile' => ['label'=>'Mobile pairing, WHMCS APIs, chat and push notifications','status'=>'pending','source'=>['lib/MobileApiService.php','lib/FirebasePushService.php','mobile/']],
        'upgrade' => ['label'=>'Existing installation migration and rollback compatibility','status'=>'pending','source'=>['lib/SchemaManager.php','sahdev.php']],
    ],
];
