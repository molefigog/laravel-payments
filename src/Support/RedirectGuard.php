<?php

namespace Molefigog\Payments\Support;

/** Prevents open redirects: only configured hosts / app schemes may receive the browser. */
final class RedirectGuard
{
    public static function isAllowed(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        if (in_array($scheme, ['http', 'https'], true)) {
            $host = strtolower($parts['host'] ?? '');

            return $host !== '' && in_array($host, self::allowedHosts(), true);
        }

        return in_array($scheme, array_map('strtolower', (array) config('payments.allowed_redirect_schemes', [])), true);
    }

    /** @return array<int, string> */
    private static function allowedHosts(): array
    {
        $hosts = (array) config('payments.allowed_redirect_hosts', []);

        foreach (['app.url', 'app.frontend_url', 'app.frontend_web_url'] as $key) {
            $value = config($key);
            if (is_string($value) && $value !== '') {
                $hosts[] = parse_url($value, PHP_URL_HOST) ?: '';
            }
        }

        return array_values(array_filter(array_map('strtolower', $hosts)));
    }

    public static function append(string $url, array $query): string
    {
        $fragment = '';
        if (($pos = strpos($url, '#')) !== false) {
            $fragment = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query).$fragment;
    }
}
