<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Appointment;
use App\Modules\Donor\Application\RecordDonation;
use App\Modules\Donor\Http\Requests\RecordDonationRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DonationController
{
    public function store(RecordDonationRequest $request, Appointment $appointment, RecordDonation $recordDonation): JsonResponse
    {
        Gate::authorize('recordDonation', $appointment->donor);

        $donation = $recordDonation->handle($appointment, $request->validated(), $request->user()?->id);

        return ApiResponse::success($donation->toApiArray())->setStatusCode(201);
    }
}
