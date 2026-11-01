<?php
namespace Sahdev\Lib;

/** Escape labels that WHMCS may already have stored as HTML entities. */
final class DisplayText
{
    public static function label($value): string
    {
        return htmlspecialchars(self::plain($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function plain($value): string
    {
        $plain = (string) $value;
        // Some WHMCS labels were entity-encoded before storage, sometimes twice.
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $plain) break;
            $plain = $decoded;
        }
        return $plain;
    }
}
