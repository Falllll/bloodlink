<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\DonorCampaign;
use App\Models\DonorCampaignContact;
use App\Modules\Request\Application\Exceptions\CampaignRejected;
use App\Modules\Request\Domain\CampaignEndReason;
use App\Modules\Request\Domain\CampaignResponse;
use App\Modules\Request\Domain\RequestStatus;
use Illuminate\Support\Facades\DB;

/**
 * Memajukan atau menutup satu kampanye (kartu 400). Keputusan (decide) dipisah
 * dari eksekusi (handle) supaya dry-run command memakai logika yang SAMA tanpa
 * menulis apa pun.
 */
final class AdvanceDonorCampaign
{
    public const string WAIT = 'wait';

    public const string HOLD = 'hold';

    public const string ESCALATE = 'escalate';

    public function __construct(private readonly ContactCampaignStage $contactStage) {}

    /**
     * Apa yang AKAN terjadi, tanpa menulis apa pun.
     *
     * @return CampaignEndReason|self::WAIT|self::HOLD|self::ESCALATE
     */
    public function decide(DonorCampaign $campaign, BloodRequest $request): CampaignEndReason|string
    {
        // 1. Permintaannya sudah selesai: kampanye ikut berhenti, apa pun tahapnya.
        $closing = match ($request->status) {
            RequestStatus::FULFILLED => CampaignEndReason::REQUEST_FULFILLED,
            RequestStatus::CANCELLED => CampaignEndReason::REQUEST_CANCELLED,
            RequestStatus::EXPIRED => CampaignEndReason::REQUEST_EXPIRED,
            default => null,
        };

        if ($closing !== null) {
            return $closing;
        }

        // 2. Jeda tahap belum habis.
        if ($campaign->stage_entered_at->copy()->addMinutes((int) config('matching.stage_dwell_minutes'))->isFuture()) {
            return self::WAIT;
        }

        // 3. Sudah cukup donor yang bersedia: JANGAN tutup -- bersedia belum sama dengan
        // darah yang ada. Cukup berhenti menaikkan tahap sampai permintaannya benar-benar
        // terpenuhi lewat alur donasi normal (langkah 1 yang akan menutupnya).
        $accepted = DonorCampaignContact::query()
            ->where('donor_campaign_id', $campaign->id)
            ->where('response', CampaignResponse::ACCEPTED->value)
            ->count();

        if ($accepted >= $request->quantity) {
            return self::HOLD;
        }

        // 4/5. Naik ke anak tangga berikutnya, atau habis.
        return $this->nextRadius($campaign) === null ? CampaignEndReason::STAGES_EXHAUSTED : self::ESCALATE;
    }

    /** @return CampaignEndReason|int alasan berhenti, atau jumlah donor yang baru dihubungi */
    public function handle(DonorCampaign $campaign): CampaignEndReason|int
    {
        return DB::transaction(function () use ($campaign): CampaignEndReason|int {
            // Kunci kampanye LEBIH DULU: dua penjadwal bersamaan berbaris di sini, dan
            // yang kedua melihat stage_entered_at yang sudah diperbarui (WAIT).
            $fresh = DonorCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isActive()) {
                $campaign->setRawAttributes($fresh->getAttributes(), sync: true);

                return 0;
            }

            $decision = $this->decide($fresh, BloodRequest::query()->findOrFail($fresh->blood_request_id));

            if ($decision instanceof CampaignEndReason) {
                $this->close($fresh, $decision);
            } elseif ($decision === self::ESCALATE) {
                $fresh->forceFill([
                    'current_radius_meters' => $this->nextRadius($fresh),
                    'stage_entered_at' => now(),
                ])->save();

                $decision = $this->contactStage->handle($fresh);
            } else {
                $decision = 0;
            }

            $campaign->setRawAttributes($fresh->getAttributes(), sync: true);

            return $decision;
        });
    }

    /** Penghentian manual oleh petugas. */
    public function stop(DonorCampaign $campaign): DonorCampaign
    {
        DB::transaction(function () use ($campaign): void {
            $fresh = DonorCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isActive()) {
                throw CampaignRejected::alreadyEnded();
            }

            $this->close($fresh, CampaignEndReason::STOPPED_MANUALLY);
            $campaign->setRawAttributes($fresh->getAttributes(), sync: true);
        });

        return $campaign;
    }

    private function close(DonorCampaign $campaign, CampaignEndReason $reason): void
    {
        $campaign->forceFill(['ended_at' => now(), 'end_reason' => $reason])->save();
    }

    private function nextRadius(DonorCampaign $campaign): ?int
    {
        /** @var list<int> $ladder */
        $ladder = config('matching.staged_radii_meters');

        foreach ($ladder as $radius) {
            if ($radius > $campaign->current_radius_meters) {
                return $radius;
            }
        }

        return null;
    }
}
