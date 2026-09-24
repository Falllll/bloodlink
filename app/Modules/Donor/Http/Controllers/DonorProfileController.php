<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donor;
use App\Modules\Donor\Application\UpdateDonorHealthStatus;
use App\Modules\Donor\Application\UpdateDonorProfile;
use App\Modules\Donor\Http\Requests\UpdateDonorHealthStatusRequest;
use App\Modules\Donor\Http\Requests\UpdateDonorProfileRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DonorProfileController
{
    public function show(Donor $donor): JsonResponse
    {
        Gate::authorize('view', $donor);

        return ApiResponse::success($donor->toApiArray());
    }

    public function update(UpdateDonorProfileRequest $request, Donor $donor, UpdateDonorProfile $updateDonorProfile): JsonResponse
    {
        Gate::authorize('update', $donor);

        $donor = $updateDonorProfile->handle($donor, $request->validated());

        return ApiResponse::success($donor->toApiArray());
    }

    public function updateHealthStatus(
        UpdateDonorHealthStatusRequest $request,
        Donor $donor,
        UpdateDonorHealthStatus $updateDonorHealthStatus,
    ): JsonResponse {
        Gate::authorize('updateHealthStatus', $donor);

        $weightKg = $request->validated('weight_kg');

        $donor = $updateDonorHealthStatus->handle(
            $donor,
            $weightKg !== null ? (float) $weightKg : null,
            $request->validated('blood_group'),
            $request->validated('rh_factor'),
        );

        return ApiResponse::success($donor->toApiArray());
    }
}
