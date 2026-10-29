<?php

namespace WuloPay;

/**
 * Subscriptions, created when a sign-up session (mode "subscription") is paid. In merchant billing your system calls
 * charge() when a renewal is due; in WuloPay billing WuloPay charges on schedule and you follow along by webhook.
 */
final class Subscriptions
{
    private readonly Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param string|null $status trialing, active, past_due, paused, cancelled or completed
     * @return array{object:string, data:list<array<string,mixed>>}
     */
    public function all(?string $status = null, ?int $limit = null): array
    {
        $query = array_filter(['status' => $status, 'limit' => $limit], static fn ($v) => $v !== null && $v !== '');
        return $this->client->request('GET', '/Billing/Subscriptions' . ($query ? '?' . http_build_query($query) : ''));
    }

    /** @return array<string,mixed> */
    public function retrieve(string $id): array
    {
        return $this->client->request('GET', $this->path($id));
    }

    /**
     * Cancels now, or (WuloPay billing) at the end of the period the shopper has paid for.
     *
     * @return array<string,mixed>
     */
    public function cancel(string $id, bool $atPeriodEnd = false): array
    {
        return $this->client->request('POST', $this->path($id) . '/Cancel', ['atPeriodEnd' => $atPeriodEnd]);
    }

    /** WuloPay billing: stop charging until resumed (missed periods are skipped). @return array<string,mixed> */
    public function pause(string $id): array
    {
        return $this->client->request('POST', $this->path($id) . '/Pause', []);
    }

    /** @return array<string,mixed> */
    public function resume(string $id): array
    {
        return $this->client->request('POST', $this->path($id) . '/Resume', []);
    }

    /**
     * Merchant billing: charges the stored card now. The idempotency key is required (e.g. your renewal order id): a
     * retry with the same key returns the same charge and never charges twice. A declined charge is returned with
     * status "failed" (and retryAllowed false when the card must not be retried).
     *
     * @return array<string,mixed>
     */
    public function charge(string $id, string $idempotencyKey, float|int|string|null $amount = null): array
    {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('An idempotency key is required (e.g. your renewal order id).');
        }
        $body = $amount === null ? [] : ['amount' => round((float) $amount, 2)];
        return $this->client->request('POST', $this->path($id) . '/Charges', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /** Charge history, newest first (cycle 1 is the sign-up payment). @return list<array<string,mixed>> */
    public function charges(string $id): array
    {
        return $this->client->request('GET', $this->path($id) . '/Charges');
    }

    /**
     * A payment-page link where the shopper replaces the card (the bank checks it; nothing is charged).
     *
     * @return array{id:string, url:string, subscriptionId:string, expiresAt:string}
     */
    public function createCardUpdateSession(string $id, string $returnUrl): array
    {
        return $this->client->request('POST', $this->path($id) . '/CardUpdateSession', ['returnUrl' => $returnUrl]);
    }

    /**
     * A fresh link to the shopper's own subscription page (update the card, cancel). The previous link stops working.
     *
     * @return array{url:string, expiresAt:string}
     */
    public function createManageLink(string $id): array
    {
        return $this->client->request('POST', $this->path($id) . '/ManageLink', []);
    }

    /** Sandbox only: makes the next scheduled charge (or the end of the trial) happen now. @return array<string,mixed> */
    public function advance(string $id): array
    {
        return $this->client->request('POST', $this->path($id) . '/Advance', []);
    }

    private function path(string $id): string
    {
        return '/Billing/Subscriptions/' . rawurlencode($id);
    }
}
