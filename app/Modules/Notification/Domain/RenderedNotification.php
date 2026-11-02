<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/**
 * Hasil render satu template: subject hanya untuk email, null untuk push. $data untuk
 * klien, bukan untuk dibaca orang (kartu 538): hanya pengirim push yang meneruskannya.
 */
final readonly class RenderedNotification
{
    /** @param  array<string, string>  $data */
    public function __construct(
        public ?string $subject,
        public string $body,
        public array $data = [],
    ) {}
}
