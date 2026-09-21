<?php

declare(strict_types=1);

namespace NeuronSearchLab;

use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/** @internal */
final class AccessTokenManager
{
    private const DEFAULT_TOKEN_URL = 'https://auth.neuronsearchlab.com/oauth2/token';

    private const DEFAULT_SCOPE = 'neuronsearchlab-api/read neuronsearchlab-api/write';

    private ?string $staticAccessToken;

    private $tokenProvider;

    private ?array $oauthClientCredentials = null;

    private $transport;

    private float $expirySkewMs;

    private ?array $cachedToken = null;

    private int $generation = 0;

    public function __construct(array $config, callable $transport)
    {
        $accessToken = $this->preserveNonBlank($config['accessToken'] ?? null);
        $tokenProvider = is_callable($config['tokenProvider'] ?? null)
            ? $config['tokenProvider']
            : null;
        $oauthConfig = is_array($config['oauthClientCredentials'] ?? null)
            ? $config['oauthClientCredentials']
            : null;

        $configuredMethods = (int) ($accessToken !== null)
            + (int) ($tokenProvider !== null)
            + (int) ($oauthConfig !== null);
        if ($configuredMethods !== 1) {
            throw new InvalidArgumentException(
                'Configure exactly one authentication method: accessToken, tokenProvider, or oauthClientCredentials'
            );
        }

        $expirySkewMs = $config['tokenExpirySkewMs'] ?? 60000;
        if (!is_numeric($expirySkewMs) || (float) $expirySkewMs < 0) {
            throw new InvalidArgumentException('tokenExpirySkewMs must be a non-negative number');
        }

        $this->staticAccessToken = $accessToken;
        $this->tokenProvider = $tokenProvider;
        $this->transport = $transport;
        $this->expirySkewMs = (float) $expirySkewMs;

        if ($oauthConfig !== null) {
            $this->oauthClientCredentials = $this->normalizeOAuthConfig($oauthConfig);
        }
    }

    public function isRefreshable(): bool
    {
        return is_callable($this->tokenProvider) || $this->oauthClientCredentials !== null;
    }

    public function setStaticAccessToken(string $token): void
    {
        $normalized = $this->preserveNonBlank($token);
        if ($normalized === null) {
            throw new InvalidArgumentException('accessToken must be a non-empty string');
        }

        $this->staticAccessToken = $normalized;
        $this->tokenProvider = null;
        $this->oauthClientCredentials = null;
        $this->cachedToken = null;
        $this->generation += 1;
    }

    /** @return array{value: string, generation: int} */
    public function getToken(): array
    {
        if ($this->staticAccessToken !== null) {
            return [
                'value' => $this->staticAccessToken,
                'generation' => $this->generation,
            ];
        }

        if ($this->cachedToken !== null && $this->isFresh($this->cachedToken)) {
            return $this->snapshot($this->cachedToken);
        }

        return $this->acquire($this->cachedToken === null ? 'initial' : 'expired');
    }

    /** @return array{value: string, generation: int}|null */
    public function refreshAfterUnauthorized(int $rejectedGeneration): ?array
    {
        if (!$this->isRefreshable()) {
            return null;
        }

        if (
            $this->cachedToken !== null
            && $this->cachedToken['generation'] !== $rejectedGeneration
            && $this->isFresh($this->cachedToken)
        ) {
            return $this->snapshot($this->cachedToken);
        }

        $this->cachedToken = null;

        return $this->acquire('unauthorized');
    }

    /** @return array{value: string, generation: int} */
    private function acquire(string $reason): array
    {
        if (is_callable($this->tokenProvider)) {
            try {
                $result = ($this->tokenProvider)([
                    'forceRefresh' => $reason === 'unauthorized',
                    'reason' => $reason,
                ]);
            } catch (Throwable $error) {
                throw new SDKAuthError('Token provider failed', null, $error);
            }
        } elseif ($this->oauthClientCredentials !== null) {
            $result = $this->acquireClientCredentialsToken();
        } else {
            throw new SDKAuthError('No authentication method is configured');
        }

        $issuedAtMs = microtime(true) * 1000;
        if (is_string($result)) {
            $accessToken = $this->preserveNonBlank($result);
            $expiresAtMs = null;
        } elseif (is_array($result)) {
            $accessToken = $this->preserveNonBlank($result['accessToken'] ?? null);
            $expiresAtMs = $this->normalizeExpiry($result, $issuedAtMs);
        } else {
            throw new SDKAuthError('Token provider must return a string or token array');
        }

        if ($accessToken === null) {
            throw new SDKAuthError('Token provider returned an empty access token');
        }

        $this->cachedToken = [
            'value' => $accessToken,
            'issuedAtMs' => $issuedAtMs,
            'expiresAtMs' => $expiresAtMs,
            'generation' => ++$this->generation,
        ];

        return $this->snapshot($this->cachedToken);
    }

    private function normalizeOAuthConfig(array $config): array
    {
        $clientId = $this->preserveNonBlank($config['clientId'] ?? null);
        $clientSecret = $this->preserveNonBlank($config['clientSecret'] ?? null);
        if ($clientId === null || $clientSecret === null) {
            throw new InvalidArgumentException(
                'oauthClientCredentials requires non-empty clientId and clientSecret values'
            );
        }

        $additionalParameters = $config['additionalParameters'] ?? [];
        if (!is_array($additionalParameters)) {
            throw new InvalidArgumentException('OAuth additionalParameters must be an array');
        }
        foreach ($additionalParameters as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('OAuth additionalParameters must contain string keys and values');
            }
            if (in_array($key, ['grant_type', 'client_id', 'client_secret', 'scope'], true)) {
                throw new InvalidArgumentException(
                    sprintf('OAuth additionalParameters cannot override reserved parameter %s', $key)
                );
            }
        }

        return [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'tokenUrl' => $this->normalizeTokenUrl($config['tokenUrl'] ?? null),
            'scope' => $this->normalizeScope($config['scope'] ?? null),
            'audience' => $this->normalizeOptionalString($config['audience'] ?? null),
            'additionalParameters' => $additionalParameters,
        ];
    }

    private function normalizeTokenUrl(mixed $value): string
    {
        $url = $this->normalizeOptionalString($value) ?? self::DEFAULT_TOKEN_URL;
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('OAuth tokenUrl must be a valid absolute URL');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('OAuth tokenUrl must not include URL credentials');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new InvalidArgumentException(
                'OAuth tokenUrl must use HTTPS (HTTP is allowed only for loopback development)'
            );
        }

        return $url;
    }

    private function normalizeScope(mixed $value): string
    {
        if ($value === null) {
            return self::DEFAULT_SCOPE;
        }

        $scopes = is_array($value)
            ? $value
            : (is_string($value) ? preg_split('/\s+/', trim($value)) : null);
        if (!is_array($scopes)) {
            throw new InvalidArgumentException('OAuth scope must be a string or array of strings');
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                throw new InvalidArgumentException('OAuth scope entries must be strings');
            }
            if (trim($scope) !== '') {
                $normalized[] = trim($scope);
            }
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('OAuth scope must contain at least one scope');
        }

        return implode(' ', $normalized);
    }

    private function acquireClientCredentialsToken(): array
    {
        $config = $this->oauthClientCredentials;
        if ($config === null) {
            throw new SDKAuthError('OAuth client credentials are not configured');
        }

        $parameters = array_merge(
            $config['additionalParameters'],
            [
                'grant_type' => 'client_credentials',
                'scope' => $config['scope'],
            ],
            $config['audience'] !== null ? ['audience' => $config['audience']] : []
        );

        try {
            $response = ($this->transport)($config['tokenUrl'], [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($config['clientId'] . ':' . $config['clientSecret']),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            ]);
        } catch (Throwable $error) {
            throw new SDKAuthError('OAuth token request failed', null, $error);
        }

        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new SDKAuthError(
                sprintf('OAuth token request failed with HTTP %d', $status),
                $status
            );
        }

        try {
            $payload = json_decode((string) ($response['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new SDKAuthError('OAuth token response was not valid JSON', null, $error);
        }
        if (!is_array($payload)) {
            throw new SDKAuthError('OAuth token response must be a JSON object');
        }

        $tokenType = $this->normalizeOptionalString($payload['token_type'] ?? null);
        if ($tokenType !== null && strtolower($tokenType) !== 'bearer') {
            throw new SDKAuthError(
                sprintf('OAuth token type %s is not supported; expected Bearer', $tokenType)
            );
        }

        $accessToken = $this->preserveNonBlank($payload['access_token'] ?? null);
        if ($accessToken === null) {
            throw new SDKAuthError('OAuth token response did not include access_token');
        }

        $result = ['accessToken' => $accessToken];
        if (array_key_exists('expires_in', $payload)) {
            if (!is_numeric($payload['expires_in']) || (float) $payload['expires_in'] <= 0) {
                throw new SDKAuthError('OAuth token response expires_in must be a positive number');
            }
            $result['expiresInSeconds'] = (float) $payload['expires_in'];
        }

        return $result;
    }

    private function normalizeExpiry(array $result, float $issuedAtMs): ?float
    {
        if (array_key_exists('expiresAt', $result)) {
            $value = $result['expiresAt'];
            if ($value instanceof DateTimeInterface) {
                $expiresAtMs = (float) $value->format('U.u') * 1000;
            } elseif (is_numeric($value)) {
                $expiresAtMs = (float) $value;
                if ($expiresAtMs < 100000000000) {
                    $expiresAtMs *= 1000;
                }
            } else {
                throw new SDKAuthError('Token provider expiresAt must be a DateTimeInterface or Unix epoch');
            }
            if (!is_finite($expiresAtMs) || $expiresAtMs <= $issuedAtMs) {
                throw new SDKAuthError('Token provider expiresAt must be in the future');
            }

            return $expiresAtMs;
        }

        if (array_key_exists('expiresInSeconds', $result)) {
            $seconds = $result['expiresInSeconds'];
            if (!is_numeric($seconds) || (float) $seconds <= 0) {
                throw new SDKAuthError('Token provider expiresInSeconds must be positive');
            }

            return $issuedAtMs + ((float) $seconds * 1000);
        }

        return null;
    }

    private function isFresh(array $token): bool
    {
        if ($token['expiresAtMs'] === null) {
            return true;
        }

        $lifetimeMs = max(0, $token['expiresAtMs'] - $token['issuedAtMs']);
        $effectiveSkewMs = min($this->expirySkewMs, $lifetimeMs / 2);

        return (microtime(true) * 1000) < $token['expiresAtMs'] - $effectiveSkewMs;
    }

    /** @return array{value: string, generation: int} */
    private function snapshot(array $token): array
    {
        return [
            'value' => $token['value'],
            'generation' => $token['generation'],
        ];
    }

    private function preserveNonBlank(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
