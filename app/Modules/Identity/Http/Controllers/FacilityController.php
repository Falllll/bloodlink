<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\Facility;
use App\Modules\Identity\Http\Requests\FacilityListRequest;
use App\Modules\Identity\Http\Requests\StoreFacilityRequest;
use App\Modules\Identity\Http\Requests\UpdateFacilityRequest;
use App\Modules\Identity\Http\Requests\VerifyFacilityRequest;
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

        // Koordinat SATU query untuk seluruh halaman (kartu 535), bukan per baris di
        // transform: -- itu N+1. Preseden penggabungannya show() di bawah; toApiArray()
        // sengaja tidak memuatnya (store/update/deactivate tidak punya hasil spasial).
        // ST_Y = latitude, ST_X = longitude. location nullable: null tetap null, bukan 0.
        $ids = array_map(fn (Facility $f): int => $f->id, $page->items());
        $coordinates = $ids === []
            ? collect()
            : DB::table('facilities')->whereIn('id', $ids)
                ->selectRaw('id, ST_Y(location::geometry) AS latitude, ST_X(location::geometry) AS longitude')
                ->get()
                ->keyBy('id');

        return ApiResponse::paginated($page, transform: fn (Facility $f): array => array_merge($f->toApiArray(), [
            'latitude' => $coordinates->get($f->id)?->latitude,
            'longitude' => $coordinates->get($f->id)?->longitude,
        ]));
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

    /**
     * Verifikasi fasilitas (kartu 430): "boleh ikut sistem", terpisah dari is_active.
     * Sekali jalan: verifikasi ulang 409, bukan menimpa siapa dan kapan.
     */
    public function verify(Facility $facility, VerifyFacilityRequest $request): JsonResponse
    {
        Gate::authorize('verify', $facility);

        $verified = DB::transaction(function () use ($facility, $request): Facility {
            $fresh = Facility::query()->whereKey($facility->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->verified_at !== null) {
                abort(409, 'This facility has already been verified.');
            }

            $fresh->forceFill([
                'verified_at' => now(),
                'verified_by' => $request->user()?->getAuthIdentifier(),
                'verification_note' => $request->validated('note'),
            ])->save();

            return $fresh;
        });

        return ApiResponse::success($verified->toApiArray());
    }

    /** Pasangan deactivate() yang hilang: menghidupkan kembali operasional, bukan memverifikasi. */
    public function activate(Facility $facility): JsonResponse
    {
        Gate::authorize('activate', $facility);

        $facility->is_active = true;
        $facility->save();

        return ApiResponse::success($facility->toApiArray());
    }

    private function writeLocation(Facility $facility, float $latitude, float $longitude): void
    {
        $facility->forceFill([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', $longitude, $latitude)),
        ])->save();
    }
}
