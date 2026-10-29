<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Controllers;

use App\Models\BloodRequest;
use App\Models\User;
use App\Modules\Request\Infrastructure\FacilityCampaignDashboardQuery;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard respons donor petugas (kartu 500): satu entri per kampanye AKTIF milik
 * fasilitas pemegang token, berbentuk sama dengan GET /blood-requests/{id}/campaign.
 * Kampanye yang sudah berakhir tetap terbaca lewat endpoint per permintaan itu.
 */
final class FacilityDashboardController
{
    public function __invoke(Request $request, FacilityCampaignDashboardQuery $query): JsonResponse
    {
        Gate::authorize('viewAny', BloodRequest::class);

        /** @var User $user */
        $user = $request->user();
        $facilityId = $user->facilityId();

        // Operator global tidak punya layar operasional: dashboard ini berlingkup SATU
        // fasilitas, dan mengagregasi seluruh sistem di sini bukan yang dibaca siapa pun.
        if ($facilityId === null) {
            return ApiResponse::success([], ['scope' => 'facility', 'reason' => 'no_facility']);
        }

        return ApiResponse::success($query->handle($facilityId), ['scope' => 'facility']);
    }
}
