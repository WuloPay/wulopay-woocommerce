<?php

namespace WuloPay\HttpClient;

/** Minimal transport, so applications (and tests) can plug in their own. */
interface HttpClientInterface
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string} Header names lower-case.
     * @throws \WuloPay\Exception\ApiConnectionException when no response was received.
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array;
}
