<?php

namespace Sahdev\Lib;

/**
 * Resolves the WHMCS cron HTTP URL (SystemURL + /crons/cron.php) and effective URL with Sahdev override.
 */
class CronUrlHelper
{
    public static function whmcsDefaultCronHttpUrl(): string
    {
        try {
            if (class_exists('\WHMCS\Config\Setting')) {
                $su = \WHMCS\Config\Setting::getValue('SystemURL');
                if (!empty($su)) {
                    return rtrim($su, '/') . '/crons/cron.php';
                }
            }
        } catch (\Throwable $e) {
        }

        return '';
    }

    public static function effectiveHttpUrl(?string $override): string
    {
        $o = trim((string) $override);
        if ($o !== '') {
            return $o;
        }

        return self::whmcsDefaultCronHttpUrl();
    }
}
