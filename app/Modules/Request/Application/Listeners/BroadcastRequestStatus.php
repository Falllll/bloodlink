<?php

declare(strict_types=1);

namespace App\Modules\Request\Application\Listeners;

use App\Modules\Request\Domain\Events\BloodRequestStatusChanged;
use App\Modules\Request\Infrastructure\Broadcasting\RequestStatusBroadcast;

// Menerjemahkan event domain ke siaran (kartu 490). Penundaan sampai commit milik
// RequestStatusBroadcast (ShouldDispatchAfterCommit), bukan listener ini.
final class BroadcastRequestStatus
{
    public function handle(BloodRequestStatusChanged $event): void
    {
        broadcast(new RequestStatusBroadcast($event->requestId, $event->facilityId, $event->to));
    }
}
