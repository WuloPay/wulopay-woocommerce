<?php

namespace WuloPay\Exception;

/** 409: the request conflicts with the current state (e.g. refunding an unpaid session). */
class ConflictException extends WuloPayException
{
}
