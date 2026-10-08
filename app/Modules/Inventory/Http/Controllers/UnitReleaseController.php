<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\ReleaseUnit;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class UnitReleaseController
{
    public function __invoke(BloodBatch $bloodBatch, ReleaseUnit $releaseUnit): JsonResponse
    {
        Gate::authorize('release', $bloodBatch);

        $unit = $releaseUnit->handle($bloodBatch);

        return ApiResponse::success($unit->toApiArray());
    }
}
