CREATE TABLE `widget_installations` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`origin` text NOT NULL,
	`last_seen_at` text NOT NULL,
	`last_message_at` text
);
