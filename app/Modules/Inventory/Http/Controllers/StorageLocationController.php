<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\StorageLocation;
use App\Models\StorageTemperatureReading;
use App\Models\User;
use App\Modules\Inventory\Application\RecordTemperatureReading;
use App\Modules\Inventory\Http\Requests\RecordTemperatureReadingRequest;
use App\Modules\Inventory\Http\Requests\StorageLocationListRequest;
use App\Modules\Inventory\Http\Requests\StoreStorageLocationRequest;
use App\Modules\Inventory\Http\Requests\TemperatureReadingListRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class StorageLocationController
{
    use AppliesListQuery;

    public function index(StorageLocationListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', StorageLocation::class);

        /** @var User|null $user */
        $user = $request->user();

        /** @var CursorPaginator<int, StorageLocation> $page */
        $page = $this->listing(StorageLocation::query()->visibleTo($user), $request);

        return ApiResponse::paginated($page, transform: fn (StorageLocation $location): array => $location->toApiArray());
    }

    public function store(StoreStorageLocationRequest $request): JsonResponse
    {
        Gate::authorize('create', StorageLocation::class);

        /** @var User $user */
        $user = $request->user();

        $location = new StorageLocation($request->safe()->only([
            'code', 'label', 'kind', 'purpose', 'temp_min_celsius', 'temp_max_celsius',
        ]));

        $location->forceFill(['public_id' => (string) Str::uuid()])
            ->assignFacility((int) $user->facilityId())
            ->save();

        return ApiResponse::success($location->refresh()->toApiArray())->setStatusCode(201);
    }

    public function readings(TemperatureReadingListRequest $request, StorageLocation $storageLocation): JsonResponse
    {
        Gate::authorize('view', $storageLocation);

        /** @var CursorPaginator<int, StorageTemperatureReading> $page */
        $page = $this->listing($storageLocation->readings()->getQuery()->with('storageLocation'), $request);

        return ApiResponse::paginated($page, transform: fn (StorageTemperatureReading $reading): array => $reading->toApiArray());
    }

    public function recordReading(
        RecordTemperatureReadingRequest $request,
        StorageLocation $storageLocation,
        RecordTemperatureReading $recordTemperatureReading,
    ): JsonResponse {
        Gate::authorize('recordReading', $storageLocation);

        $reading = $recordTemperatureReading->handle(
            $storageLocation,
            (string) $request->validated('reading_celsius'),
            $request->source(),
            $request->user()?->id,
            $request->validated('note'),
        );

        // 201 juga untuk pembacaan di luar rentang: ia sah dan justru wajib tersimpan.
        return ApiResponse::success($reading->toApiArray())->setStatusCode(201);
    }
}
