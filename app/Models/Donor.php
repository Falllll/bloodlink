<?php

namespace App\Models;

use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property Carbon $date_of_birth
 * @property Carbon|null $last_donation_date
 */
class Donor extends Model implements FacilityScoped
{
    /** @use HasFactory<DonorFactory> */
    use Auditable, HasFactory, ScopedToFacility, SoftDeletes;

    protected $fillable = [
        'full_name',
        'nik',
        'date_of_birth',
        'sex',
        'blood_group',
        'rh_factor',
        'phone',
        'email',
        'address',
        'city',
        'weight_kg',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'last_donation_date' => 'date',
            'merged_at' => 'datetime',
            'weight_kg' => 'decimal:2',
            'phone' => 'encrypted',
            'email' => 'encrypted',
            'address' => 'encrypted',
            'nik' => 'encrypted',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'donor_number' => $this->donor_number,
            'full_name' => $this->full_name,
            'date_of_birth' => $this->date_of_birth->toDateString(),
            'sex' => $this->sex,
            'blood_group' => $this->blood_group,
            'rh_factor' => $this->rh_factor,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'weight_kg' => $this->weight_kg,
            'last_donation_date' => $this->last_donation_date?->toDateString(),
            'donation_count' => $this->donation_count,
            'has_nik' => $this->nik !== null,
        ];
    }

    /**
     * Kolom terenkripsi tidak boleh masuk diff audit (ciphertext bocor ke audit_logs).
     *
     * @return list<string>
     */
    public function auditExcept(): array
    {
        return ['updated_at', 'remember_token', 'phone', 'email', 'address', 'phone_hash', 'nik', 'nik_hash'];
    }

    /**
     * Blind index deterministik untuk pencarian/dedup nomor HP tanpa membuka ciphertext.
     * HMAC ber-key (bukan hash polos): ruang nomor HP kecil dan mudah di-brute force.
     */
    public static function phoneHash(string $phone): string
    {
        $normalized = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($normalized, '0')) {
            $normalized = '+62'.substr($normalized, 1);
        } elseif (str_starts_with($normalized, '62')) {
            $normalized = '+'.$normalized;
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /**
     * Blind index deterministik untuk pencarian/dedup NIK tanpa membuka ciphertext.
     * HMAC ber-key, dengan pola yang sama seperti phoneHash().
     */
    public static function nikHash(string $nik): string
    {
        $normalized = preg_replace('/\D/', '', $nik) ?? '';

        if (strlen($normalized) !== 16) {
            throw new InvalidArgumentException('NIK must be exactly 16 digits.');
        }

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * @return HasMany<Deferral, $this>
     */
    public function deferrals(): HasMany
    {
        return $this->hasMany(Deferral::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $donor): void {
            if ($donor->isDirty('phone')) {
                $donor->phone_hash = self::phoneHash($donor->phone);
            }

            if ($donor->isDirty('nik')) {
                $donor->nik_hash = $donor->nik === null ? null : self::nikHash($donor->nik);
            }
        });
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'registered_facility_id');
    }

    public function facilityColumn(): string
    {
        return 'registered_facility_id';
    }
}
