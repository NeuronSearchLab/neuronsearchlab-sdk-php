# neuronsearchlab/sdk-php

Official PHP SDK for the NeuronSearchLab Core API. It exposes a single `NeuronSDK` class for recommendation systems work: track events, sync catalogue items, patch and delete items, request personalized recommendations, run product/content search, and preserve request attribution for ranking quality analysis.

## Installation

```bash
composer require neuronsearchlab/sdk-php
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use NeuronSearchLab\NeuronSDK;
use function NeuronSearchLab\configureLogger;

configureLogger(['level' => 'INFO']);

$sdk = new NeuronSDK([
    'baseUrl' => 'https://api.neuronsearchlab.com/v1',
    'oauthClientCredentials' => [
        'clientId' => getenv('NSL_CLIENT_ID'),
        'clientSecret' => getenv('NSL_CLIENT_SECRET'),
    ],
    'collateWindowSeconds' => 3,
    'maxBatchSize' => 200,
    'maxBufferedEvents' => 5000,
]);

$item = $sdk->upsertItem([
    'name' => 'Premier League Highlights',
    'description' => 'Matchday recap',
    'metadata' => ['league' => 'EPL'],
]);
$itemId = $item['id']; // NSL-generated integer; persist this mapping

$sdk->trackEvent([
    'eventId' => 42, // from dashboard Event configuration
    'userId' => '42',
    'itemId' => $itemId,
    'contextId' => 101,
    'metadata' => ['action' => 'view'],
])->wait();

$sdk->patchItem(['itemId' => $itemId, 'active' => true]);
$sdk->deleteItems(['itemId' => $itemId]);

$recs = $sdk->getRecommendations([
    'userId' => '42',
    'contextId' => 101,
    'limit' => 5,
]);

$results = $sdk->search([
    'query' => 'latest football highlights',
    'userId' => '42',
    'contextId' => 101,
    'limit' => 5,
    'filter' => ['category:sports'],
]);
```

## Resources

- Docs hub: https://docs.neuronsearchlab.com
- PHP SDK guide: https://docs.neuronsearchlab.com/sdk/php
- Recommendation systems reading path: https://www.neuronsearchlab.com/blog/recommendation-systems

## Server authentication

Configure exactly one authentication method: `accessToken`, `tokenProvider`, or `oauthClientCredentials`.

Client credentials are recommended for long-running server integrations. The SDK exchanges them for short-lived access tokens, caches tokens in memory, refreshes before expiry, and performs exactly one forced refresh and retry after an API `401`.

```php
$sdk = new NeuronSDK([
    'baseUrl' => 'https://api.neuronsearchlab.com/v1',
    'oauthClientCredentials' => [
        'clientId' => getenv('NSL_CLIENT_ID'),
        'clientSecret' => getenv('NSL_CLIENT_SECRET'),
        // Hosted NSL defaults; omit unless using a custom issuer.
        'tokenUrl' => 'https://auth.neuronsearchlab.com/oauth2/token',
        'scope' => [
            'neuronsearchlab-api/read',
            'neuronsearchlab-api/write',
        ],
    ],
]);
```

Keep client credentials in a server secret manager. Never expose them to browser, mobile, desktop, or other user-distributed code.

Existing static access tokens remain supported:

```php
$sdk = new NeuronSDK([
    'baseUrl' => 'https://api.neuronsearchlab.com/v1',
    'accessToken' => getenv('NSL_ACCESS_TOKEN'),
]);
```

If your application owns token acquisition, provide a callable. Results are cached; include expiry metadata for proactive refresh. The provider receives `forceRefresh => true` after a `401` and should bypass any cache it owns.

```php
$sdk = new NeuronSDK([
    'baseUrl' => 'https://api.neuronsearchlab.com/v1',
    'tokenProvider' => static function (array $context) use ($auth): array {
        $token = $auth->accessToken(forceRefresh: $context['forceRefresh']);

        return [
            'accessToken' => $token->value,
            'expiresAt' => $token->expiresAt, // DateTimeInterface or Unix epoch
        ];
    },
]);
```

`tokenExpirySkewMs` defaults to 60 seconds and is capped at half the issued token lifetime.

## Retry-safe event ingestion

Supply a stable, caller-owned `deduplicationId` when an event might be retried. Generate and persist this value with the source event so a process or queue retry reuses it; the SDK intentionally does not generate one in memory.

```php
$sdk->trackEvent([
    'eventId' => 44,
    'userId' => '42',
    'itemId' => 3187,
    'deduplicationId' => 'order-991-line-1-purchased',
])->wait();
```

The aliases `deduplication_id`, `idempotencyKey`, `idempotency_key`, `messageId`, and `message_id` are accepted and serialized only as `deduplication_id`.

## Searches steer recommendations

Every search is recorded as an event on your Search event type and weighs into that user's later recommendations by its weight, exactly as a click or a purchase does. The results you send are kept as impressions, not as items the user chose.

```php
// NSL runs the search.
$sdk->search(['query' => 'waterproof trail shoes', 'userId' => 'user-123']);

// Your engine ran it: record it with the ids it showed, and get
// recommendations that complement them (those ids are left out).
$extras = $sdk->search([
    'query' => 'waterproof trail shoes',
    'userId' => 'user-123',
    'resultItemIds' => [1042, 1077, 1013],
]);

// Record only. eventId is optional and defaults to your Search event.
$sdk->trackSearch([
    'userId' => 'user-123',
    'query' => 'waterproof trail shoes',
    'resultItemIds' => [1042, 1077],
])->wait();
```

When searches steered a `getRecommendations()` response it carries `search_intent`, with their share of the user's recent event weight and the queries involved.

## Notes

- The package supports PHP 8.2 and newer maintained PHP 8.x releases; CI exercises PHP 8.2, 8.3, 8.4, and 8.5.
- PHP does not have browser lifecycle hooks, so event batching is process-local. Buffered events flush when `flushEvents()` is called, when the batch limit is reached, when an older buffer exceeds the collate window on a later `trackEvent()`, or automatically at shutdown.
- `trackEvent()` returns a `PendingResult`; call `->wait()` to force delivery and surface any transport error immediately.
- Request ID propagation, session ID handling, array-batch fallback, and retry behavior mirror the TypeScript SDK as closely as PHP’s synchronous runtime allows.
- `search()` posts to the public Core API `/v1/search` AWS API Gateway endpoint. It does not call the console Platform API.

## Release Flow

- CI runs on pushes and pull requests against PHP 8.2, 8.3, 8.4, and 8.5.
- Packagist is already connected to the GitHub repository and auto-updates from pushes.
- Pushes to `main` refresh `dev-main` on Packagist, and pushed `v*` git tags become installable versioned releases.
- `.github/workflows/publish.yml` runs validation and tests on tag pushes before or alongside those releases.

## License

MIT
