<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Models\DeviceToken;
use App\Modules\Identity\Domain\Events\PasswordWasReset;
use App\Modules\Identity\Domain\Events\StaffAccountDeactivated;
use App\Modules\Notification\Application\RevokeDeviceToken;

/**
 * Mencabut setiap device token push milik satu akun. Dipicu dua peristiwa Identity:
 * password direset (kartu 485) dan akun staf dinonaktifkan (kartu 487). Sinkron, di
 * transaksi pemicunya: yang gagal tidak mencabut apa pun, yang berhasil tidak pernah
 * meninggalkan token hidup.
 */
final class RevokeUserDevices
{
    public function __construct(private readonly RevokeDeviceToken $revoke) {}

    public function handle(PasswordWasReset|StaffAccountDeactivated $event): void
    {
        DeviceToken::query()
            ->where('user_id', $event->userId)
            ->whereNull('revoked_at')
            ->get()
            ->each(fn (DeviceToken $device): DeviceToken => $this->revoke->handle($device));
    }
}
