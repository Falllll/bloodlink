<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

/**
 * Alasan sanksi ADMINISTRATIF donor (kartu 430). Bukan alasan medis: yang medis
 * tinggal di deferral_reasons yang bersumber WHO. Nilai harus sama dengan enum
 * kolom donor_flags.reason.
 */
enum DonorFlagReason: string
{
    case IDENTITY_FRAUD = 'identity_fraud';
    case REPEATED_NO_SHOW = 'repeated_no_show';
    case STAFF_SAFETY = 'staff_safety';
    case OTHER = 'other';
}
