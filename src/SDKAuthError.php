<?php

declare(strict_types=1);

namespace NeuronSearchLab;

use RuntimeException;
use Throwable;

final class SDKAuthError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
