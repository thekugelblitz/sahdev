<?php
namespace App\Support;
final class Totp
{
    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $value = '';
        for ($i=0;$i<32;$i++) $value .= $alphabet[random_int(0,31)];
        return $value;
    }
    public static function code(string $secret, int $step, int $digits = 6): string
    {
        $bits = ''; foreach (str_split($secret) as $char) { $n = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$char); if ($n === false) throw new \InvalidArgumentException('Invalid TOTP secret.'); $bits .= str_pad(decbin($n),5,'0',STR_PAD_LEFT); }
        $key = ''; foreach (str_split($bits,8) as $byte) if (strlen($byte) === 8) $key .= chr(bindec($byte));
        $hash = hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$key,true); $offset = ord($hash[19]) & 15;
        $value = unpack('N',substr($hash,$offset,4))[1] & 0x7fffffff;
        return str_pad((string)($value % (10 ** $digits)),$digits,'0',STR_PAD_LEFT);
    }
    public static function verify(string $secret, string $code, int $lastStep): ?int
    {
        if (!preg_match('/^\d{6}$/D',$code)) return null;
        $now = intdiv(time(),30);
        foreach ([$now-1,$now,$now+1] as $step) if ($step > $lastStep && hash_equals(self::code($secret,$step),$code)) return $step;
        return null;
    }
}
