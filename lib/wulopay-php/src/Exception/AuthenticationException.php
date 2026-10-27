<?php

namespace WuloPay\Exception;

/** 401: the API key or secret is wrong, revoked, or the token was refused. */
class AuthenticationException extends WuloPayException
{
}
