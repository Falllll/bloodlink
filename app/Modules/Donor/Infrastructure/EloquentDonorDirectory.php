<?php

declare(strict_types=1);

namespace App\Modules\Donor\Infrastructure;

use App\Models\DeviceToken;
use App\Models\Donor;
use App\Models\NotificationPreference;
use App\Modules\Donor\Application\DonorEligibilityService;
use App\Modules\Donor\Domain\EligibilityDecision;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Shared\Matching\DonorDirectory;
use App\Shared\Matching\NearbyDonor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Implementasi DonorDirectory (kartu 410) di atas komponen Donor yang sudah ada:
 * radius lewat DonorLocationQuery (ST_DWithin di WHERE, ST_Distance hanya di
 * SELECT/ORDER BY -- index donors_location_gist tetap terpakai), kelayakan lewat
 * DonorEligibilityService::forMany(). Tidak ada aturan medis yang ditulis di sini.
 *
 * SENGAJA tanpa visibleTo(): donor dicari menurut kedekatannya ke fasilitas
 * peminta, bukan menurut fasilitas tempat ia terdaftar. Yang keluar hanya id,
 * public_id, dan jarak.
 */
final class EloquentDonorDirectory implements DonorDirectory
{
    public function __construct(
        private readonly DonorLocationQuery $locations,
        private readonly DonorEligibilityService $eligibility,
    ) {}

    public function nearest(
        float $latitude,
        float $longitude,
        int $radiusMeters,
        array $acceptablePairs,
        array $excludeDonorIds,
        int $limit,
    ): array {
        $rows = $this->locations->withinRadiusOrderedByDistance($latitude, $longitude, $radiusMeters)
            ->whereNull('donors.deleted_at')
            // Baris hasil merge adalah batu nisan (ResolveDonorForUser, FindSimilarDonors):
            // MergeDonors tidak men-soft-delete sumber, dan deferral-nya sudah pindah ke
            // target -- tanpa filter ini batu nisan tampil LAYAK dan dipanggil dua kali.
            ->whereNull('donors.merged_into_id')
            ->where(function (Builder $q) use ($acceptablePairs): void {
                // Daftar kosong berarti tidak ada yang sah: WHERE FALSE, bukan "semua".
                $q->whereRaw('FALSE');

                foreach ($acceptablePairs as $pair) {
                    $q->orWhere(fn (Builder $p) => $p
                        ->where('donors.blood_group', $pair['group'])
                        ->where('donors.rh_factor', $pair['rh']));
                }
            })
            ->when($excludeDonorIds !== [], fn (Builder $q) => $q->whereNotIn('donors.id', $excludeDonorIds))
            ->orderBy('donors.id')
            ->limit($limit)
            ->get();

        $nearby = [];
        foreach ($rows as $row) {
            $nearby[] = new NearbyDonor((int) $row->id, (string) $row->public_id, (int) round((float) $row->distance_meters));
        }

        return $nearby;
    }

    public function ineligibilityReasons(array $donorIds): array
    {
        if ($donorIds === []) {
            return [];
        }

        $donors = Donor::query()->whereKey($donorIds)->get();
        $decisions = $this->eligibility->forMany($donors);

        $reasons = [];
        foreach ($donors as $donor) {
            $reasons[$donor->id] = $this->reasonOf($decisions[$donor->public_id]);
        }

        return $reasons;
    }

    public function reachability(array $donorIds): array
    {
        if ($donorIds === []) {
            return [];
        }

        // Query tetap, berapa pun jumlah donornya. email terenkripsi, tapi NULL tetap NULL.
        $rows = DB::table('donors')->whereIn('id', $donorIds)
            ->select('id', 'user_id')
            ->selectRaw('email IS NOT NULL AS has_email')
            ->get();

        $userIds = $rows->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $withToken = $userIds === []
            ? []
            : array_flip(DeviceToken::query()->deliverable()->whereIn('user_id', $userIds)->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
        // Donor yang menolak panggilan darurat (kartu 488) KELUAR dari daftar panggilan:
        // punya alamat tidak sama dengan boleh dipanggil. Query ketiga, tetap tidak N+1.
        $optedOut = $userIds === []
            ? []
            : array_flip(NotificationPreference::query()->optedOutOfEmergencyCalls()->whereIn('user_id', $userIds)->pluck('user_id')->map(fn ($id): int => (int) $id)->all());

        $reachable = [];
        foreach ($rows as $row) {
            if ($row->user_id !== null && isset($optedOut[(int) $row->user_id])) {
                $reachable[(int) $row->id] = false;

                continue;
            }

            $reachable[(int) $row->id] = (bool) $row->has_email || ($row->user_id !== null && isset($withToken[(int) $row->user_id]));
        }

        return $reachable;
    }

    /** Gagal-tertutup: UNDETERMINED juga tidak layak, dengan kodenya sendiri. */
    private function reasonOf(EligibilityDecision $decision): ?string
    {
        if ($decision->outcome === EligibilityOutcome::ELIGIBLE) {
            return null;
        }

        foreach ($decision->findings as $finding) {
            if ($finding->outcome !== EligibilityOutcome::ELIGIBLE) {
                return $finding->code->value;
            }
        }

        return $decision->outcome->value;
    }
}
