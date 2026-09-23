<?php

declare(strict_types=1);

namespace App\Modules\Donor\Infrastructure;

use App\Models\DeferralReason;
use App\Models\Donor;
use App\Modules\Donor\Application\PlaceDeferral;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\Events\DonorPermanentlyDeferred;
use DateTimeImmutable;

final class RecordPermanentDeferral
{
    public function __construct(private readonly PlaceDeferral $placeDeferral) {}

    public function handle(DonorPermanentlyDeferred $event): void
    {
        $donor = Donor::query()->findOrFail($event->donorId);

        $reason = DeferralReason::query()
            ->where('jurisdiction', 'WHO')
            ->where('code', 'TTI_CONFIRMED_REACTIVE')
            ->firstOrFail();

        $this->placeDeferral->handle(
            $donor,
            $reason,
            new DateTimeImmutable($event->occurredAt),
            DeferralSource::TTI_RESULT,
        );
    }
}
