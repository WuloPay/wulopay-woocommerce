<?php

namespace WuloPay;

/**
 * Optional shared cache for access tokens (e.g. WordPress transients, Redis), so separate PHP requests reuse one
 * token instead of asking for a new one each time. The token endpoint is rate limited, so caching matters.
 */
interface TokenCacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $token, int $ttlSeconds): void;

    public function delete(string $key): void;
}
