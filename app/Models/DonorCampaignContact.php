<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Request\Domain\CampaignResponse;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu donor yang dihubungi dalam satu kampanye. Ada barisnya berarti pesan dikirim
 * ke orang ini. Paling banyak satu baris per (kampanye, donor).
 *
 * @property int $id
 * @property string $public_id
 * @property int $donor_campaign_id
 * @property int $donor_id
 * @property int $radius_meters
 * @property int $distance_meters
 * @property Carbon $contacted_at
 * @property Carbon|null $responded_at
 * @property CampaignResponse|null $response
 */
final class DonorCampaignContact extends Model
{
    use Auditable;

    protected $fillable = [];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'radius_meters' => 'integer',
            'distance_meters' => 'integer',
            'contacted_at' => 'datetime',
            'responded_at' => 'datetime',
            'response' => CampaignResponse::class,
        ];
    }

    /** @return BelongsTo<DonorCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DonorCampaign::class, 'donor_campaign_id');
    }
}
