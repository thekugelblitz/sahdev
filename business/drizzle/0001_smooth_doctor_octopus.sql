CREATE TABLE `invoices` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`plan_id` text,
	`purpose` text NOT NULL,
	`amount` integer NOT NULL,
	`currency` text NOT NULL,
	`state` text NOT NULL,
	`created_at` text NOT NULL,
	`due_at` text NOT NULL,
	`paid_at` text,
	`gateway` text,
	`payment_id` text,
	`promo_code` text
);
--> statement-breakpoint
CREATE TABLE `ledger` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`currency` text NOT NULL,
	`kind` text NOT NULL,
	`amount` integer NOT NULL,
	`applied` integer DEFAULT 0 NOT NULL,
	`reference` text NOT NULL,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `plan_versions` (
	`id` text PRIMARY KEY NOT NULL,
	`data` text NOT NULL,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `platform_config` (
	`id` text PRIMARY KEY NOT NULL,
	`data` text NOT NULL,
	`version` integer DEFAULT 1 NOT NULL
);
--> statement-breakpoint
CREATE TABLE `reservations` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`currency` text NOT NULL,
	`amount` integer NOT NULL,
	`settled` integer,
	`state` text NOT NULL,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `subscriptions` (
	`workspace_id` text PRIMARY KEY NOT NULL,
	`plan_id` text NOT NULL,
	`state` text NOT NULL,
	`period_end` text NOT NULL,
	`pending_plan_id` text,
	`suspension_reason` text
);
--> statement-breakpoint
CREATE TABLE `wallets` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`currency` text NOT NULL,
	`balance` integer DEFAULT 0 NOT NULL,
	`reserved` integer DEFAULT 0 NOT NULL
);
--> statement-breakpoint
CREATE TABLE `webhook_events` (
	`id` text PRIMARY KEY NOT NULL,
	`gateway` text NOT NULL,
	`body_hash` text NOT NULL,
	`received_at` text NOT NULL
);
