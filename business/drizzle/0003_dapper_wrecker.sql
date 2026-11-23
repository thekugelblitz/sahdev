CREATE TABLE `billing_runtime` (
	`id` text PRIMARY KEY NOT NULL,
	`namespace` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `checkout_attempts` (
	`invoice_id` text PRIMARY KEY NOT NULL,
	`gateway` text NOT NULL,
	`nonce` text NOT NULL,
	`state` text NOT NULL,
	`data` text,
	`provider_id` text,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `payment_receipts` (
	`id` text PRIMARY KEY NOT NULL,
	`invoice_id` text NOT NULL,
	`workspace_id` text NOT NULL,
	`gateway` text NOT NULL,
	`payment_id` text NOT NULL,
	`namespace` text NOT NULL,
	`amount` integer NOT NULL,
	`currency` text NOT NULL,
	`credit` integer NOT NULL,
	`state` text NOT NULL,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `payment_recovery` (
	`id` text PRIMARY KEY NOT NULL,
	`payment_ref` text NOT NULL,
	`reason` text NOT NULL,
	`created_at` text NOT NULL
);
--> statement-breakpoint
ALTER TABLE `invoices` ADD `transition` integer;--> statement-breakpoint
ALTER TABLE `subscriptions` ADD `admin_hold` text;--> statement-breakpoint
ALTER TABLE `subscriptions` ADD `recovery_hold` text;--> statement-breakpoint
ALTER TABLE `subscriptions` ADD `billing_revision` integer DEFAULT 0 NOT NULL;
--> statement-breakpoint
UPDATE subscriptions SET admin_hold=COALESCE(suspension_reason,'Legacy administrative review'),state='active',suspension_reason=NULL WHERE state='suspended' AND COALESCE(suspension_reason,'')!='Payment required';
