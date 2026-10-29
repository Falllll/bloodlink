<?php

declare(strict_types=1);

namespace App\Modules\Request\Application\Listeners;

use App\Modules\Request\Domain\Events\DonorResponded;
use App\Modules\Request\Infrastructure\Broadcasting\DonorResponseBroadcast;

// Menerjemahkan event domain ke siaran (kartu 500). Penundaan sampai commit milik
// DonorResponseBroadcast (ShouldDispatchAfterCommit), bukan listener ini.
final class BroadcastDonorResponse
{
    public function handle(DonorResponded $event): void
    {
        broadcast(new DonorResponseBroadcast($event->campaignId, $event->bloodRequestId, $event->facilityId, $event->response));
    }
}
