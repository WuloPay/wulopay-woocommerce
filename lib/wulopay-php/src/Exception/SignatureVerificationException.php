<?php

namespace WuloPay\Exception;

/** A webhook signature is missing, malformed, stale or does not match. */
class SignatureVerificationException extends WuloPayException
{
}
