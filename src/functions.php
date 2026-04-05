<?php

declare(strict_types=1);

namespace NeuronSearchLab;

function logger(): SDKLogger
{
    return SDKLogger::instance();
}

function configureLogger(array $config = []): void
{
    SDKLogger::instance()->configure($config);
}
