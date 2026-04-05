<?php

declare(strict_types=1);

namespace NeuronSearchLab;

final class SDKLogger
{
    private const LEVEL_VALUES = [
        'TRACE' => 10,
        'DEBUG' => 20,
        'INFO' => 30,
        'WARN' => 40,
        'ERROR' => 50,
        'FATAL' => 60,
    ];

    private const NETWORK_BODY_KEYS = [
        'requestBody' => true,
        'responseBody' => true,
    ];

    private static ?self $instance = null;

    private array $config = [];

    private int $levelValue = 30;

    private function __construct()
    {
        $this->config = [
            'level' => 'INFO',
            'enableNetworkBodyLogging' => false,
            'enablePerformanceLogging' => false,
            'transport' => static function (array $entry): void {
                $prefix = sprintf('[NeuronSDK][%s] %s', $entry['level'], $entry['message']);
                $line = $prefix;

                if (!empty($entry['context'])) {
                    $line .= ' ' . json_encode(
                        $entry['context'],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    );
                }

                $handle = fopen('php://stderr', 'wb');
                if ($handle !== false) {
                    fwrite($handle, $line . PHP_EOL);
                    fclose($handle);
                }
            },
            'redactKeys' => ['accessToken', 'authorization', 'Authorization'],
        ];
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function configure(array $config = []): void
    {
        $this->config = array_merge($this->config, $config);

        if (array_key_exists('level', $config)) {
            $this->levelValue = $this->toLevelValue($config['level']);
            $this->config['level'] = $this->levelValueToName($this->levelValue);
        }
    }

    public function shouldLog(string $level): bool
    {
        return $this->toLevelValue($level) >= $this->levelValue;
    }

    public function isPerformanceLoggingEnabled(): bool
    {
        return $this->config['enablePerformanceLogging'] && $this->shouldLog('DEBUG');
    }

    public function canLogNetworkPayloads(string $level): bool
    {
        return $this->config['enableNetworkBodyLogging']
            && $this->shouldLog($level)
            && $this->toLevelValue($level) <= $this->toLevelValue('DEBUG');
    }

    public function trace(string $message, ?array $context = null): void
    {
        $this->log('TRACE', $message, $context);
    }

    public function debug(string $message, ?array $context = null): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function info(string $message, ?array $context = null): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warn(string $message, ?array $context = null): void
    {
        $this->log('WARN', $message, $context);
    }

    public function error(string $message, ?array $context = null): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function fatal(string $message, ?array $context = null): void
    {
        $this->log('FATAL', $message, $context);
    }

    private function log(string $level, string $message, ?array $context = null): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        ($this->config['transport'])([
            'level' => $level,
            'levelValue' => $this->toLevelValue($level),
            'message' => $message,
            'timestamp' => gmdate(DATE_ATOM),
            'context' => $this->sanitizeContext($level, $context),
        ]);
    }

    private function sanitizeContext(string $level, ?array $context): ?array
    {
        if ($context === null) {
            return null;
        }

        $sanitized = [];

        foreach ($context as $key => $value) {
            if (in_array($key, $this->config['redactKeys'], true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (isset(self::NETWORK_BODY_KEYS[$key]) && !$this->canLogNetworkPayloads($level)) {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized === [] ? null : $sanitized;
    }

    private function toLevelValue(string|int $level): int
    {
        if (is_int($level)) {
            return $level;
        }

        return self::LEVEL_VALUES[$level] ?? self::LEVEL_VALUES['INFO'];
    }

    private function levelValueToName(int $value): string
    {
        foreach (self::LEVEL_VALUES as $name => $levelValue) {
            if ($levelValue === $value) {
                return $name;
            }
        }

        return 'INFO';
    }
}
