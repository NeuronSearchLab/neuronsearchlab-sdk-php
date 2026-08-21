<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NeuronSearchLab\NeuronSDK;

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

$tests = [
    'testBatchesEventsAndPreservesOrder',
    'testPropagatesRecommendationRequestIds',
    'testSearchPostsToCoreApiEndpointAndPropagatesRequestId',
    'testRetriesAfterFailure',
    'testAutoSessionIdIsAttached',
    'testWhitespaceRequestAndSessionIdsSuppressAutoPropagation',
    'testRecommendationResponsePreservesRawBodyShape',
];

foreach ($tests as $test) {
    $test();
    fwrite(STDOUT, $test . " passed\n");
}

fwrite(STDOUT, "All PHP SDK tests passed\n");
