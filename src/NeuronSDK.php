<?php

declare(strict_types=1);

namespace NeuronSearchLab;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class NeuronSDK
{
    private string $baseUrl;

    private string $accessToken;

    private int $timeoutMs;

    private int $maxRetries;

    private int $collateWindowMs;

    private int $maxBatchSize;

    private int $maxBufferedEvents;

    private int $maxEventRetries;

    private bool $disableArrayBatching;

    private bool $arrayBatchingRejected = false;

    private bool $propagateRecommendationRequestId;

    private ?string $lastRecommendationRequestId = null;

    private bool $autoSessionId;

    private ?string $sessionId = null;

    private array $eventBuffer = [];

    private ?float $firstBufferedAt = null;

    private bool $isFlushing = false;

    private int $flushRetryCount = 0;

    private bool $shutdownFlushRegistered = false;

    private $httpClient;

    private $backoffStrategy;

    public function __construct(array $config)
    {
        if (empty($config['baseUrl']) || empty($config['accessToken'])) {
            throw new InvalidArgumentException('baseUrl and accessToken are required');
        }

        $this->baseUrl = self::normalizeApiBaseUrl((string) $config['baseUrl']);
        $this->accessToken = (string) $config['accessToken'];
        $this->timeoutMs = (int) ($config['timeoutMs'] ?? 10000);
        $this->maxRetries = (int) ($config['maxRetries'] ?? 2);
        $this->collateWindowMs = (int) round(((float) ($config['collateWindowSeconds'] ?? 3)) * 1000);
        $this->maxBatchSize = (int) ($config['maxBatchSize'] ?? 200);
        $this->maxBufferedEvents = (int) ($config['maxBufferedEvents'] ?? 5000);
        $this->maxEventRetries = (int) ($config['maxEventRetries'] ?? 5);
        $this->disableArrayBatching = (bool) ($config['disableArrayBatching'] ?? false);
        $this->propagateRecommendationRequestId = (bool) ($config['propagateRecommendationRequestId'] ?? true);
        $this->autoSessionId = (bool) ($config['autoSessionId'] ?? true);
        $this->sessionId = $this->normalizeOptionalString($config['sessionId'] ?? null);
        $this->httpClient = $config['httpClient'] ?? null;
        $this->backoffStrategy = is_callable($config['backoffStrategy'] ?? null)
            ? $config['backoffStrategy']
            : null;

        if ($this->autoSessionId && $this->sessionId === null) {
            $this->sessionId = self::generateSessionId();
        }

        if (!is_callable($this->httpClient) && !function_exists('curl_init')) {
            throw new InvalidArgumentException(
                'cURL is not available in this environment. Provide config.httpClient.'
            );
        }

        $this->registerShutdownFlush();
    }

    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
    }

    public function setBaseUrl(string $url): void
    {
        $this->baseUrl = self::normalizeApiBaseUrl($url);
    }

    public function setTimeout(int $timeoutMs): void
    {
        $this->timeoutMs = $timeoutMs;
    }

    public function setRequestId(?string $requestId): void
    {
        $this->lastRecommendationRequestId = $this->normalizeOptionalString($requestId);
    }

    public function getRequestId(): ?string
    {
        return $this->lastRecommendationRequestId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $this->normalizeOptionalString($sessionId);

        if ($this->autoSessionId && $this->sessionId === null) {
            $this->sessionId = self::generateSessionId();
        }
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function flushEvents(array $options = []): void
    {
        if ($this->isFlushing || $this->eventBuffer === []) {
            return;
        }

        $this->isFlushing = true;

        try {
            while ($this->eventBuffer !== []) {
                $batch = array_splice($this->eventBuffer, 0, $this->maxBatchSize);
                $this->recalculateFirstBufferedAt();

                try {
                    $response = $this->sendBatch($batch, $options);

                    foreach ($batch as $entry) {
                        $entry['pending']->resolve($response);
                    }

                    $this->flushRetryCount = 0;
                } catch (Throwable $error) {
                    $this->eventBuffer = array_merge($batch, $this->eventBuffer);
                    $this->recalculateFirstBufferedAt();
                    $this->trimBufferIfNeeded();

                    $this->flushRetryCount += 1;
                    $willRetry = $this->flushRetryCount <= $this->maxEventRetries;

                    logger()->{$willRetry ? 'warn' : 'error'}(
                        $willRetry
                            ? 'Failed to send events, retrying synchronously'
                            : 'Dropping events after max retries',
                        [
                            'attempt' => $this->flushRetryCount,
                            'maxEventRetries' => $this->maxEventRetries,
                            'error' => $error->getMessage(),
                            'bufferedCount' => count($this->eventBuffer),
                        ]
                    );

                    if ($willRetry) {
                        $this->sleepMs((int) round($this->backoffMs($this->flushRetryCount)));
                        continue;
                    }

                    $dropError = new RuntimeException(
                        'Max retries reached while sending buffered events',
                        0,
                        $error
                    );

                    $dropped = array_splice($this->eventBuffer, 0, count($batch));
                    foreach ($dropped as $entry) {
                        $entry['pending']->reject($dropError);
                    }

                    $this->recalculateFirstBufferedAt();
                    break;
                }
            }
        } finally {
            $this->isFlushing = false;
        }
    }

    public function trackEvent(array $data): PendingResult
    {
        $payload = $this->normalizeEventPayload($data);

        if ($this->shouldFlushByAge()) {
            $this->flushEvents();
        }

        $existingRequestId = is_string($data['requestId'] ?? null)
            ? $data['requestId']
            : (is_string($data['request_id'] ?? null) ? $data['request_id'] : null);

        $requestIdToAttach = ($existingRequestId === null || $existingRequestId === '')
            && $this->propagateRecommendationRequestId
            ? $this->lastRecommendationRequestId
            : null;

        $existingSessionId = is_string($data['sessionId'] ?? null)
            ? $data['sessionId']
            : (is_string($data['session_id'] ?? null) ? $data['session_id'] : null);

        if ($this->autoSessionId && $this->sessionId === null) {
            $this->sessionId = self::generateSessionId();
        }

        $sessionIdToAttach = ($existingSessionId === null || $existingSessionId === '')
            ? $this->sessionId
            : null;

        if ($requestIdToAttach !== null) {
            $payload['request_id'] = $requestIdToAttach;
        }

        if ($sessionIdToAttach !== null) {
            $payload['session_id'] = $sessionIdToAttach;
        }

        $payload['client_ts'] = gmdate(DATE_ATOM);

        $pending = new PendingResult($this);
        $this->trimBufferIfNeeded(1);
        $this->eventBuffer[] = [
            'payload' => $payload,
            'pending' => $pending,
            'enqueueTime' => microtime(true),
        ];
        $this->firstBufferedAt ??= (float) $this->eventBuffer[array_key_last($this->eventBuffer)]['enqueueTime'];

        if ($this->collateWindowMs === 0 || count($this->eventBuffer) >= $this->maxBatchSize) {
            $this->flushEvents();
        }

        return $pending;
    }

    public function createEvent(array $data): PendingResult
    {
        return $this->trackEvent($data);
    }

    public function upsertItem(array $data): mixed
    {
        $payload = array_is_list($data)
            ? array_map(fn (array $item): array => $this->normalizeItemPayload($item), $data)
            : $this->normalizeItemPayload($data);

        return $this->request('/items', [
            'method' => 'POST',
            'headers' => $this->getHeaders(),
            'body' => $this->encodeJson($payload),
        ]);
    }

    public function patchItem(array $input): mixed
    {
        $itemId = $this->extractItemId($input);

        if (!$this->isPositiveInteger($itemId)) {
            throw new InvalidArgumentException(
                'itemId is required and must be a positive integer returned by NSL'
            );
        }

        $patch = $input;
        unset($patch['id']);
        unset($patch['itemId']);
        unset($patch['item_id']);

        if ($patch === []) {
            throw new InvalidArgumentException(
                'patchItem requires at least one field to update (for example active => false)'
            );
        }

        return $this->request('/items/' . rawurlencode((string) $itemId), [
            'method' => 'POST',
            'headers' => $this->getHeaders(),
            'body' => $this->encodeJson($patch),
        ]);
    }

    public function setItemActive(int $itemId, bool $active): mixed
    {
        return $this->patchItem([
            'itemId' => $itemId,
            'active' => $active,
        ]);
    }

    public function deleteItems(array $items): mixed
    {
        $payload = array_is_list($items) ? $items : [$items];

        if ($payload === []) {
            throw new InvalidArgumentException(
                'itemId is required and must be a positive integer returned by NSL'
            );
        }

        foreach ($payload as $entry) {
            if (!is_array($entry) || !$this->isPositiveInteger($this->extractItemId($entry))) {
                throw new InvalidArgumentException(
                    'itemId is required and must be a positive integer returned by NSL'
                );
            }
        }

        $responses = [];

        foreach ($payload as $entry) {
            $itemId = $this->extractItemId($entry);
            $responses[] = $this->request('/items/' . rawurlencode((string) $itemId), [
                'method' => 'DELETE',
                'headers' => $this->getHeaders(),
            ]);
        }

        if (count($responses) === 1) {
            return $responses[0];
        }

        return [
            'message' => 'Items deleted successfully',
            'object' => 'list',
            'itemIds' => array_map(fn (array $entry): int => (int) $this->extractItemId($entry), $payload),
            'deletedCount' => count($responses),
            'data' => $responses,
        ];
    }

    public function getRecommendations(array $options): mixed
    {
        $userId = $options['userId'] ?? null;
        if (!is_string($userId) && !is_int($userId)) {
            throw new InvalidArgumentException('userId must be a string or number');
        }

        $query = [
            'user_id' => (string) $userId,
        ];

        if (array_key_exists('contextId', $options)) {
            if (!$this->isPositiveInteger($options['contextId'])) {
                throw new InvalidArgumentException('contextId must be a positive integer');
            }
            $query['context_id'] = (string) $options['contextId'];
        }

        if (isset($options['limit']) && is_int($options['limit'])) {
            $query['limit'] = (string) $options['limit'];
        }

        if (!empty($options['startingAfter'])) {
            $query['starting_after'] = (string) $options['startingAfter'];
        } elseif (!empty($options['starting_after'])) {
            $query['starting_after'] = (string) $options['starting_after'];
        }

        $response = $this->request(
            $this->baseUrl . '/recommendations?' . http_build_query($query),
            [
                'method' => 'GET',
                'headers' => $this->getHeaders(),
            ]
        );

        if ($this->propagateRecommendationRequestId && is_array($response) && isset($response['request_id'])) {
            $this->lastRecommendationRequestId = (string) $response['request_id'];
        }

        return $response;
    }

    public function getAutoRecommendations(array $options): mixed
    {
        $userId = $options['userId'] ?? null;
        if (!is_string($userId) && !is_int($userId)) {
            throw new InvalidArgumentException('userId must be a string or number');
        }

        $query = [
            'mode' => 'auto',
            'user_id' => (string) $userId,
        ];

        if (array_key_exists('contextId', $options)) {
            if (!$this->isPositiveInteger($options['contextId'])) {
                throw new InvalidArgumentException('contextId must be a positive integer');
            }
            $query['context_id'] = (string) $options['contextId'];
        }

        foreach ([
            'limit' => 'limit',
            'cursor' => 'cursor',
            'windowDays' => 'window_days',
            'candidateLimit' => 'candidate_limit',
            'servedCap' => 'served_cap',
        ] as $optionKey => $queryKey) {
            if (isset($options[$optionKey]) && $options[$optionKey] !== '') {
                $query[$queryKey] = (string) $options[$optionKey];
            }
        }

        $response = $this->request(
            $this->baseUrl . '/recommendations?' . http_build_query($query),
            [
                'method' => 'GET',
                'headers' => $this->getHeaders(),
            ]
        );

        if ($this->propagateRecommendationRequestId && is_array($response) && isset($response['request_id'])) {
            $this->lastRecommendationRequestId = (string) $response['request_id'];
        }

        return $response;
    }

    public function search(array $options): mixed
    {
        $payload = $this->normalizeSearchPayload($options);

        $response = $this->request('/search', [
            'method' => 'POST',
            'headers' => $this->getHeaders(),
            'body' => $this->encodeJson($payload),
        ]);

        if ($this->propagateRecommendationRequestId && is_array($response) && isset($response['request_id'])) {
            $this->lastRecommendationRequestId = (string) $response['request_id'];
        }

        return $response;
    }

    private function request(string $pathOrUrl, array $init = []): mixed
    {
        $method = (string) ($init['method'] ?? 'GET');
        $url = preg_match('#^https?://#i', $pathOrUrl) === 1
            ? $pathOrUrl
            : $this->baseUrl . '/' . ltrim($pathOrUrl, '/');

        $retryOn = $init['retryOn'] ?? [429, 500, 502, 503, 504];
        $attempt = 0;
        $requestId = logger()->shouldLog('DEBUG') || logger()->isPerformanceLoggingEnabled()
            ? sprintf('%s-%s', base_convert((string) time(), 10, 36), substr(bin2hex(random_bytes(4)), 0, 8))
            : null;

        while (true) {
            $startTime = logger()->isPerformanceLoggingEnabled() ? microtime(true) : null;

            if (logger()->shouldLog('DEBUG')) {
                logger()->debug('HTTP request attempt', [
                    'method' => $method,
                    'url' => $url,
                    'attempt' => $attempt,
                    'maxRetries' => $this->maxRetries,
                    'retryOn' => $retryOn,
                    'requestId' => $requestId,
                    'requestBody' => is_string($init['body'] ?? null) ? $init['body'] : null,
                ]);
            }

            try {
                $response = $this->performHttpRequest($url, $init);
                $durationMs = $startTime !== null ? (int) round((microtime(true) - $startTime) * 1000) : null;
                $status = (int) ($response['status'] ?? 0);
                $statusText = (string) ($response['statusText'] ?? '');
                $bodyText = (string) ($response['body'] ?? '');

                if ($status >= 200 && $status < 300) {
                    if (logger()->shouldLog('DEBUG')) {
                        logger()->debug('HTTP response received', [
                            'method' => $method,
                            'url' => $url,
                            'attempt' => $attempt,
                            'status' => $status,
                            'requestId' => $requestId,
                            'durationMs' => $durationMs,
                        ]);
                    }

                    if ($bodyText !== '' && logger()->shouldLog('TRACE')) {
                        logger()->trace('HTTP response payload', [
                            'method' => $method,
                            'url' => $url,
                            'requestId' => $requestId,
                            'responseBody' => $bodyText,
                        ]);
                    }

                    return $bodyText === '' ? null : $this->parseResponseBody($bodyText);
                }

                if (logger()->shouldLog('WARN')) {
                    logger()->warn('HTTP response not OK', [
                        'method' => $method,
                        'url' => $url,
                        'attempt' => $attempt,
                        'status' => $status,
                        'statusText' => $statusText,
                        'requestId' => $requestId,
                        'durationMs' => $durationMs,
                        'responseBody' => $bodyText,
                    ]);
                }

                $body = $bodyText === '' ? null : $this->parseResponseBody($bodyText);

                if (in_array($status, $retryOn, true) && $attempt < $this->maxRetries) {
                    $attempt += 1;
                    $retryAfter = $this->extractRetryAfterMs($response['headers'] ?? []);
                    $delayMs = $retryAfter ?? $this->backoffMs($attempt);

                    logger()->info('Retrying request after HTTP status', [
                        'method' => $method,
                        'url' => $url,
                        'attempt' => $attempt,
                        'status' => $status,
                        'delayMs' => $delayMs,
                        'requestId' => $requestId,
                    ]);

                    $this->sleepMs((int) round($delayMs));
                    continue;
                }

                throw new SDKHttpError(
                    sprintf('HTTP %d %s for %s %s', $status, $statusText, $method, $url),
                    $status,
                    $statusText,
                    is_array($body) || is_string($body) ? $body : null
                );
            } catch (SDKTimeoutError $error) {
                if ($attempt < $this->maxRetries) {
                    $attempt += 1;
                    logger()->warn('Retrying request after timeout', [
                        'method' => $method,
                        'url' => $url,
                        'attempt' => $attempt,
                        'timeoutMs' => $this->timeoutMs,
                        'requestId' => $requestId,
                    ]);
                    $this->sleepMs((int) round($this->backoffMs($attempt)));
                    continue;
                }

                logger()->error('Request aborted after max retries', [
                    'method' => $method,
                    'url' => $url,
                    'attempts' => $attempt,
                    'timeoutMs' => $this->timeoutMs,
                    'requestId' => $requestId,
                ]);

                throw $error;
            } catch (Throwable $error) {
                if ($attempt < $this->maxRetries) {
                    $attempt += 1;
                    logger()->warn('Retrying request after network error', [
                        'method' => $method,
                        'url' => $url,
                        'attempt' => $attempt,
                        'error' => $error->getMessage(),
                        'requestId' => $requestId,
                    ]);
                    $this->sleepMs((int) round($this->backoffMs($attempt)));
                    continue;
                }

                logger()->error('Request failed', [
                    'method' => $method,
                    'url' => $url,
                    'attempts' => $attempt,
                    'error' => $error->getMessage(),
                    'requestId' => $requestId,
                ]);

                throw $error;
            }
        }
    }

    private function performHttpRequest(string $url, array $init): array
    {
        if (is_callable($this->httpClient)) {
            $response = ($this->httpClient)($url, $init);

            if (!is_array($response)) {
                throw new RuntimeException('Custom httpClient must return an array response');
            }

            return [
                'status' => (int) ($response['status'] ?? 0),
                'statusText' => (string) ($response['statusText'] ?? ''),
                'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
                'body' => (string) ($response['body'] ?? ''),
            ];
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL is required unless config.httpClient is provided');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => (string) ($init['method'] ?? 'GET'),
            CURLOPT_HTTPHEADER => $this->headersToLines($init['headers'] ?? []),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return strlen($headerLine);
            },
        ]);

        if (array_key_exists('body', $init)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $init['body']);
        }

        $body = curl_exec($ch);

        if ($body === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new SDKTimeoutError($this->timeoutMs);
            }

            throw new RuntimeException(sprintf('cURL error %d: %s', $errno, $error));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'statusText' => '',
            'headers' => $responseHeaders,
            'body' => (string) $body,
        ];
    }

    private function sendBatch(array $batch, array $options): mixed
    {
        $shouldSendArray = count($batch) > 1
            && !$this->disableArrayBatching
            && !$this->arrayBatchingRejected;

        if ($shouldSendArray) {
            try {
                return $this->postEvents(
                    array_map(static fn (array $entry): array => $entry['payload'], $batch),
                    $options
                );
            } catch (SDKHttpError $error) {
                if (!$this->arrayBatchingRejected) {
                    $this->arrayBatchingRejected = true;
                    logger()->warn('Array payload rejected, falling back to single-event sends', [
                        'status' => $error->status,
                        'statusText' => $error->statusText,
                    ]);

                    return $this->sendIndividually($batch, $options);
                }

                throw $error;
            }
        }

        return $this->sendIndividually($batch, $options);
    }

    private function sendIndividually(array $batch, array $options): mixed
    {
        $lastResponse = null;

        foreach ($batch as $entry) {
            $lastResponse = $this->postEvents($entry['payload'], $options);
        }

        return $lastResponse;
    }

    private function postEvents(array $payload, array $options): mixed
    {
        return $this->request('/events', [
            'method' => 'POST',
            'headers' => $this->getHeaders(),
            'body' => $this->encodeJson($payload),
            'keepalive' => (bool) ($options['useBeacon'] ?? false),
        ]);
    }

    private function getHeaders(array $extra = []): array
    {
        return array_merge([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken,
        ], $extra);
    }

    private function shouldFlushByAge(): bool
    {
        if ($this->firstBufferedAt === null || $this->collateWindowMs <= 0) {
            return false;
        }

        return ((microtime(true) - $this->firstBufferedAt) * 1000) >= $this->collateWindowMs;
    }

    private function trimBufferIfNeeded(int $incomingCount = 0): void
    {
        $overflow = count($this->eventBuffer) + $incomingCount - $this->maxBufferedEvents;

        if ($overflow <= 0) {
            return;
        }

        $dropped = array_splice($this->eventBuffer, 0, $overflow);

        logger()->warn('Dropping buffered events due to maxBufferedEvents limit', [
            'maxBufferedEvents' => $this->maxBufferedEvents,
            'dropped' => $overflow,
        ]);

        foreach ($dropped as $entry) {
            $entry['pending']->reject(
                new RuntimeException('Event dropped because the buffer exceeded maxBufferedEvents')
            );
        }

        $this->recalculateFirstBufferedAt();
    }

    private function recalculateFirstBufferedAt(): void
    {
        $this->firstBufferedAt = $this->eventBuffer === []
            ? null
            : (float) $this->eventBuffer[0]['enqueueTime'];
    }

    private function backoffMs(int $attempt): float
    {
        if (is_callable($this->backoffStrategy)) {
            return (float) ($this->backoffStrategy)($attempt);
        }

        return (300 * (2 ** ($attempt - 1))) + (mt_rand(0, 20000) / 100);
    }

    private function sleepMs(int $delayMs): void
    {
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function parseResponseBody(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $body;
        }
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function extractRetryAfterMs(array $headers): ?float
    {
        $retryAfter = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;

        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return ((float) $retryAfter) * 1000;
        }

        return null;
    }

    private function headersToLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = sprintf('%s: %s', $name, $value);
        }

        return $lines;
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function isEventId(mixed $value): bool
    {
        return is_int($value) && $value !== 0;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeApiBaseUrl(string $url): string
    {
        $trimmed = rtrim($url, '/');

        return preg_match('#/v\d+$#i', $trimmed) === 1 ? $trimmed : $trimmed . '/v1';
    }

    private function normalizeNonEmptyString(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function extractItemId(array $input): mixed
    {
        return $input['id'] ?? $input['item_id'] ?? $input['itemId'] ?? null;
    }

    private function normalizeItemPayload(array $data): array
    {
        if (array_key_exists('id', $data) || array_key_exists('itemId', $data) || array_key_exists('item_id', $data)) {
            throw new InvalidArgumentException(
                'item IDs are generated by NSL; omit id, itemId, and item_id when ingesting items'
            );
        }

        return $data;
    }

    private function normalizeSearchPayload(array $options): array
    {
        $query = $this->normalizeOptionalString($options['query'] ?? null);
        if ($query === null) {
            throw new InvalidArgumentException('query is required');
        }

        $payload = ['query' => $query];
        $userId = $this->normalizeNonEmptyString($options['user_id'] ?? $options['userId'] ?? null);
        $contextId = $options['context_id'] ?? $options['contextId'] ?? null;
        $requestId = $this->normalizeOptionalString($options['request_id'] ?? $options['requestId'] ?? null);

        if ($userId !== null) {
            $payload['user_id'] = $userId;
        }

        if ($contextId !== null) {
            if (!$this->isPositiveInteger($contextId)) {
                throw new InvalidArgumentException('contextId must be a positive integer');
            }
            $payload['context_id'] = $contextId;
        }

        if (isset($options['limit']) && is_numeric($options['limit'])) {
            $payload['limit'] = (string) max(1, (int) $options['limit']);
        }

        if ($requestId !== null) {
            $payload['request_id'] = $requestId;
        }

        $filters = $options['filter'] ?? $options['filters'] ?? null;
        if (is_string($filters)) {
            $payload['filter'] = $filters;
        } elseif ($this->isStringList($filters)) {
            $payload['filter'] = $filters;
        }

        $scope = $options['scope'] ?? null;
        if (is_string($scope) && trim($scope) !== '') {
            $payload['scope'] = trim($scope);
        } elseif (is_array($scope)) {
            $payload['scope'] = $this->encodeJson($scope);
        } elseif ($this->isStructuredFilterList($options['filters'] ?? null)) {
            $payload['scope'] = $this->encodeJson(['filters' => $options['filters']]);
        }

        $queryRetrievalEnabled = $this->serializeSearchBoolean(
            $options['query_retrieval_enabled'] ?? $options['queryRetrievalEnabled'] ?? null
        );
        if ($queryRetrievalEnabled !== null) {
            $payload['query_retrieval_enabled'] = $queryRetrievalEnabled;
        }

        $fusionMethod = $options['fusion_method'] ?? $options['fusionMethod'] ?? null;
        if ($fusionMethod === 'rrf' || $fusionMethod === 'weighted') {
            $payload['fusion_method'] = $fusionMethod;
        }

        foreach ([
            'semantic_weight' => $options['semantic_weight'] ?? $options['semanticWeight'] ?? null,
            'keyword_weight' => $options['keyword_weight'] ?? $options['keywordWeight'] ?? null,
        ] as $payloadKey => $value) {
            $serialized = $this->serializeSearchNumber($value);
            if ($serialized !== null) {
                $payload[$payloadKey] = $serialized;
            }
        }

        $keywordFields = $options['keyword_fields'] ?? $options['keywordFields'] ?? null;
        if ($this->isStringList($keywordFields)) {
            $payload['keyword_fields'] = implode(',', array_filter(array_map('trim', $keywordFields)));
        } elseif (is_string($keywordFields) && trim($keywordFields) !== '') {
            $payload['keyword_fields'] = trim($keywordFields);
        }

        if (array_key_exists('debug', $options)) {
            $payload['debug'] = $options['debug'];
        }

        if (isset($options['explain']) && is_string($options['explain'])) {
            $payload['explain'] = $options['explain'];
        }

        $includeSuppressed = $options['include_suppressed'] ?? $options['includeSuppressed'] ?? null;
        if ($includeSuppressed !== null) {
            $payload['include_suppressed'] = $includeSuppressed;
        }

        return $payload;
    }

    private function normalizeEventPayload(array $data): array
    {
        $userId = $this->normalizeNonEmptyString($data['user_id'] ?? $data['userId'] ?? null);
        $itemId = $data['item_id'] ?? $data['itemId'] ?? null;
        $eventId = $data['event']
                ?? $data['event_id']
                ?? $data['eventId']
                ?? $data['event_type']
                ?? $data['eventType']
                ?? $data['type']
                ?? null;
        $contextId = $data['context_id'] ?? $data['contextId'] ?? null;
        $deduplicationAliases = [
            'deduplication_id',
            'deduplicationId',
            'idempotency_key',
            'idempotencyKey',
            'message_id',
            'messageId',
        ];
        $deduplicationValues = [];
        foreach ($deduplicationAliases as $alias) {
            if (!array_key_exists($alias, $data)) {
                continue;
            }
            $value = $data[$alias];
            if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > 255) {
                throw new InvalidArgumentException(
                    'deduplicationId must be a non-empty string of at most 255 characters'
                );
            }
            $deduplicationValues[] = trim($value);
        }
        if (count(array_unique($deduplicationValues)) > 1) {
            throw new InvalidArgumentException(
                'deduplication id aliases must contain the same value'
            );
        }
        $deduplicationId = $deduplicationValues[0] ?? null;

        if ($userId === null || !$this->isPositiveInteger($itemId) || !$this->isEventId($eventId)) {
            throw new InvalidArgumentException(
                'eventId must be a non-zero integer, itemId must be a positive integer, and userId is required'
            );
        }

        if ($contextId !== null && !$this->isPositiveInteger($contextId)) {
            throw new InvalidArgumentException('contextId must be a positive integer when provided');
        }

        $occurredAt = isset($data['occurred_at']) && is_int($data['occurred_at'])
            ? $data['occurred_at']
            : (isset($data['occurredAt']) && is_int($data['occurredAt'])
                ? $data['occurredAt']
                : time());

        $data['user_id'] = $userId;
        $data['item_id'] = $itemId;
        $data['event_id'] = $eventId;
        if ($contextId !== null) {
            $data['context_id'] = $contextId;
        }
        foreach (['event', 'eventId', 'eventType', 'event_type', 'type', 'itemId', 'contextId'] as $alias) {
            unset($data[$alias]);
        }
        $data['occurred_at'] = $occurredAt;
        foreach ($deduplicationAliases as $alias) {
            unset($data[$alias]);
        }
        if ($deduplicationId !== null) {
            $data['deduplication_id'] = $deduplicationId;
        }

        return $data;
    }

    private function isStringList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }

        return true;
    }

    private function isStructuredFilterList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['column'], $entry['operator'])
                || !array_key_exists('value', $entry)
            ) {
                return false;
            }
        }

        return $value !== [];
    }

    private function serializeSearchBoolean(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    private function serializeSearchNumber(mixed $value): ?string
    {
        if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
            return (string) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return trim($value);
        }

        return null;
    }

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownFlushRegistered) {
            return;
        }

        register_shutdown_function(function (): void {
            if ($this->eventBuffer === []) {
                return;
            }

            try {
                $this->flushEvents(['useBeacon' => true]);
            } catch (Throwable $error) {
                logger()->error('Shutdown event flush failed', [
                    'error' => $error->getMessage(),
                    'bufferedCount' => count($this->eventBuffer),
                ]);
            }
        });

        $this->shutdownFlushRegistered = true;
    }

    private static function generateSessionId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
