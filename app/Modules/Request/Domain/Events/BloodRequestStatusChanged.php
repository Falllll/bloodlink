<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain\Events;

/**
 * Status satu permintaan berpindah (kartu 490). SENGAJA hanya public_id dan dua
 * status -- transport-nya urusan listener, bukan urusan event ini.
 *
 * Status sebagai string, bukan RequestStatus: layer EvtRequest tidak boleh
 * bergantung pada DomRequest (deptrac), dan nilainya memang string di database.
 */
final class BloodRequestStatusChanged
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $facilityId,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
