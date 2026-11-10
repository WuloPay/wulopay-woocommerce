<?php

namespace WuloPay;

/**
 * Payment links: a page you share yourself, paid as often as you allow (maxPayments; 1 = single use). Each payment
 * sends payment_link.paid. Needs scope links.purchase.create.
 */
final class PaymentLinks
{
    private readonly Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array{amount?:float|int|string, lineItems?:list<array<string,mixed>>, reference?:string, description?:string,
     *              currency?:string, pricesIncludeTax?:bool, expiresAt?:string, maxPayments?:int,
     *              metadata?:array<string,string>} $params Give amount, or lineItems (WuloPay adds them up).
     * @return array<string,mixed>
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        return $this->client->request('POST', '/PaymentLinks', LinkAmounts::round($params), LinkAmounts::idempotency($idempotencyKey));
    }

    /**
     * @param string|null $status active, completed, cancelled or expired
     * @return array{object:string, data:list<array<string,mixed>>}
     */
    public function all(?string $status = null, ?int $limit = null): array
    {
        return $this->client->request('GET', '/PaymentLinks' . LinkAmounts::query($status, $limit));
    }

    /** @return array<string,mixed> With timesPaid and amountCollected. */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/PaymentLinks/' . rawurlencode($id));
    }

    /**
     * Stops taking payments. Payments already made stay (refund them on their checkout session).
     *
     * @return array<string,mixed>
     */
    public function cancel(string $id): array
    {
        return $this->client->request('POST', '/PaymentLinks/' . rawurlencode($id) . '/Cancel');
    }
}
