<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\LogRecord;

/**
 * Monolog processor that injects a correlation ID into every log record.
 *
 * Correlation IDs are set externally:
 *   - HTTP requests: CorrelationIdSubscriber generates one on kernel.request
 *   - Messenger handlers: Each handler generates one before processing
 *
 * The processor safely falls back to 'no-request' when no correlation
 * ID has been explicitly set (e.g. CLI commands, dev环境下).
 */
final class CorrelationIdProcessor
{
    private static ?string $correlationId = null;

    public static function setCorrelationId(string $id): void
    {
        self::$correlationId = $id;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['correlation_id'] = self::$correlationId ?? 'no-request';

        return $record;
    }
}
