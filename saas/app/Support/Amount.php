<?php
namespace App\Support;
use Illuminate\Validation\ValidationException;
final class Amount
{
    public const SCALE = 1000000;
    public static function parse(string $value, int $decimals = 6): int
    {
        if (!preg_match('/^\d{1,9}(?:\.\d{1,'.$decimals.'})?$/D', $value)) throw ValidationException::withMessages(['amount' => 'Enter a positive decimal amount with up to '.$decimals.' decimal places.']);
        $parts = explode('.', $value, 2);
        return (int)$parts[0] * (10 ** $decimals) + (int)str_pad($parts[1] ?? '', $decimals, '0');
    }
    public static function format(int $value, int $decimals = 6): string
    {
        $scale = 10 ** $decimals; $abs = abs($value);
        return ($value < 0 ? '-' : '').number_format(intdiv($abs, $scale)).'.'.str_pad((string)($abs % $scale), $decimals, '0', STR_PAD_LEFT);
    }
    public static function charge(int $tokens, int $rate): int
    {
        if ($tokens < 0 || $tokens > 1000000 || $rate < 0 || $rate > 1000000000000) throw new \InvalidArgumentException('Usage or rate outside supported bounds.');
        return intdiv($tokens * $rate + self::SCALE - 1, self::SCALE);
    }
}
