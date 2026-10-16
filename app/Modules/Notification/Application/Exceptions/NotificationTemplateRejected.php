<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Exceptions;

use App\Modules\Notification\Domain\NotificationChannel;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * Template yang tidak bisa dirender dengan jujur. Selalu bug kita (seeder atau
 * pemanggil), tidak pernah kesalahan klien API -- maka 500. Pesan yang salah lebih
 * buruk daripada pesan yang tidak terkirim: yang kedua terlihat di log dan bisa diulang.
 */
final class NotificationTemplateRejected extends DomainException
{
    public static function notFound(string $code, NotificationChannel $channel, string $locale): self
    {
        return new self("No active notification template \"{$code}\" for channel \"{$channel->value}\" and locale \"{$locale}\".");
    }

    /** @param  list<string>  $names */
    public static function missingVariables(string $code, array $names): self
    {
        return new self("Template \"{$code}\" is missing variables: ".implode(', ', $names).'.');
    }

    /** @param  list<string>  $names */
    public static function unexpectedVariables(string $code, array $names): self
    {
        return new self("Template \"{$code}\" was given undeclared variables: ".implode(', ', $names).'.');
    }

    /** @param  list<string>  $names */
    public static function undeclaredPlaceholders(string $code, array $names): self
    {
        return new self("Template \"{$code}\" contains placeholders not declared in its variables: ".implode(', ', $names).'.');
    }

    /** @param  list<string>  $names */
    public static function unusedVariables(string $code, array $names): self
    {
        return new self("Template \"{$code}\" declares variables its text never uses: ".implode(', ', $names).'.');
    }

    public static function malformedPlaceholder(string $code): self
    {
        return new self("Template \"{$code}\" contains a malformed placeholder.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::NOTIFICATION_TEMPLATE_REJECTED;
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
