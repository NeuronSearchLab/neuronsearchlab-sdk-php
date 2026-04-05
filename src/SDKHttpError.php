<?php

declare(strict_types=1);

namespace NeuronSearchLab;

use RuntimeException;
use Throwable;

final class SDKHttpError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $statusText,
        public readonly array|string|null $body = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }
}
