<?php

namespace App\Http;

/**
 * Turns the configured proxy list into the shape TrustProxies wants.
 */
class TrustedProxies
{
    /**
     * @return list<string>|string|null Null when no proxy is to be trusted.
     */
    public static function from(string $configured): array|string|null
    {
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', $configured),
        )));

        return match (true) {
            $proxies === [] => null,
            $proxies === ['*'] => '*',
            default => $proxies,
        };
    }
}
