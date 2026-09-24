<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donor;
use App\Modules\Donor\Application\RecordScreening;
use App\Modules\Donor\Http\Requests\RecordScreeningRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DonorScreeningController
{
    public function store(RecordScreeningRequest $request, Donor $donor, RecordScreening $recordScreening): JsonResponse
    {
        Gate::authorize('recordScreening', $donor);

        $screening = $recordScreening->handle($donor, $request->validated(), $request->user()?->id);

        return ApiResponse::success($screening->toApiArray())->setStatusCode(201);
    }
}
