<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\SeparateIntoComponents;
use App\Modules\Inventory\Http\Requests\SeparateIntoComponentsRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ComponentSeparationController
{
    public function __invoke(
        SeparateIntoComponentsRequest $request,
        BloodBatch $bloodBatch,
        SeparateIntoComponents $separateIntoComponents,
    ): JsonResponse {
        // Pemisahan mengubah status unit, jadi dijaga izin yang sama dengan
        // perpindahan status (BloodBatchPolicy::transition, Kartu 200).
        Gate::authorize('transition', $bloodBatch);

        $components = $separateIntoComponents->handle($bloodBatch, $request->plan());

        return ApiResponse::success([
            'parent' => $bloodBatch->toApiArray(),
            'components' => array_map(fn (BloodBatch $component): array => $component->toApiArray(), $components),
        ])->setStatusCode(201);
    }
}
