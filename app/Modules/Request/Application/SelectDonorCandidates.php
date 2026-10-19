<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\Facility;
use App\Modules\Request\Domain\DonorCandidate;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Matching\DonorDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Siapa yang boleh DAN bisa dipanggil untuk satu permintaan pada SATU radius
 * (kartu 410). Eskalasi radius seiring waktu milik kampanye (kartu 400); kelas ini
 * tidak mengurus waktu dan tidak menyimpan apa pun.
 *
 * Nol aturan medis di sini: kompatibilitas dari CheckBloodCompatibility,
 * kelayakan dari DonorEligibilityService (lewat port DonorDirectory).
 * Urutan murni jarak -- tanpa skor, tanpa pembobotan.
 */
final class SelectDonorCandidates
{
    /**
     * Ambil lebih banyak dari batas kirim karena sebagian gugur di lapis kelayakan.
     * Angka OPERASIONAL, bukan medis.
     */
    private const int OVERFETCH_FACTOR = 3;

    public function __construct(
        private readonly CheckBloodCompatibility $compatibility,
        private readonly DonorDirectory $donors,
    ) {}

    /**
     * @param  list<int>  $excludeDonorIds  donor yang sudah dihubungi (kartu 400)
     * @return list<DonorCandidate>
     */
    public function handle(BloodRequest $request, Facility $origin, int $radiusMeters, array $excludeDonorIds = []): array
    {
        // Tanpa aturan bersumber (trombosit, Rujukan §12.6) tidak ada donor yang sah.
        $direction = CheckBloodCompatibility::directionFor($request->component);

        if ($direction === null) {
            return [];
        }

        $point = DB::table('facilities')
            ->where('id', $origin->id)
            ->whereNotNull('location')
            ->selectRaw('ST_Y(location::geometry) AS latitude, ST_X(location::geometry) AS longitude')
            ->first();

        // Bantuan, bukan syarat (alasan sama dengan kartu 380): koordinat kosong -> kosong.
        if ($point === null) {
            return [];
        }

        // Arah DONOR -> PASIEN: check(recipient = pasien permintaan, donor = kandidat).
        // Pasangan diturunkan dari gerbang, tidak pernah ditulis di sini.
        $pairs = [];
        foreach (BloodGroup::cases() as $group) {
            foreach (RhFactor::cases() as $rh) {
                if ($this->compatibility->check($direction, $request->blood_group, $request->rh_factor, $group, $rh)->isCompatible()) {
                    $pairs[] = ['group' => $group->value, 'rh' => $rh->value];
                }
            }
        }

        $max = (int) config('notification.max_recipients_per_campaign');

        // Batas diterapkan SEBELUM penilaian kelayakan: N terdekat dulu, baru dinilai.
        $nearby = $this->donors->nearest(
            (float) $point->latitude,
            (float) $point->longitude,
            $radiusMeters,
            $pairs,
            $excludeDonorIds,
            $max * self::OVERFETCH_FACTOR,
        );

        $ids = array_map(fn ($donor): int => $donor->donorId, $nearby);
        $reasons = $this->donors->ineligibilityReasons($ids);
        $reachable = $this->donors->reachability($ids);

        $candidates = [];
        foreach ($nearby as $donor) {
            // array_key_exists, BUKAN ??: null di sini berarti LAYAK, dan ?? akan
            // memperlakukannya sebagai kunci yang hilang. Donor tanpa penilaian sama
            // sekali gagal-tertutup jadi tidak layak.
            $reason = array_key_exists($donor->donorId, $reasons) ? $reasons[$donor->donorId] : 'NOT_ASSESSED';

            $candidates[] = new DonorCandidate(
                $donor->donorPublicId,
                $donor->distanceMeters,
                $reason === null,
                $reason,
                $reachable[$donor->donorId] ?? false,
            );
        }

        return array_slice($candidates, 0, $max);
    }
}
