<?php

namespace App\Models;

use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donor extends Model implements FacilityScoped
{
    /** @use HasFactory<DonorFactory> */
    use Auditable, HasFactory, ScopedToFacility, SoftDeletes;

    protected $fillable = [
        'full_name',
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
            'deferred_until' => 'date',
            'is_deferred' => 'boolean',
            'weight_kg' => 'decimal:2',
            'phone' => 'encrypted',
            'email' => 'encrypted',
            'address' => 'encrypted',
        ];
    }

    /**
     * Kolom terenkripsi tidak boleh masuk diff audit (ciphertext bocor ke audit_logs).
     *
     * @return list<string>
     */
    public function auditExcept(): array
    {
        return ['updated_at', 'remember_token', 'phone', 'email', 'address', 'phone_hash'];
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

    protected static function booted(): void
    {
        static::saving(function (self $donor): void {
            if ($donor->isDirty('phone')) {
                $donor->phone_hash = self::phoneHash($donor->phone);
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
