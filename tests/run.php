<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NeuronSearchLab\NeuronSDK;
use NeuronSearchLab\SDKAuthError;
use NeuronSearchLab\SDKHttpError;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . sprintf(' Expected %s, got %s.', var_export($expected, true), var_export($actual, true))
        );
    }
}

function testBatchesEventsAndPreservesOrder(): void
{
    $requests = [];

    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'collateWindowSeconds' => 10,
        'maxBatchSize' => 10,
        'backoffStrategy' => static fn (): int => 1,
        'httpClient' => static function (string $url, array $init) use (&$requests): array {
            $requests[] = ['url' => $url, 'init' => $init];

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $pendingOne = $sdk->trackEvent(['eventId' => 41, 'userId' => 'u1', 'itemId' => 1]);
    $pendingTwo = $sdk->trackEvent(['eventId' => 42, 'userId' => 'u1', 'itemId' => 2]);

    $sdk->flushEvents();

    expectSame(1, count($requests), 'Expected one batched event request.');

    $body = json_decode($requests[0]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    expect(is_array($body), 'Expected array event payload.');
    expectSame(2, count($body), 'Expected two events in the batch.');
    expectSame(41, $body[0]['event_id'], 'Expected first event to preserve order.');
    expectSame('u1', $body[0]['user_id'], 'Expected first event user_id.');
    expectSame(1, $body[0]['item_id'], 'Expected first event item_id.');
    expectSame(42, $body[1]['event_id'], 'Expected second event to preserve order.');
    expectSame(2, $body[1]['item_id'], 'Expected second event item_id.');
    expect(isset($body[0]['client_ts']), 'Expected first event client timestamp.');
    expect(isset($body[1]['client_ts']), 'Expected second event client timestamp.');

    $pendingOne->wait();
    $pendingTwo->wait();
}

function testPropagatesRecommendationRequestIds(): void
{
    $requests = [];

    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'collateWindowSeconds' => 0,
        'maxBatchSize' => 10,
        'backoffStrategy' => static fn (): int => 1,
        'httpClient' => static function (string $url, array $init) use (&$requests): array {
            $requests[] = ['url' => $url, 'init' => $init];

            if (str_contains($url, '/recommendations')) {
                return [
                    'status' => 200,
                    'statusText' => 'OK',
                    'headers' => [],
                    'body' => json_encode([
                        'request_id' => 'req-123',
                        'recommendations' => [],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $sdk->getRecommendations(['userId' => 'u1', 'limit' => 5]);
    $sdk->trackEvent(['eventId' => 41, 'userId' => 'u1', 'itemId' => 3])->wait();

    expectSame(2, count($requests), 'Expected one recommendation call and one event call.');
    $body = json_decode($requests[1]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    $event = is_array($body) && array_is_list($body) ? $body[0] : $body;
    expectSame('req-123', $event['request_id'] ?? null, 'Expected propagated request_id.');
}

function testSearchPostsToCoreApiEndpointAndPropagatesRequestId(): void
{
    $requests = [];

    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'collateWindowSeconds' => 0,
        'backoffStrategy' => static fn (): int => 1,
        'httpClient' => static function (string $url, array $init) use (&$requests): array {
            $requests[] = ['url' => $url, 'init' => $init];

            if (str_ends_with($url, '/search')) {
                return [
                    'status' => 200,
                    'statusText' => 'OK',
                    'headers' => [],
                    'body' => json_encode([
                        'object' => 'list',
                        'url' => '/v1/search',
                        'request_id' => '66666666-6666-4666-8666-666666666666',
                        'query' => 'fresh tech',
                        'recommendations' => [],
                        'data' => [],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $result = $sdk->search([
        'query' => ' fresh tech ',
        'userId' => 'u1',
        'contextId' => 101,
        'limit' => 3,
        'filter' => ['category:tech'],
        'queryRetrievalEnabled' => true,
        'fusionMethod' => 'weighted',
        'semanticWeight' => 0.7,
        'keywordWeight' => 0.3,
        'keywordFields' => ['name', 'description'],
    ]);

    expectSame('/v1/search', $result['url'] ?? null, 'Expected search response URL.');
    expectSame('https://api.example.com/v1/search', $requests[0]['url'], 'Expected Core API search URL.');
    expectSame('POST', $requests[0]['init']['method'], 'Expected POST search request.');

    $payload = json_decode($requests[0]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    expectSame('fresh tech', $payload['query'] ?? null, 'Expected trimmed search query.');
    expectSame('u1', $payload['user_id'] ?? null, 'Expected user ID.');
    expectSame(101, $payload['context_id'] ?? null, 'Expected context ID.');
    expectSame('3', $payload['limit'] ?? null, 'Expected string limit.');
    expectSame(['category:tech'], $payload['filter'] ?? null, 'Expected shorthand filters.');
    expectSame('true', $payload['query_retrieval_enabled'] ?? null, 'Expected string boolean.');
    expectSame('weighted', $payload['fusion_method'] ?? null, 'Expected fusion method.');
    expectSame('0.7', $payload['semantic_weight'] ?? null, 'Expected semantic weight.');
    expectSame('0.3', $payload['keyword_weight'] ?? null, 'Expected keyword weight.');
    expectSame('name,description', $payload['keyword_fields'] ?? null, 'Expected keyword fields CSV.');

    $sdk->trackEvent(['eventId' => 42, 'userId' => 'u1', 'itemId' => 30])->wait();
    $event = json_decode($requests[1]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    expectSame(
        '66666666-6666-4666-8666-666666666666',
        $event['request_id'] ?? null,
        'Expected search request_id to propagate to events.'
    );
}

function testRetriesAfterFailure(): void
{
    $requests = [];
    $attempts = 0;

    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'collateWindowSeconds' => 0,
        'maxBatchSize' => 5,
        'maxRetries' => 0,
        'maxEventRetries' => 3,
        'backoffStrategy' => static fn (): int => 1,
        'httpClient' => static function (string $url, array $init) use (&$requests, &$attempts): array {
            $attempts += 1;
            $requests[] = ['url' => $url, 'init' => $init, 'attempt' => $attempts];

            if ($attempts === 1) {
                throw new RuntimeException('network down');
            }

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $sdk->trackEvent(['eventId' => 41, 'userId' => 'u1', 'itemId' => 5])->wait();

    expectSame(2, $attempts, 'Expected a retry after the first network failure.');
    $body = json_decode($requests[count($requests) - 1]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    $event = is_array($body) && array_is_list($body) ? $body[0] : $body;
    expectSame(41, $event['event_id'], 'Expected the retried event payload.');
    expectSame(5, $event['item_id'], 'Expected the retried item_id.');
}

function testAutoSessionIdIsAttached(): void
{
    $requests = [];

    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'collateWindowSeconds' => 0,
        'httpClient' => static function (string $url, array $init) use (&$requests): array {
            $requests[] = ['url' => $url, 'init' => $init];

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $sessionId = $sdk->getSessionId();
    $sdk->trackEvent(['eventId' => 41, 'userId' => 'u1', 'itemId' => 6])->wait();

    expect(is_string($sessionId) && $sessionId !== '', 'Expected an auto-generated session ID.');
    $body = json_decode($requests[0]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    $event = is_array($body) && array_is_list($body) ? $body[0] : $body;
    expectSame($sessionId, $event['session_id'] ?? null, 'Expected session_id on the event payload.');
}

function testWhitespaceRequestAndSessionIdsSuppressAutoPropagation(): void
{
    $requests = [];

    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'collateWindowSeconds' => 0,
        'httpClient' => static function (string $url, array $init) use (&$requests): array {
            $requests[] = ['url' => $url, 'init' => $init];

            if (str_contains($url, '/recommendations')) {
                return [
                    'status' => 200,
                    'statusText' => 'OK',
                    'headers' => [],
                    'body' => json_encode([
                        'request_id' => 'req-123',
                        'recommendations' => [],
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $sdk->getRecommendations(['userId' => 'u1']);
    $sdk->trackEvent([
        'eventId' => 41,
        'userId' => 'u1',
        'itemId' => 7,
        'requestId' => '   ',
        'sessionId' => '   ',
    ])->wait();

    $body = json_decode($requests[1]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    $event = is_array($body) && array_is_list($body) ? $body[0] : $body;

    expectSame('   ', $event['requestId'] ?? null, 'Expected original whitespace requestId to be preserved.');
    expect(!isset($event['request_id']), 'Expected propagated request_id to stay suppressed when requestId is whitespace.');
    expectSame('   ', $event['sessionId'] ?? null, 'Expected original whitespace sessionId to be preserved.');
    expect(!isset($event['session_id']), 'Expected auto session_id to stay suppressed when sessionId is whitespace.');
}

function testRecommendationResponsePreservesRawBodyShape(): void
{
    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'httpClient' => static function (): array {
            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => 'plain-text-response',
            ];
        },
    ]);

    $response = $sdk->getRecommendations(['userId' => 'u1']);
    expectSame('plain-text-response', $response, 'Expected raw non-JSON recommendation responses to be preserved.');
}

function testTokenProviderCachesToken(): void
{
    $providerContexts = [];
    $authorizationHeaders = [];
    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'tokenProvider' => static function (array $context) use (&$providerContexts): array {
            $providerContexts[] = $context;

            return ['accessToken' => 'provider-token', 'expiresInSeconds' => 3600];
        },
        'httpClient' => static function (string $url, array $init) use (&$authorizationHeaders): array {
            $authorizationHeaders[] = $init['headers']['Authorization'] ?? null;

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['recommendations' => []], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $sdk->getRecommendations(['userId' => 'user-1']);
    $sdk->getRecommendations(['userId' => 'user-2']);

    expectSame(
        [['forceRefresh' => false, 'reason' => 'initial']],
        $providerContexts,
        'Expected a cached token provider result.'
    );
    expectSame(
        ['Bearer provider-token', 'Bearer provider-token'],
        $authorizationHeaders,
        'Expected the provider token on both API calls.'
    );
}

function testOAuthClientCredentialsRefreshOnceAfter401(): void
{
    $tokenUrl = 'https://auth.neuronsearchlab.com/oauth2/token';
    $tokenCalls = 0;
    $apiCalls = 0;
    $apiUrls = [];
    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'oauthClientCredentials' => [
            'clientId' => 'server-client',
            'clientSecret' => 'server-secret',
        ],
        'httpClient' => static function (string $url, array $init) use (
            $tokenUrl,
            &$tokenCalls,
            &$apiCalls,
            &$apiUrls
        ): array {
            if ($url === $tokenUrl) {
                $tokenCalls += 1;
                expectSame(
                    'Basic ' . base64_encode('server-client:server-secret'),
                    $init['headers']['Authorization'] ?? null,
                    'Expected OAuth Basic client authentication.'
                );
                parse_str((string) ($init['body'] ?? ''), $form);
                expectSame('client_credentials', $form['grant_type'] ?? null, 'Expected client_credentials grant.');
                expectSame(
                    'neuronsearchlab-api/read neuronsearchlab-api/write',
                    $form['scope'] ?? null,
                    'Expected hosted NSL scopes.'
                );

                return [
                    'status' => 200,
                    'statusText' => 'OK',
                    'headers' => [],
                    'body' => json_encode([
                        'access_token' => 'oauth-token-' . $tokenCalls,
                        'token_type' => 'Bearer',
                        'expires_in' => 3600,
                    ], JSON_THROW_ON_ERROR),
                ];
            }

            $apiCalls += 1;
            $apiUrls[] = $url;
            $authorization = $init['headers']['Authorization'] ?? null;
            if ($authorization === 'Bearer oauth-token-1') {
                return [
                    'status' => 401,
                    'statusText' => 'Unauthorized',
                    'headers' => [],
                    'body' => json_encode(['error' => 'expired_token'], JSON_THROW_ON_ERROR),
                ];
            }

            expectSame('Bearer oauth-token-2', $authorization, 'Expected the refreshed API token.');

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['recommendations' => []], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $sdk->getRecommendations([
        'userId' => 'user-1',
        'contextId' => 101,
    ]);
    $sdk->getRecommendations(['userId' => 'user-2']);

    expectSame(2, $tokenCalls, 'Expected initial acquisition plus one forced refresh.');
    expectSame(3, $apiCalls, 'Expected one 401 retry and one cached-token request.');
    expect(
        str_contains($apiUrls[0], 'context_id=101'),
        'Expected stable numeric context_id serialization.'
    );
}

function testFinalEvent401IsNotRetriedInBackground(): void
{
    $providerCalls = 0;
    $apiCalls = 0;
    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'tokenProvider' => static function () use (&$providerCalls): string {
            $providerCalls += 1;

            return 'event-token-' . $providerCalls;
        },
        'collateWindowSeconds' => 0,
        'maxEventRetries' => 5,
        'httpClient' => static function () use (&$apiCalls): array {
            $apiCalls += 1;

            return [
                'status' => 401,
                'statusText' => 'Unauthorized',
                'headers' => [],
                'body' => json_encode(['error' => 'unauthorized'], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $pending = $sdk->trackEvent([
        'eventId' => 41,
        'userId' => 'user-1',
        'itemId' => 1,
    ]);
    try {
        $pending->wait();
        throw new RuntimeException('Expected the final event 401 to surface.');
    } catch (SDKHttpError $error) {
        expectSame(401, $error->status, 'Expected the final 401 response.');
    }

    expectSame(2, $providerCalls, 'Expected exactly one authorization refresh.');
    expectSame(2, $apiCalls, 'Expected exactly one event retry after refresh.');
}

function testEventIdempotencyAliasesNormalizeToCanonicalField(): void
{
    $requestBody = null;
    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'accessToken' => 'token',
        'collateWindowSeconds' => 0,
        'httpClient' => static function (string $url, array $init) use (&$requestBody): array {
            $requestBody = (string) ($init['body'] ?? '');

            return [
                'status' => 200,
                'statusText' => 'OK',
                'headers' => [],
                'body' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            ];
        },
    ]);

    $sdk->trackEvent([
        'eventId' => 41,
        'userId' => 'user-1',
        'itemId' => 1,
        'messageId' => 'customer-event-001',
    ])->wait();

    $event = json_decode((string) $requestBody, true, 512, JSON_THROW_ON_ERROR);
    expectSame('customer-event-001', $event['deduplication_id'] ?? null, 'Expected canonical deduplication_id.');
    expect(!array_key_exists('messageId', $event), 'Expected the messageId alias to be removed.');
}

function testOAuthIssuerErrorsDoNotExposeSecrets(): void
{
    $secret = 'do-not-expose-this-secret';
    $sdk = new NeuronSDK([
        'baseUrl' => 'https://api.example.com/v1',
        'oauthClientCredentials' => [
            'clientId' => 'client-id',
            'clientSecret' => $secret,
            'tokenUrl' => 'https://auth.example.com/oauth2/token',
        ],
        'httpClient' => static fn (): array => [
            'status' => 401,
            'statusText' => 'Unauthorized',
            'headers' => [],
            'body' => 'issuer echoed ' . $secret,
        ],
    ]);

    try {
        $sdk->getRecommendations(['userId' => 'user-1']);
        throw new RuntimeException('Expected OAuth token acquisition to fail.');
    } catch (SDKAuthError $error) {
        expectSame(401, $error->status, 'Expected OAuth issuer status.');
        expect(!str_contains($error->getMessage(), $secret), 'Expected client secret redaction.');
        expect(!str_contains($error->getMessage(), 'issuer echoed'), 'Expected issuer body redaction.');
    }
}

$tests = [
    'testBatchesEventsAndPreservesOrder',
    'testPropagatesRecommendationRequestIds',
    'testSearchPostsToCoreApiEndpointAndPropagatesRequestId',
    'testRetriesAfterFailure',
    'testAutoSessionIdIsAttached',
    'testWhitespaceRequestAndSessionIdsSuppressAutoPropagation',
    'testRecommendationResponsePreservesRawBodyShape',
    'testTokenProviderCachesToken',
    'testOAuthClientCredentialsRefreshOnceAfter401',
    'testFinalEvent401IsNotRetriedInBackground',
    'testEventIdempotencyAliasesNormalizeToCanonicalField',
    'testOAuthIssuerErrorsDoNotExposeSecrets',
];

foreach ($tests as $test) {
    $test();
    fwrite(STDOUT, $test . " passed\n");
}

fwrite(STDOUT, "All PHP SDK tests passed\n");
