<?php

namespace WuloPay;

use WuloPay\Exception\SignatureVerificationException;

/**
 * Verifies a webhook from WuloPay and returns the event.
 *
 *   $event = \WuloPay\Webhook::constructEvent(
 *       file_get_contents('php://input'), $_SERVER['HTTP_WULOPAY_SIGNATURE'] ?? '', $signingSecret);
 *   if ($event['type'] === 'checkout.session.completed') { $session = $event['data']['object']; ... }
 *
 * Always pass the raw request body, exactly as received.
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    /**
     * @return array<string,mixed> The event: id, type, createdAt, environment, data.object
     * @throws SignatureVerificationException
     */
    public static function constructEvent(string $payload, string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): array
    {
        self::verifySignature($payload, $signatureHeader, $secret, $tolerance);
        $event = json_decode($payload, true);
        if (!is_array($event) || !isset($event['id'], $event['type'])) {
            throw new SignatureVerificationException('The webhook body is not a WuloPay event.');
        }
        return $event;
    }

    /** @throws SignatureVerificationException */
    public static function verifySignature(string $payload, string $signatureHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): void
    {
        if ($secret === '') {
            throw new SignatureVerificationException('No webhook signing secret is configured.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && ctype_digit($kv[1])) {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if ($timestamp === null || $signatures === []) {
            throw new SignatureVerificationException('The WuloPay-Signature header is missing or malformed.');
        }
        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            throw new SignatureVerificationException('The webhook timestamp is outside the allowed tolerance.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }
        throw new SignatureVerificationException('The webhook signature does not match.');
    }
}
