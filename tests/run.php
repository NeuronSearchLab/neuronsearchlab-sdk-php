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

    $pendingOne = $sdk->trackEvent(['type' => 'view', 'userId' => 'u1', 'itemId' => 'itm_i1']);
    $pendingTwo = $sdk->trackEvent(['type' => 'click', 'userId' => 'u1', 'itemId' => 'itm_i2']);

    $sdk->flushEvents();

    expectSame(1, count($requests), 'Expected one batched event request.');

    $body = json_decode($requests[0]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    expect(is_array($body), 'Expected array event payload.');
    expectSame(2, count($body), 'Expected two events in the batch.');
    expectSame('view', $body[0]['type'], 'Expected first event to preserve order.');
    expectSame('u1', $body[0]['user_id'], 'Expected first event user_id.');
    expectSame('itm_i1', $body[0]['item_id'], 'Expected first event item_id.');
    expectSame('click', $body[1]['type'], 'Expected second event to preserve order.');
    expectSame('itm_i2', $body[1]['item_id'], 'Expected second event item_id.');
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
    $sdk->trackEvent(['type' => 'view', 'userId' => 'u1', 'itemId' => 'itm_i3'])->wait();

    expectSame(2, count($requests), 'Expected one recommendation call and one event call.');
    $body = json_decode($requests[1]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    $event = is_array($body) && array_is_list($body) ? $body[0] : $body;
    expectSame('req-123', $event['request_id'] ?? null, 'Expected propagated request_id.');
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

    $sdk->trackEvent(['type' => 'view', 'userId' => 'u1', 'itemId' => 'itm_i5'])->wait();

    expectSame(2, $attempts, 'Expected a retry after the first network failure.');
    $body = json_decode($requests[count($requests) - 1]['init']['body'], true, 512, JSON_THROW_ON_ERROR);
    $event = is_array($body) && array_is_list($body) ? $body[0] : $body;
    expectSame('view', $event['type'], 'Expected the retried event payload.');
    expectSame('itm_i5', $event['item_id'], 'Expected the retried item_id.');
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
    $sdk->trackEvent(['type' => 'view', 'userId' => 'u1', 'itemId' => 'itm_i6'])->wait();

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
        'type' => 'view',
        'userId' => 'u1',
        'itemId' => 'itm_i7',
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
