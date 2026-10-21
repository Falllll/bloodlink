<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\DonorCampaign;
use App\Modules\Request\Domain\DonorCandidate;
use App\Modules\Request\Domain\Events\CampaignDonorsContacted;
use App\Shared\Matching\DonorCallGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menghubungi satu tahap kampanye (kartu 400): kandidat dari SelectDonorCandidates
 * (kartu 410) pada radius tahap sekarang, MINUS yang sudah pernah dipanggil untuk
 * permintaan ini -- dijaga DonorCallGuard (kartu 480), bukan oleh daftar di PHP.
 *
 * DIPANGGIL DI DALAM transaksi pemanggil yang sudah memegang kunci kampanye.
 */
final class ContactCampaignStage
{
    public function __construct(
        private readonly SelectDonorCandidates $select,
        private readonly DonorCallGuard $calls,
    ) {}

    /** @return int jumlah donor yang baru dihubungi */
    public function handle(DonorCampaign $campaign): int
    {
        /** @var BloodRequest $request */
        $request = BloodRequest::query()->with('facility')->findOrFail($campaign->blood_request_id);

        // Yang sudah dipanggil untuk PERMINTAAN ini, lintas kampanye (kartu 480) --
        // bukan hanya kampanye ini. Ini penyaring supaya slot max_recipients terisi
        // donor baru; PENJAGANYA adalah klaim di bawah, bukan daftar ini.
        $contacted = $this->calls->alreadyClaimed($request->id);

        // Hanya yang layak DAN bisa dihubungi: baris kontak berarti "pesan dikirim ke
        // orang ini", dan menulisnya untuk orang yang tidak dikirimi adalah kebohongan
        // yang merusak angka tingkat respons.
        $targets = array_values(array_filter(
            $this->select->handle($request, $request->facility, $campaign->current_radius_meters, $contacted),
            fn (DonorCandidate $candidate): bool => $candidate->isEligible && $candidate->isReachable,
        ));

        if ($targets === []) {
            return 0;
        }

        $idsByPublicId = Donor::query()
            ->whereIn('public_id', array_map(fn (DonorCandidate $c): string => $c->donorPublicId, $targets))
            ->pluck('id', 'public_id');

        $now = now();

        // Klaim DULU, tulis kontak (dan kirim) KEMUDIAN -- di transaksi pemanggil yang
        // sama. Hanya yang dikembalikan klaim yang dihubungi: donor yang sudah diklaim
        // jalur lain di antara SELECT di atas dan baris ini tersaring oleh unique index.
        $claimed = array_flip($this->calls->claim(
            $request->id,
            array_map(fn (DonorCandidate $c): int => (int) $idsByPublicId[$c->donorPublicId], $targets),
            $campaign->current_radius_meters,
            $now->toDateTimeImmutable(),
        ));

        $targets = array_values(array_filter(
            $targets,
            fn (DonorCandidate $c): bool => isset($claimed[(int) $idsByPublicId[$c->donorPublicId]]),
        ));

        if ($targets === []) {
            return 0;
        }

        $rows = [];
        foreach ($targets as $candidate) {
            $rows[] = [
                'public_id' => (string) Str::uuid(),
                'donor_campaign_id' => $campaign->id,
                'donor_id' => (int) $idsByPublicId[$candidate->donorPublicId],
                'radius_meters' => $campaign->current_radius_meters,
                'distance_meters' => $candidate->distanceMeters,
                'contacted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // SATU insert untuk seluruh tahap. Insert biasa, BUKAN insertOrIgnore: unique
        // (donor_campaign_id, donor_id) yang dilanggar berarti kunci kampanye tidak
        // bekerja, dan itu harus meledak, bukan ditelan.
        DB::table('donor_campaign_contacts')->insert($rows);

        event(new CampaignDonorsContacted($campaign->public_id, array_column($rows, 'public_id')));

        return count($rows);
    }
}
