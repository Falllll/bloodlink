<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\Facility;
use App\Modules\Identity\Http\Requests\FacilityListRequest;
use App\Modules\Identity\Http\Requests\StoreFacilityRequest;
use App\Modules\Identity\Http\Requests\UpdateFacilityRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class FacilityController
{
    use AppliesListQuery;

    public function index(FacilityListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Facility::class);

        /** @var CursorPaginator<int, Facility> $page */
        $page = $this->listing(Facility::query(), $request);

        return ApiResponse::paginated($page->through(fn (Facility $f) => $f->toApiArray()));
    }

    public function show(Facility $facility): JsonResponse
    {
        Gate::authorize('view', $facility);

        $row = DB::table('facilities')->where('id', $facility->id)
            ->selectRaw('ST_Y(location::geometry) AS latitude, ST_X(location::geometry) AS longitude')
            ->first();

        return ApiResponse::success(array_merge($facility->toApiArray(), [
            'latitude' => $row?->latitude,
            'longitude' => $row?->longitude,
        ]));
    }

    public function store(StoreFacilityRequest $request): JsonResponse
    {
        Gate::authorize('create', Facility::class);

        $facility = DB::transaction(function () use ($request): Facility {
            $facility = new Facility($request->safe()->only([
                'code', 'name', 'type', 'address', 'city', 'province', 'phone', 'email',
            ]));

            $facility->forceFill(['public_id' => Str::uuid()])->save();

            if ($request->filled('latitude') && $request->filled('longitude')) {
                $this->writeLocation($facility, (float) $request->validated('latitude'), (float) $request->validated('longitude'));
            }

            return $facility;
        });

        return ApiResponse::success($facility->toApiArray(), [])->setStatusCode(201);
    }

    public function update(UpdateFacilityRequest $request, Facility $facility): JsonResponse
    {
        Gate::authorize('update', $facility);

        $facility->fill($request->safe()->only([
            'code', 'name', 'type', 'address', 'city', 'province', 'phone', 'email',
        ]));
        $facility->save();

        if ($request->filled('latitude') && $request->filled('longitude')) {
            $this->writeLocation($facility, (float) $request->validated('latitude'), (float) $request->validated('longitude'));
        }

        return ApiResponse::success($facility->toApiArray());
    }

    public function deactivate(Facility $facility): JsonResponse
    {
        Gate::authorize('deactivate', $facility);

        $facility->is_active = false;
        $facility->save();

        return ApiResponse::success($facility->toApiArray());
    }

    private function writeLocation(Facility $facility, float $latitude, float $longitude): void
    {
        DB::table('facilities')->where('id', $facility->id)->update([
            'location' => DB::raw("ST_SetSRID(ST_MakePoint({$longitude}, {$latitude}), 4326)::geography"),
        ]);
    }
}
