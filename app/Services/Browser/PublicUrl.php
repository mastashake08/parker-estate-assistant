<?php

namespace App\Services\Browser;

class PublicUrl
{
    /**
     * Determine whether the URL is an http(s) address on the public internet.
     *
     * This keeps the assistant from being used to reach the database, other
     * containers, the host machine, or cloud metadata endpoints.
     */
    public static function check(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $host = trim($parts['host'] ?? '', '[]');

        if ($host === '') {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        return $addresses !== [] && collect($addresses)->every(fn (string $address) => filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false);
    }
}
