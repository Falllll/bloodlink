<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DonorConsent extends Model
{
    use Auditable;

    // facility_id sengaja tidak di sini: pemilik fasilitas disetel server lewat forceFill()
    // di RecordDonorConsent, sejalan dengan larangan mass-assignment pemilik fasilitas (Kartu 72).
    protected $fillable = [
        'donor_id', 'questionnaire_version',
        'purposes', 'granted_at', 'recorded_by', 'ip',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'purposes' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
