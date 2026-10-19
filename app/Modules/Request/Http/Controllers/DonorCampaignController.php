<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Controllers;

use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\DonorCampaign;
use App\Models\DonorCampaignContact;
use App\Models\User;
use App\Modules\Request\Application\AdvanceDonorCampaign;
use App\Modules\Request\Application\Exceptions\CampaignRejected;
use App\Modules\Request\Application\StartDonorCampaign;
use App\Modules\Request\Domain\CampaignResponse;
use App\Modules\Request\Http\Requests\RespondToCampaignRequest;
use App\Shared\Http\ApiResponse;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Kampanye panggilan donor (kartu 400). Petugas melihat AGREGAT saja -- tidak ada
 * identitas donor di balasan mana pun. Donor menjawab undangannya sendiri.
 */
final class DonorCampaignController
{
    public function start(Request $request, BloodRequest $bloodRequest, StartDonorCampaign $startDonorCampaign): JsonResponse
    {
        Gate::authorize('manageCampaign', $bloodRequest);

        /** @var User $user */
        $user = $request->user();

        $campaign = $startDonorCampaign->handle($bloodRequest, $user);

        return ApiResponse::success($this->summary($campaign))->setStatusCode(201);
    }

    /** Kampanye terbaru untuk permintaan ini (aktif atau tidak), sebagai agregat. */
    public function show(BloodRequest $bloodRequest): JsonResponse
    {
        Gate::authorize('manageCampaign', $bloodRequest);

        $campaign = DonorCampaign::query()
            ->where('blood_request_id', $bloodRequest->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->firstOrFail();

        return ApiResponse::success($this->summary($campaign));
    }

    public function stop(DonorCampaign $campaign, AdvanceDonorCampaign $advance): JsonResponse
    {
        Gate::authorize('manageCampaign', $campaign->bloodRequest);

        return ApiResponse::success($this->summary($advance->stop($campaign)));
    }

    /** Undangan milik donor yang sedang masuk. Tanpa facility.context: donor tidak punya fasilitas. */
    public function myInvitations(Request $request): JsonResponse
    {
        $donor = $this->donorOf($request);

        $contacts = $donor === null ? collect() : DonorCampaignContact::query()
            ->where('donor_id', $donor->id)
            ->with('campaign.bloodRequest.facility')
            ->orderByDesc('contacted_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ApiResponse::success($contacts->map(fn (DonorCampaignContact $contact): array => $this->invitation($contact))->values()->all());
    }

    public function respond(RespondToCampaignRequest $request, string $contact): JsonResponse
    {
        $donor = $this->donorOf($request);

        $answered = DB::transaction(function () use ($donor, $contact, $request): DonorCampaignContact {
            // Kepemilikan di query: undangan orang lain dibalas 404, sama dengan yang
            // tidak ada -- keberadaannya bukan informasi yang perlu dibocorkan.
            $row = DonorCampaignContact::query()
                ->where('public_id', $contact)
                ->where('donor_id', $donor === null ? 0 : $donor->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->responded_at !== null) {
                throw CampaignRejected::alreadyResponded();
            }

            if (! DonorCampaign::query()->findOrFail($row->donor_campaign_id)->isActive()) {
                throw CampaignRejected::alreadyEnded();
            }

            // Hanya jawaban: tidak ada alokasi, tidak ada unit, tidak ada status permintaan
            // yang berubah. Donor yang bersedia tetap melewati alur donasi normal.
            $row->forceFill(['response' => $request->campaignResponse(), 'responded_at' => now()])->save();

            return $row;
        });

        return ApiResponse::success($this->invitation($answered->load('campaign.bloodRequest.facility')));
    }

    /** Sama dengan ResolveDonorForUser: baris hasil merge adalah batu nisan. */
    private function donorOf(Request $request): ?Donor
    {
        /** @var User $user */
        $user = $request->user();

        return Donor::query()->where('user_id', $user->id)->whereNull('merged_into_id')->first();
    }

    /** @return array<string, mixed> */
    private function summary(DonorCampaign $campaign): array
    {
        $stages = DonorCampaignContact::query()
            ->where('donor_campaign_id', $campaign->id)
            ->groupBy('radius_meters')
            ->orderBy('radius_meters')
            ->selectRaw('radius_meters, COUNT(*) AS contacted')
            ->selectRaw('COUNT(*) FILTER (WHERE response = ?) AS accepted', [CampaignResponse::ACCEPTED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE response = ?) AS declined', [CampaignResponse::DECLINED->value])
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'radius_meters' => (int) $row->radius_meters,
                'contacted' => (int) $row->contacted,
                'accepted' => (int) $row->accepted,
                'declined' => (int) $row->declined,
            ])
            ->all();

        return [
            'id' => $campaign->public_id,
            'blood_request_id' => $campaign->bloodRequest->public_id,
            'current_radius_meters' => $campaign->current_radius_meters,
            'started_at' => $campaign->started_at->format(DateTimeInterface::ATOM),
            'stage_entered_at' => $campaign->stage_entered_at->format(DateTimeInterface::ATOM),
            'ended_at' => $campaign->ended_at?->format(DateTimeInterface::ATOM),
            'end_reason' => $campaign->end_reason?->value,
            'stages' => $stages,
            'totals' => [
                'contacted' => array_sum(array_column($stages, 'contacted')),
                'accepted' => array_sum(array_column($stages, 'accepted')),
                'declined' => array_sum(array_column($stages, 'declined')),
            ],
        ];
    }

    /**
     * Yang dilihat donor: kebutuhannya dan di mana -- TANPA patient_reference atau
     * data pasien apa pun.
     *
     * @return array<string, mixed>
     */
    private function invitation(DonorCampaignContact $contact): array
    {
        $bloodRequest = $contact->campaign->bloodRequest;

        return [
            'id' => $contact->public_id,
            'facility_name' => $bloodRequest->facility->name,
            'component' => $bloodRequest->component->value,
            'urgency' => $bloodRequest->urgency->value,
            'needed_by' => $bloodRequest->needed_by->format(DateTimeInterface::ATOM),
            'distance_meters' => $contact->distance_meters,
            'contacted_at' => $contact->contacted_at->format(DateTimeInterface::ATOM),
            'responded_at' => $contact->responded_at?->format(DateTimeInterface::ATOM),
            'response' => $contact->response?->value,
            'is_open' => $contact->campaign->isActive() && $contact->responded_at === null,
        ];
    }
}
