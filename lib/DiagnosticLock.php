<?php
namespace Sahdev\Lib;
use WHMCS\Database\Capsule;

/** Connection-owned MySQL advisory locks; no lease expires during an upstream call. */
class DiagnosticLock
{
    private static $held = [];
    public static function acquire(string $key): bool
    {
        $name = 'sdv5:' . substr(hash('sha256', $key), 0, 54);
        if (isset(self::$held[$name])) return false;
        try {
            $row = Capsule::connection()->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name]);
            if ((int) ($row->acquired ?? 0) !== 1) return false;
            self::$held[$name] = true;
            return true;
        } catch (\Throwable $e) { return false; }
    }
    public static function release(string $key): void
    {
        $name = 'sdv5:' . substr(hash('sha256', $key), 0, 54);
        if (!isset(self::$held[$name])) return;
        try { Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]); }
        catch (\Throwable $e) { /* Connection close also releases advisory locks. */ }
        finally { unset(self::$held[$name]); }
    }
}
