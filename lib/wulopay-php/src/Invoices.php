<?php

namespace WuloPay;

/**
 * Invoices: e-mailed to your customer and paid once on WuloPay's page. WuloPay works out the tax and the total from the
 * line items; you hear about payment by the invoice.paid webhook. Needs scope invoices.create. Responses are arrays
 * shaped like the API's JSON (see https://github.com/WuloPay/wulopay-openapi).
 */
final class Invoices
{
    private readonly Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array{customer:array{name:string, email:string, id?:string, company?:string}, reference?:string,
     *              description?:string, currency?:string,
     *              lineItems?:list<array{name:string, unitPrice:float|int|string, quantity?:int, sku?:string,
     *                                    description?:string, taxRate?:float|int, taxAmount?:float|int|string,
     *                                    discount?:float|int|string}>,
     *              pricesIncludeTax?:bool, shipping?:array{amount:float|int|string, taxRate?:float|int},
     *              discount?:float|int|string, amount?:float|int|string, dueDate?:string, expiresOn?:string,
     *              send?:bool, metadata?:array<string,string>} $params
     *        Prices exclude tax unless pricesIncludeTax. amount is optional with lineItems (checked against WuloPay's
     *        total) and required without them. Dates are Y-m-d. send (default true) e-mails the customer.
     * @param string|null $idempotencyKey Reuse the same key when retrying, so only one invoice is made (and e-mailed).
     * @return array<string,mixed>
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        return $this->client->request('POST', '/Invoices', LinkAmounts::round($params), LinkAmounts::idempotency($idempotencyKey));
    }

    /**
     * @param string|null $status open, paid, cancelled or expired
     * @return array{object:string, data:list<array<string,mixed>>}
     */
    public function all(?string $status = null, ?int $limit = null): array
    {
        return $this->client->request('GET', '/Invoices' . LinkAmounts::query($status, $limit));
    }

    /** @return array<string,mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/Invoices/' . rawurlencode($id));
    }

    /**
     * (Re)sends the invoice e-mail. Open invoices only.
     *
     * @return array<string,mixed>
     */
    public function send(string $id): array
    {
        return $this->client->request('POST', '/Invoices/' . rawurlencode($id) . '/Send');
    }

    /**
     * Cancels an unpaid invoice (a paid one is refunded instead, on its checkout session).
     *
     * @return array<string,mixed>
     */
    public function cancel(string $id): array
    {
        return $this->client->request('POST', '/Invoices/' . rawurlencode($id) . '/Cancel');
    }
}
