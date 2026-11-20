<?php

namespace WuloPay;

/**
 * Saved cards, per shopper, named by your customerReference (1 to 64 characters: letters, digits and . _ : @ + -).
 * Cards are saved on WuloPay's page: a checkout session with 'mode' => 'save_card', or 'saveCard' => true while paying.
 * Needs scopes wallet.read and wallet.write; pay() also needs checkout.sessions.create.
 */
final class Wallet
{
    private readonly Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Creates the customer, or updates their name, e-mail and phone. Optional: saving a card creates the customer too.
     *
     * @param array{name?:string, email?:string, phone?:string} $params
     * @return array<string,mixed> The customer with their cards.
     */
    public function upsertCustomer(string $customerReference, array $params = []): array
    {
        return $this->client->request('PUT', self::customer($customerReference), $params);
    }

    /** @return array<string,mixed> The customer with their saved cards. */
    public function retrieveCustomer(string $customerReference): array
    {
        return $this->client->request('GET', self::customer($customerReference));
    }

    /** Deletes the customer and every saved card (for example on a privacy request). */
    public function deleteCustomer(string $customerReference): void
    {
        $this->client->request('DELETE', self::customer($customerReference));
    }

    /** @return list<array<string,mixed>> Saved cards: the default first, then the newest. */
    public function listCards(string $customerReference): array
    {
        return $this->client->request('GET', self::customer($customerReference) . '/Cards');
    }

    /**
     * Renames a card, or makes it the default.
     *
     * @param array{nickname?:string, isDefault?:true} $params
     * @return array<string,mixed>
     */
    public function updateCard(string $customerReference, string $cardId, array $params): array
    {
        return $this->client->request('PATCH', self::customer($customerReference) . '/Cards/' . rawurlencode($cardId), $params);
    }

    /** Removes a card: it can no longer be charged from this moment. */
    public function removeCard(string $customerReference, string $cardId): void
    {
        $this->client->request('DELETE', self::customer($customerReference) . '/Cards/' . rawurlencode($cardId));
    }

    /**
     * Pays with a saved card. Open the returned 'url' for the shopper: WuloPay's page shows the card and they confirm
     * with 3-D Secure. Confirm the payment from checkout.session.completed or checkoutSessions->retrieve($sessionId).
     *
     * @param array{amount:float|int|string, reference:string, returnUrl:string, currency?:string, cancelUrl?:string,
     *              description?:string, metadata?:array<string,string>, expiresInMinutes?:int} $params
     * @return array<string,mixed> status 'requires_action', sessionId, url, expiresAt, amount, currency, reference, card.
     */
    public function pay(string $customerReference, string $cardId, array $params, ?string $idempotencyKey = null): array
    {
        return $this->client->request('POST', self::customer($customerReference) . '/Cards/' . rawurlencode($cardId) . '/Payments',
            LinkAmounts::round($params), LinkAmounts::idempotency($idempotencyKey));
    }

    private static function customer(string $customerReference): string
    {
        return '/Wallet/Customers/' . rawurlencode($customerReference);
    }
}
