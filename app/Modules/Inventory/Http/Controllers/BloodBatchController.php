<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\BloodBatch;
use App\Models\Donor;
use App\Models\User;
use App\Modules\Inventory\Application\RegisterBloodBatch;
use App\Modules\Inventory\Application\TransitionBloodBatch;
use App\Modules\Inventory\Http\Requests\BloodBatchListRequest;
use App\Modules\Inventory\Http\Requests\StoreBloodBatchRequest;
use App\Modules\Inventory\Http\Requests\TransitionBloodBatchRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

final class BloodBatchController
{
    use AppliesListQuery;

    public function index(BloodBatchListRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        /** @var CursorPaginator<int, BloodBatch> $page */
        $page = $this->listing(
            BloodBatch::query()->visibleTo($user)->with(['facility', 'donor', 'storageProfile']),
            $request,
        );

        return ApiResponse::paginated($page->through(fn (BloodBatch $batch) => $batch->toApiArray()));
    }

    public function show(BloodBatch $bloodBatch): JsonResponse
    {
        Gate::authorize('view', $bloodBatch);

        return ApiResponse::success($bloodBatch->toApiArray());
    }

    public function store(StoreBloodBatchRequest $request, RegisterBloodBatch $registerBloodBatch): JsonResponse
    {
        Gate::authorize('create', BloodBatch::class);

        /** @var User $user */
        $user = $request->user();

        $donorPublicId = $request->validated('donor_id');
        $hemoglobin = $request->validated('hemoglobin_g_dl');

        $batch = $registerBloodBatch->handle((int) $user->facilityId(), [
            'component' => $request->validated('component'),
            'blood_group' => $request->validated('blood_group'),
            'rh_factor' => $request->validated('rh_factor'),
            'volume_ml' => (int) $request->validated('volume_ml'),
            'hemoglobin_g_dl' => $hemoglobin !== null ? (float) $hemoglobin : null,
            'collected_at' => new DateTimeImmutable($request->validated('collected_at')),
            'storage_temperature_c' => (float) $request->validated('storage_temperature_c'),
            'donor_id' => $donorPublicId !== null
                ? Donor::query()->where('public_id', $donorPublicId)->value('id')
                : null,
        ]);

        return ApiResponse::success($batch->toApiArray())->setStatusCode(201);
    }

    public function transition(
        TransitionBloodBatchRequest $request,
        BloodBatch $bloodBatch,
        TransitionBloodBatch $transitionBloodBatch,
    ): JsonResponse {
        Gate::authorize('transition', $bloodBatch);

        $batch = $transitionBloodBatch->handle($bloodBatch, $request->status(), $request->discardReason());

        return ApiResponse::success($batch->toApiArray());
    }
}
