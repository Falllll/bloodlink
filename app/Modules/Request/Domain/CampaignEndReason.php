<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/** Kenapa kampanye panggilan donor berhenti (kartu 400). Selalu dicatat bersama ended_at. */
enum CampaignEndReason: string
{
    case REQUEST_FULFILLED = 'request_fulfilled';
    case REQUEST_CANCELLED = 'request_cancelled';
    case REQUEST_EXPIRED = 'request_expired';
    case STAGES_EXHAUSTED = 'stages_exhausted';
    case STOPPED_MANUALLY = 'stopped_manually';
}
