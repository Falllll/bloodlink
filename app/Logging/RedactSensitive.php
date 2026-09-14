<?php

declare(strict_types=1);

namespace App\Logging;

use App\Shared\Logging\SensitiveKeys;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class RedactSensitive
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            context: $this->redact($record->context),
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            if (SensitiveKeys::isSensitive((string) $key)) {
                $redacted[$key] = SensitiveKeys::REDACTED;

                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }
}
