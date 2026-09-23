<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donor;
use App\Modules\Donor\Application\RecordDonorConsent;
use App\Modules\Donor\Domain\ConsentPurpose;
use App\Modules\Donor\Http\Requests\RecordDonorConsentRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DonorConsentController
{
    public function store(RecordDonorConsentRequest $request, Donor $donor, RecordDonorConsent $recordDonorConsent): JsonResponse
    {
        Gate::authorize('recordConsent', $donor);

        $consent = $recordDonorConsent->grant(
            $donor,
            array_map(
                fn (string $purpose): ConsentPurpose => ConsentPurpose::from($purpose),
                $request->purposes(),
            ),
            $request->user()?->id,
            $request->ip(),
        );

        return ApiResponse::success([
            'id' => $consent->public_id,
            'purposes' => $consent->purposes,
            'granted_at' => $consent->granted_at,
        ])->setStatusCode(201);
    }
}
