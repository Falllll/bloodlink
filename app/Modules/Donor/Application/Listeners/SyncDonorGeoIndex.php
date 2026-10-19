<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application\Listeners;

use App\Modules\Donor\Domain\Events\DonorLocationChanged;
use App\Modules\Donor\Infrastructure\DonorGeoIndex;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write-through ke indeks GEO. Sengaja sinkron, BUKAN ShouldQueue: cache yang
 * tertinggal di antrean basi tepat saat dipakai. Redis mati TIDAK BOLEH
 * menggagalkan penyimpanan data donor -- kegagalan dicatat, lalu ditambal oleh
 * donor:reconcile-geo.
 */
final class SyncDonorGeoIndex
{
    public function __construct(private readonly DonorGeoIndex $index) {}

    public function handle(DonorLocationChanged $event): void
    {
        if (! $this->index->enabled()) {
            return;
        }

        try {
            if ($event->latitude === null || $event->longitude === null) {
                $this->index->forget($event->donorId);

                return;
            }

            $this->index->put($event->donorId, $event->latitude, $event->longitude);
        } catch (Throwable $e) {
            Log::warning('Donor geo index sync failed; donor:reconcile-geo will repair it.', [
                'donor_id' => $event->donorId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
