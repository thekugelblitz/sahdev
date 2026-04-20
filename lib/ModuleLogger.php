<?php

namespace Sahdev\Lib;

use WHMCS\Database\Capsule;

/**
 * Best-effort diagnostic log for Sahdev (enrichment failures, etc.). Never throws.
 */
class ModuleLogger
{
    private const MAX_MESSAGE_LEN = 2000;

    public static function log(string $level, string $source, string $message, ?int $ticketId = null): void
    {
        $level = strtolower(substr($level, 0, 16));
        $source = substr($source, 0, 128);
        $message = substr($message, 0, self::MAX_MESSAGE_LEN);

        try {
            if (!Capsule::schema()->hasTable('tblsahdev_module_logs')) {
                return;
            }
            Capsule::table('tblsahdev_module_logs')->insert([
                'level' => $level,
                'source' => $source,
                'message' => $message,
                'ticket_id' => $ticketId,
                'created_at' => \Carbon\Carbon::now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public static function warning(string $source, string $message, ?int $ticketId = null): void
    {
        self::log('warning', $source, $message, $ticketId);
    }

    public static function error(string $source, string $message, ?int $ticketId = null): void
    {
        self::log('error', $source, $message, $ticketId);
    }

    public static function debug(string $source, string $message, ?int $ticketId = null): void
    {
        self::log('debug', $source, $message, $ticketId);
    }

    public static function info(string $source, string $message, ?int $ticketId = null): void
    {
        self::log('info', $source, $message, $ticketId);
    }
}
