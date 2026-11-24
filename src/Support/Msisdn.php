<?php

namespace Molefigog\Payments\Support;

final class Msisdn
{
    /**
     * @param  string  $format  raw | digits | local | international
     */
    public static function format(string $raw, string $format = 'raw', string $prefix = '266'): string
    {
        $raw = trim($raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return match ($format) {
            'digits' => $digits,
            'international' => str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix) + 6
                ? $digits
                : $prefix.ltrim($digits, '0'),
            'local' => str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix) + 6
                ? substr($digits, strlen($prefix))
                : ltrim($digits, '0'),
            default => $raw,
        };
    }
}
