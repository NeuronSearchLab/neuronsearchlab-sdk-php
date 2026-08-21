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
    'accessToken' => getenv('NEURON_API_TOKEN'),
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

## Notes

- The package now targets PHP 8.4+.
- PHP does not have browser lifecycle hooks, so event batching is process-local. Buffered events flush when `flushEvents()` is called, when the batch limit is reached, when an older buffer exceeds the collate window on a later `trackEvent()`, or automatically at shutdown.
- `trackEvent()` returns a `PendingResult`; call `->wait()` to force delivery and surface any transport error immediately.
- Request ID propagation, session ID handling, array-batch fallback, and retry behavior mirror the TypeScript SDK as closely as PHP’s synchronous runtime allows.
- `search()` posts to the public Core API `/v1/search` AWS API Gateway endpoint. It does not call the console Platform API.

## Release Flow

- CI runs on pushes and pull requests against PHP 8.4.
- Packagist is already connected to the GitHub repository and auto-updates from pushes.
- Pushes to `main` refresh `dev-main` on Packagist, and pushed `v*` git tags become installable versioned releases.
- `.github/workflows/publish.yml` runs validation and tests on tag pushes before or alongside those releases.

## License

MIT
