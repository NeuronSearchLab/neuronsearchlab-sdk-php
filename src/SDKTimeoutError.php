<?php

declare(strict_types=1);

namespace NeuronSearchLab;

use RuntimeException;
use Throwable;

final class SDKTimeoutError extends RuntimeException
{
    public function __construct(public readonly int $timeoutMs, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Request timed out after %d ms', $timeoutMs), 0, $previous);
    }
}
