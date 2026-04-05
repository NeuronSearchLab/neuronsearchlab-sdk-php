<?php

declare(strict_types=1);

namespace NeuronSearchLab;

use Throwable;

final class PendingResult
{
    private ?NeuronSDK $sdk;

    private bool $settled = false;

    private mixed $value = null;

    private ?Throwable $error = null;

    public function __construct(?NeuronSDK $sdk = null)
    {
        $this->sdk = $sdk;
    }

    public function wait(): mixed
    {
        if (!$this->settled && $this->sdk !== null) {
            $this->sdk->flushEvents();
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    public function resolve(mixed $value): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->value = $value;
        $this->sdk = null;
    }

    public function reject(Throwable $error): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->error = $error;
        $this->sdk = null;
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }
}
