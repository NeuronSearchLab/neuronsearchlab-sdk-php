# neuronsearchlab/sdk-php

Official PHP SDK for the NeuronSearchLab API. It exposes a single `NeuronSDK` class (track events, upsert items, patch and delete items, request recommendations) plus `configureLogger()` for opt-in diagnostic output.

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
    'baseUrl' => 'https://api.neuronsearchlab.com',
    'accessToken' => getenv('NEURON_API_TOKEN'),
    'collateWindowSeconds' => 3,
    'maxBatchSize' => 200,
    'maxBufferedEvents' => 5000,
]);

$contentId = 3187;

$sdk->trackEvent([
    'eventId' => 1,
    'userId' => '42',
    'itemId' => $contentId,
    'metadata' => ['action' => 'view'],
])->wait();

$sdk->upsertItem([
    'itemId' => $contentId,
    'name' => 'Premier League Highlights',
    'description' => 'Matchday recap',
    'metadata' => ['league' => 'EPL'],
]);

$sdk->patchItem(['itemId' => $contentId, 'active' => true]);
$sdk->deleteItems(['itemId' => $contentId]);

$recs = $sdk->getRecommendations(['userId' => '42', 'limit' => 5]);
```

## Notes

- The package now targets PHP 8.4+.
- PHP does not have browser lifecycle hooks, so event batching is process-local. Buffered events flush when `flushEvents()` is called, when the batch limit is reached, when an older buffer exceeds the collate window on a later `trackEvent()`, or automatically at shutdown.
- `trackEvent()` returns a `PendingResult`; call `->wait()` to force delivery and surface any transport error immediately.
- Request ID propagation, session ID handling, array-batch fallback, and retry behavior mirror the TypeScript SDK as closely as PHP’s synchronous runtime allows.

## Release Flow

- CI runs on pushes and pull requests against PHP 8.4.
- Publishing is handled by `.github/workflows/publish.yml` when you push a `v*` tag.
- If you want Packagist refreshes from GitHub Actions, add `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` repo secrets.

## License

MIT
