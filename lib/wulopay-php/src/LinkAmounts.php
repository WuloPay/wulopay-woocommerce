<?php

namespace WuloPay;

/** @internal Rounds the money fields of invoice and payment link requests to cents before sending. */
final class LinkAmounts
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function round(array $params): array
    {
        foreach (['amount', 'discount'] as $field) {
            if (isset($params[$field])) {
                $params[$field] = round((float) $params[$field], 2);
            }
        }
        if (isset($params['shipping']['amount'])) {
            $params['shipping']['amount'] = round((float) $params['shipping']['amount'], 2);
        }
        if (isset($params['lineItems']) && is_array($params['lineItems'])) {
            foreach ($params['lineItems'] as $i => $line) {
                foreach (['unitPrice', 'taxAmount', 'discount'] as $field) {
                    if (isset($line[$field])) {
                        $params['lineItems'][$i][$field] = round((float) $line[$field], 2);
                    }
                }
            }
        }
        return $params;
    }

    /** @return array<string,string> */
    public static function idempotency(?string $key): array
    {
        return $key !== null && $key !== '' ? ['Idempotency-Key' => $key] : [];
    }

    public static function query(?string $status, ?int $limit): string
    {
        $query = array_filter(['status' => $status, 'limit' => $limit], static fn ($v) => $v !== null && $v !== '');
        return $query ? '?' . http_build_query($query) : '';
    }
}
