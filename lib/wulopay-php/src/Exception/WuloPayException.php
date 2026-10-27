<?php

namespace WuloPay\Exception;

/** Base class for every error the library throws. */
class WuloPayException extends \RuntimeException
{
    private ?int $httpStatus;

    public function __construct(string $message, ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus ?? 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    /** HTTP status of the API response, or null when the API was not reached. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
