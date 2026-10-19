<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain\Events;

/**
 * Satu tahap kampanye baru saja mencatat kontak (kartu 400). Pengirimannya milik
 * kartu 460. SENGAJA hanya public_id kampanye dan kontak -- bukan daftar donor,
 * bukan data kontak -- supaya payload antrean kelak tidak membawa data pribadi.
 */
final class CampaignDonorsContacted
{
    /** @param  list<string>  $contactIds */
    public function __construct(
        public readonly string $campaignId,
        public readonly array $contactIds,
    ) {}
}
