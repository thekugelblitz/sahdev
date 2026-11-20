<?php
namespace Sahdev\Lib;

use WHMCS\Database\Capsule;
use WHMCS\User\Admin;

/** Editing a draft must never write to a public ticket reply. */
final class AutopilotDrafts
{
    const PREFIX = "[Sahdev Autopilot Draft] Review before sending - Draft Mode is ON\n\n---\n\n";

    public static function body(string $message): ?string
    {
        $message = str_replace("\r\n", "\n", $message);
        if (strpos($message, self::PREFIX) !== 0) {
            return null;
        }
        return substr($message, strlen(self::PREFIX));
    }

    private static function authorize(int $ticketId, int $adminId): void
    {
        $admin = Admin::find($adminId);
        if (!$admin || $admin->isDisabled || !$admin->hasPermission('View Support Ticket')
            || !PermissionService::hasPermission($adminId, PermissionService::PERM_TICKET_AI)) {
            throw new \RuntimeException('You do not have permission to access this draft.', 403);
        }
        $ticket = Capsule::table('tbltickets')->where('id', $ticketId)->first(['id', 'did', 'flag']);
        if (!$ticket) {
            throw new \RuntimeException('Ticket not found.', 404);
        }
        $departments = array_map('intval', $admin->getSupportDepartmentIds());
        if (!in_array((int) $ticket->did, $departments, true)
            && !((int) $ticket->flag === $adminId && $admin->hasPermission('View Flagged Tickets'))) {
            throw new \RuntimeException('You do not have access to this ticket department.', 403);
        }
    }

    private static function noteQuery(int $ticketId, int $noteId)
    {
        // Use the same note table as WHMCS. Never fall back to tblticketreplies.
        return Capsule::table('tblticketnotes')->where('ticketid', $ticketId)->where('id', $noteId);
    }

    private static function payload(int $noteId, string $body, string $message): array
    {
        // WHMCS bundles this Markdown parser. The browser also allowlists the
        // generated HTML before using it in a rich editor or note preview.
        $parser = new \Michelf\MarkdownExtra();
        $parser->no_markup = true;
        return ['note_id' => $noteId, 'body' => $body, 'html' => $parser->transform($body),
            'revision' => hash('sha256', $message)];
    }

    public static function read(int $ticketId, int $noteId, int $adminId): array
    {
        self::authorize($ticketId, $adminId);
        $note = self::noteQuery($ticketId, $noteId)->first(['id', 'message']);
        $body = $note ? self::body((string) $note->message) : null;
        if ($body === null) {
            throw new \RuntimeException('This Sahdev Autopilot draft no longer exists.', 404);
        }
        return self::payload($noteId, $body, $note->message);
    }

    public static function save(int $ticketId, int $noteId, int $adminId, string $body, string $revision): array
    {
        self::authorize($ticketId, $adminId);
        if (trim($body) === '') {
            throw new \InvalidArgumentException('The draft cannot be empty.', 422);
        }
        $message = self::PREFIX . $body;
        // WHMCS stores notes in a TEXT column (the limit is bytes, not characters).
        if (strlen($message) > 65535) {
            throw new \InvalidArgumentException('The draft is too long to save as a ticket note.', 422);
        }
        $result = self::payload($noteId, $body, $message);
        Capsule::connection()->transaction(function () use ($ticketId, $noteId, $message, $revision) {
            $note = self::noteQuery($ticketId, $noteId)->lockForUpdate()->first(['id', 'message']);
            if (!$note || self::body((string) $note->message) === null) {
                throw new \RuntimeException('This Sahdev Autopilot draft no longer exists.', 404);
            }
            if (!hash_equals(hash('sha256', $note->message), $revision)) {
                throw new \RuntimeException('Another administrator changed this draft. Copy your edits, cancel, and reopen Edit to load the latest version.', 409);
            }
            self::noteQuery($ticketId, $noteId)->update(['message' => $message, 'editor' => 'markdown']);
        });
        if (function_exists('logActivity')) {
            logActivity('Sahdev Autopilot draft note #' . $noteId . ' edited on ticket #' . $ticketId . ' by admin #' . $adminId);
        }
        return $result;
    }
}
