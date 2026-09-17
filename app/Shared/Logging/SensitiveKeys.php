<?php

declare(strict_types=1);

namespace App\Shared\Logging;

final class SensitiveKeys
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    public const KEYS = [
        'password', 'password_confirmation', 'token', 'access_token',
        'refresh_token', 'authorization', 'secret', 'nik',
        'hemoglobin', 'lab_result',
    ];

    public static function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::KEYS as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }
}
