<?php

namespace WuloPay;

use WuloPay\Exception\ApiConnectionException;
use WuloPay\Exception\AuthenticationException;
use WuloPay\Exception\ConflictException;
use WuloPay\Exception\InvalidRequestException;
use WuloPay\Exception\NotFoundException;
use WuloPay\Exception\PermissionException;
use WuloPay\Exception\RateLimitException;
use WuloPay\Exception\WuloPayException;
use WuloPay\HttpClient\CurlHttpClient;
use WuloPay\HttpClient\HttpClientInterface;

/**
 * WuloPay API client. Server-side only: the key and secret must never reach a browser or a mobile app.
 *
 *   $wulopay = new \WuloPay\Client('wk_...', getenv('WULOPAY_SECRET'));
 *   $session = $wulopay->checkoutSessions->create([...]);
 */
final class Client
{
    public const VERSION = '1.1.0';
    public const SANDBOX_BASE_URL = 'https://sandbox.wulopay.com/api/v1';

    public readonly CheckoutSessions $checkoutSessions;
    public readonly Plans $plans;
    public readonly Subscriptions $subscriptions;

    private string $keyId;
    private string $secret;
    private string $baseUrl;
    private int $timeout;
    private HttpClientInterface $http;
    private ?TokenCacheInterface $tokenCache;
    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    /**
     * @param array{baseUrl?:string, timeout?:int, httpClient?:HttpClientInterface, tokenCache?:TokenCacheInterface} $options
     *        baseUrl defaults to the sandbox; WuloPay gives you the live URL when your account goes live.
     */
    public function __construct(string $keyId, string $secret, array $options = [])
    {
        if ($keyId === '' || $secret === '') {
            throw new \InvalidArgumentException('Both the API key id and the secret are required.');
        }
        $this->keyId = $keyId;
        $this->secret = $secret;
        $this->baseUrl = rtrim($options['baseUrl'] ?? self::SANDBOX_BASE_URL, '/');
        if (strpos($this->baseUrl, 'https://') !== 0) {
            throw new \InvalidArgumentException('baseUrl must start with https://');
        }
        $this->timeout = (int) ($options['timeout'] ?? 30);
        $this->http = $options['httpClient'] ?? new CurlHttpClient();
        $this->tokenCache = $options['tokenCache'] ?? null;
        $this->checkoutSessions = new CheckoutSessions($this);
        $this->plans = new Plans($this);
        $this->subscriptions = new Subscriptions($this);
    }

    /**
     * Calls the API with a bearer token, refreshing it once if the API refuses it.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string> $headers
     * @return array<string,mixed>
     * @internal Used by the service classes.
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $response = $this->send($method, $path, $body, $headers + ['Authorization' => 'Bearer ' . $this->accessToken()]);
        if ($response['status'] === 401) {
            $this->forgetToken();
            $response = $this->send($method, $path, $body, $headers + ['Authorization' => 'Bearer ' . $this->accessToken()]);
        }
        return $this->decode($response);
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }
        $cacheKey = $this->cacheKey();
        if ($this->tokenCache !== null && ($cached = $this->tokenCache->get($cacheKey))) {
            $this->token = $cached;
            $this->tokenExpiresAt = time() + 60; // re-check the shared cache at least every minute
            return $cached;
        }
        $data = $this->decode($this->send('POST', '/Authorization/Token/ApiToken', ['key' => $this->keyId, 'secret' => $this->secret], []));
        if (empty($data['authToken']) || !is_string($data['authToken'])) {
            throw new AuthenticationException('WuloPay did not return an access token.', 401);
        }
        $ttl = max(60, (int) ($data['expiresIn'] ?? 3600) - 120); // renew 2 minutes early
        $this->token = $data['authToken'];
        $this->tokenExpiresAt = time() + $ttl;
        if ($this->tokenCache !== null) {
            $this->tokenCache->set($cacheKey, $this->token, $ttl);
        }
        return $this->token;
    }

    private function forgetToken(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = 0;
        if ($this->tokenCache !== null) {
            $this->tokenCache->delete($this->cacheKey());
        }
    }

    private function cacheKey(): string
    {
        return 'wulopay_token_' . substr(hash('sha256', $this->baseUrl . '|' . $this->keyId), 0, 24);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function send(string $method, string $path, ?array $body, array $headers): array
    {
        $headers += [
            'Accept' => 'application/json',
            'User-Agent' => 'wulopay-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
        $json = null;
        if ($body !== null) {
            // An empty PHP array would encode as [] but the API expects a JSON object.
            $json = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ($json === false) {
                throw new \InvalidArgumentException('The request could not be encoded as JSON.');
            }
            $headers['Content-Type'] = 'application/json';
        }
        return $this->http->request($method, $this->baseUrl . $path, $headers, $json, $this->timeout);
    }

    /** The API's { "error": "..." }, or the first message of a validation problem { "title", "errors": {...} }. */
    private static function errorMessage($data): ?string
    {
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['error']) && is_string($data['error'])) {
            return $data['error'];
        }
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                    return $messages[0];
                }
            }
        }
        return isset($data['title']) && is_string($data['title']) ? $data['title'] : null;
    }

    /**
     * @param array{status:int, headers:array<string,string>, body:string} $response
     * @return array<string,mixed>
     */
    private function decode(array $response): array
    {
        $status = $response['status'];
        $data = $response['body'] === '' ? [] : json_decode($response['body'], true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($data)) {
                throw new WuloPayException('WuloPay returned an unexpected response.', $status);
            }
            return $data;
        }
        $message = self::errorMessage($data) ?? 'WuloPay request failed with HTTP ' . $status . '.';
        switch ($status) {
            case 400:
                throw new InvalidRequestException($message, 400);
            case 401:
                throw new AuthenticationException($message, 401);
            case 403:
                throw new PermissionException($message, 403);
            case 404:
                throw new NotFoundException($message, 404);
            case 409:
                throw new ConflictException($message, 409);
            case 429:
                $retry = $response['headers']['retry-after'] ?? null;
                throw new RateLimitException($message, $retry !== null ? (int) $retry : null);
            case 0:
                throw new ApiConnectionException($message);
            default:
                throw new WuloPayException($message, $status);
        }
    }
}
