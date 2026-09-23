<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donor;
use App\Modules\Donor\Application\MergeDonors;
use App\Modules\Donor\Http\Requests\MergeDonorsRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DonorMergeController
{
    public function __invoke(MergeDonorsRequest $request, MergeDonors $merge): JsonResponse
    {
        Gate::authorize('merge', Donor::class);

        $source = Donor::query()->where('public_id', $request->validated('source_id'))->firstOrFail();
        $target = Donor::query()->where('public_id', $request->validated('target_id'))->firstOrFail();

        $target = $merge->handle($source, $target);

        return ApiResponse::success([
            'id' => $target->public_id,
            'merged_id' => $source->public_id,
        ]);
    }
}
