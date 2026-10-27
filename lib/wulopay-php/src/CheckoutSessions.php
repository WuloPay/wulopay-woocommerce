<?php

namespace WuloPay;

/**
 * Checkout sessions: one per order. Responses are arrays shaped like the API's JSON
 * (see https://github.com/WuloPay/wulopay-openapi).
 */
final class CheckoutSessions
{
    private readonly Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Creates a session; send the shopper to the returned 'url'.
     *
     * @param array{amount:float|int|string, currency:string, reference:string, returnUrl:string, cancelUrl?:string,
     *              description?:string, customer?:array{email?:string,name?:string}, metadata?:array<string,string>,
     *              expiresInMinutes?:int} $params
     * @param string|null $idempotencyKey Reuse the same key when retrying the same order, so only one session is made.
     * @return array<string,mixed>
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        if (isset($params['amount'])) {
            $params['amount'] = round((float) $params['amount'], 2);
        }
        $headers = $idempotencyKey !== null && $idempotencyKey !== '' ? ['Idempotency-Key' => $idempotencyKey] : [];
        return $this->client->request('POST', '/Checkout/Sessions', $params, $headers);
    }

    /** @return array<string,mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/Checkout/Sessions/' . rawurlencode($id));
    }

    /** @return array<string,mixed> */
    public function cancel(string $id): array
    {
        return $this->client->request('POST', '/Checkout/Sessions/' . rawurlencode($id) . '/Cancel');
    }

    /**
     * Refunds a paid session: everything still refundable when $amount is null, else that amount.
     *
     * @return array{sessionId:string, refundedThisTime:float, totalRefunded:float, remainingRefundable:float}
     */
    public function refund(string $id, ?float $amount = null, ?string $reason = null): array
    {
        $body = [];
        if ($amount !== null) {
            $body['amount'] = round($amount, 2);
        }
        if ($reason !== null && $reason !== '') {
            $body['reason'] = $reason;
        }
        return $this->client->request('POST', '/Checkout/Sessions/' . rawurlencode($id) . '/Refunds', $body);
    }
}
