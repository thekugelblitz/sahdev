CREATE TABLE `memberships` (
	`id` text PRIMARY KEY NOT NULL,
	`subject` text NOT NULL,
	`workspace_id` text NOT NULL,
	`role` text NOT NULL,
	`contact_id` text
);
--> statement-breakpoint
CREATE TABLE `workspaces` (
	`id` text PRIMARY KEY NOT NULL,
	`data` text NOT NULL,
	`version` integer DEFAULT 1 NOT NULL
);
