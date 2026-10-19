<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\DonorCampaign;
use App\Models\User;
use App\Modules\Request\Application\Exceptions\CampaignRejected;
use App\Modules\Request\Domain\RequestStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Memulai kampanye panggilan donor (kartu 400). Selalu oleh MANUSIA lewat endpoint:
 * penjadwal hanya memajukan dan menutup, tidak pernah memulai.
 */
final class StartDonorCampaign
{
    public function __construct(private readonly ContactCampaignStage $contactStage) {}

    public function handle(BloodRequest $request, User $actor): DonorCampaign
    {
        try {
            return DB::transaction(function () use ($request, $actor): DonorCampaign {
                $fresh = BloodRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

                // Terpenuhi berarti semua kantong sudah dialokasikan: tidak ada yang perlu dipanggil.
                if (! in_array($fresh->status, [RequestStatus::OPEN, RequestStatus::PARTIALLY_FULFILLED], true)) {
                    throw CampaignRejected::requestNotOpen($fresh->status);
                }

                // Index parsial juga menjaganya; ini untuk pesan yang manusiawi.
                if (DonorCampaign::query()->active()->where('blood_request_id', $fresh->id)->exists()) {
                    throw CampaignRejected::alreadyRunning();
                }

                /** @var list<int> $ladder */
                $ladder = config('matching.staged_radii_meters');

                $campaign = new DonorCampaign;
                $campaign->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'blood_request_id' => $fresh->id,
                    'started_by' => $actor->id,
                    'current_radius_meters' => $ladder[0],
                    'stage_entered_at' => now(),
                    'started_at' => now(),
                ])->save();

                $this->contactStage->handle($campaign);

                return $campaign;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'donor_campaigns_one_active_per_request')) {
                throw CampaignRejected::alreadyRunning();
            }

            throw $e;
        }
    }
}
