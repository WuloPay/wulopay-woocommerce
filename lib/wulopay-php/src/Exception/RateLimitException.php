<?php

namespace WuloPay\Exception;

/** 429: too many requests; wait getRetryAfter() seconds (when known) and retry. */
class RateLimitException extends WuloPayException
{
    private ?int $retryAfter;

    public function __construct(string $message, ?int $retryAfter = null)
    {
        parent::__construct($message, 429);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
