<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Models\DeviceToken;

/** Mencabut token: menulis revoked_at, TIDAK PERNAH delete(). Idempoten. */
final class RevokeDeviceToken
{
    public function handle(DeviceToken $device): DeviceToken
    {
        if ($device->revoked_at === null) {
            $device->forceFill(['revoked_at' => now()])->save();
        }

        return $device;
    }
}
