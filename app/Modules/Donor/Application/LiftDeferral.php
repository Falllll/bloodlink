<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Deferral;
use App\Modules\Donor\Application\Exceptions\DeferralConflict;
use App\Modules\Donor\Domain\DeferralType;
use Illuminate\Support\Facades\DB;

final class LiftDeferral
{
    public function handle(Deferral $deferral, int $liftedBy, string $reason): Deferral
    {
        if ($deferral->lifted_at !== null) {
            throw DeferralConflict::alreadyLifted();
        }

        if ($deferral->type === DeferralType::PERMANENT) {
            throw DeferralConflict::permanentNotLiftable();
        }

        return DB::transaction(function () use ($deferral, $liftedBy, $reason): Deferral {
            $deferral->forceFill([
                'lifted_at' => now(),
                'lifted_by' => $liftedBy,
                'lift_note' => $reason,
            ])->save();

            return $deferral;
        });
    }
}
