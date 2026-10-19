<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Jawaban donor atas panggilan. ACCEPTED berarti bersedia datang -- BUKAN berarti
 * darahnya sudah ada: alur donasi normal tetap berlaku, dan jawaban ini tidak
 * pernah mengalokasikan atau membuat unit apa pun.
 */
enum CampaignResponse: string
{
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
}
