<?php

declare(strict_types=1);

use App\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Didaftarkan lewat withBroadcasting(..., ['middleware' => ['auth:sanctum']]) di
// bootstrap/app.php -- BUKAN lewat withRouting(channels: ...), yang memasang 'web'.

// Satu channel per fasilitas (kartu 490). Petugas tanpa fasilitas (operator global)
// TIDAK diberi akses di sini: ia tidak punya satu layar operasional pun, dan
// memberinya semua fasilitas berarti satu langganan yang membaca segalanya.
Broadcast::channel('facility.{facilityPublicId}', function (User $user, string $facilityPublicId): bool {
    $facilityId = $user->facilityId();

    if ($facilityId === null) {
        return false;
    }

    return Facility::query()->whereKey($facilityId)->value('public_id') === $facilityPublicId;
});
