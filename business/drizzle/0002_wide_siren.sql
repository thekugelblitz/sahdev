CREATE TABLE `credential_vault` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`data` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `visitor_sessions` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`contact_id` text NOT NULL,
	`conversation_id` text NOT NULL,
	`origin` text NOT NULL,
	`created_at` text NOT NULL,
	`expires_at` text NOT NULL
);
