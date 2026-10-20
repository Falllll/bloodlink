<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain\Rules;

use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\DonorFlagWindow;
use App\Modules\Donor\Domain\EligibilityFinding;
use App\Modules\Donor\Domain\EligibilityFindingCode;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Modules\Donor\Domain\EligibilityRule;
use DateTimeImmutable;

/**
 * Flag administratif aktif memblokir kelayakan dengan kodenya SENDIRI
 * (ACTIVE_ADMIN_FLAG), bukan ACTIVE_DEFERRAL: donor yang diblokir administratif
 * tidak boleh menerima alasan penolakan medis yang tidak pernah terjadi padanya.
 */
final readonly class DonorFlagRule implements EligibilityRule
{
    public function evaluate(
        DonorEligibilitySnapshot $snapshot,
        DateTimeImmutable $today,
    ): ?EligibilityFinding {
        $active = array_values(array_filter(
            $snapshot->flags,
            fn (DonorFlagWindow $flag): bool => $flag->isActiveOn($today),
        ));

        if ($active === []) {
            return null;
        }

        // Flag tanpa batas waktu tidak punya tanggal coba lagi; yang berbatas: yang terjauh.
        $retryAfterDate = null;
        foreach ($active as $flag) {
            if ($flag->endsAt === null) {
                $retryAfterDate = null;
                break;
            }

            if ($retryAfterDate === null || $flag->endsAt > $retryAfterDate) {
                $retryAfterDate = $flag->endsAt;
            }
        }

        return new EligibilityFinding(
            outcome: EligibilityOutcome::NOT_ELIGIBLE,
            code: EligibilityFindingCode::ACTIVE_ADMIN_FLAG,
            retryAfterDate: $retryAfterDate,
        );
    }
}
