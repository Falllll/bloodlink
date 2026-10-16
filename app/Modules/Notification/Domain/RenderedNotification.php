<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/** Hasil render satu template: subject hanya untuk email, null untuk push. */
final readonly class RenderedNotification
{
    public function __construct(
        public ?string $subject,
        public string $body,
    ) {}
}
