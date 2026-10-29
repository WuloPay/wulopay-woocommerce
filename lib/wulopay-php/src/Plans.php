<?php

namespace WuloPay;

/**
 * Plans: what a subscription charges and how often. Responses are arrays shaped like the API's JSON
 * (see https://github.com/WuloPay/wulopay-openapi).
 */
final class Plans
{
    private readonly Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array{name:string, amount:float|int|string, interval:string, currency?:string, intervalCount?:int,
     *              cycles?:int|null, trialDays?:int} $params interval is day, week, month or year; trialDays are free days
     *              before the first charge.
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        $params['amount'] = round((float) ($params['amount'] ?? 0), 2);
        return $this->client->request('POST', '/Billing/Plans', $params);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->client->request('GET', '/Billing/Plans');
    }

    /** @return array<string,mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', '/Billing/Plans/' . rawurlencode($id));
    }
}
