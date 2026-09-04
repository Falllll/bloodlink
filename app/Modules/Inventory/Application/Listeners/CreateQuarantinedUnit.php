<?php

namespace App\Modules\Inventory\Application\Listeners;

use App\Modules\Donor\Domain\Events\DonationCompleted;

final class CreateQuarantinedUnit
{
    public function handle(DonationCompleted $event): void
    {
        logger()->info('Inventory menerima donation.completed', [
            'donation_id' => $event->donationId,
        ]);
    }
}
