<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Donor ini sudah pernah dipanggil untuk permintaan ini" (kartu 480). Ditulis
 * HANYA lewat ClaimDonorCalls (INSERT ... ON CONFLICT DO NOTHING), tidak pernah
 * lewat Eloquent create().
 *
 * Catatan internal: SENGAJA tanpa public_id dan tanpa toApiArray(). Baris ini tidak
 * pernah keluar ke API -- jangan menambah serializer "demi konsistensi".
 *
 * Tidak dihapus saat kampanye selesai: ia catatan, bukan lock sementara. Dibersihkan
 * notification:prune-claims setelah permintaannya selesai dan lewat masa retensi.
 *
 * @property int $id
 * @property int $blood_request_id
 * @property int $donor_id
 * @property int $radius_meters
 * @property Carbon $claimed_at
 */
final class DonorCallClaim extends Model
{
    protected $fillable = [];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['claimed_at' => 'datetime'];
    }
}
