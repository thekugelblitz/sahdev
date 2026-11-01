<?php

namespace Sahdev\Lib;

/** Shared, module-scoped appearance assets for addon pages and ticket panels. */
final class AdminTheme
{
    public static function wrap(string $content, int $adminId): string
    {
        $navigation = '';
        $content = preg_replace_callback('/<!--sahdev-navigation-->(.*?)<!--\/sahdev-navigation-->/s', function ($match) use (&$navigation) {
            $navigation = $match[1];
            return '';
        }, $content);
        // Data endpoints must keep their response shape (e.g. analytics JSON).
        if ($navigation === '' && strpos($content, 'sahdev-page-container') === false) return $content;
        $class = $navigation !== '' ? 'sahdev-ui sdv-workspace' : 'sahdev-ui';
        return self::assets() . '<div class="' . $class . '"' . self::attributes($adminId) . '>'
            . $navigation . '<div class="sdv-workspace-content">' . $content . '</div></div>';
    }

    public static function assets(): string
    {
        static $rendered = false;
        if ($rendered) {
            return '';
        }
        $rendered = true;
        $ui = dirname(__DIR__) . '/ui/';
        return '<style>' . file_get_contents($ui . 'admin-theme.css') . '</style>'
            . '<script>' . file_get_contents($ui . 'admin-theme.js') . '</script>';
    }

    public static function attributes(int $adminId): string
    {
        $mode = AdminPreferences::getThemeMode($adminId);
        $token = function_exists('generate_token') ? generate_token('plain') : '';
        return ' data-sahdev-theme="' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-sahdev-token="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"';
    }
}
