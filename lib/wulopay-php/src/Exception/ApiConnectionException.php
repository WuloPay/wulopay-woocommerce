<?php

namespace WuloPay\Exception;

/** The API could not be reached (network error or timeout). Safe to retry with the same Idempotency-Key. */
class ApiConnectionException extends WuloPayException
{
}
