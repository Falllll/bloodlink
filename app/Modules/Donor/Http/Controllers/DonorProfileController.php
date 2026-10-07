<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donor;
use App\Models\User;
use App\Modules\Donor\Application\SummariseDeferralStatus;
use App\Modules\Donor\Application\UpdateDonorHealthStatus;
use App\Modules\Donor\Application\UpdateDonorProfile;
use App\Modules\Donor\Http\Requests\DonorListRequest;
use App\Modules\Donor\Http\Requests\UpdateDonorHealthStatusRequest;
use App\Modules\Donor\Http\Requests\UpdateDonorProfileRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

final class DonorProfileController
{
    use AppliesListQuery;

    public function index(DonorListRequest $request, SummariseDeferralStatus $summariseDeferralStatus): JsonResponse
    {
        Gate::authorize('viewAny', Donor::class);

        /** @var User|null $user */
        $user = $request->user();
        $asOf = new DateTimeImmutable;

        // Baris nisan hasil merge (Kartu 105) bukan donor yang bisa dikelola.
        $query = Donor::query()->visibleTo($user)->whereNull('merged_into_id');

        if (($term = $request->searchTerm()) !== null) {
            $query->matchingName($term);
        }

        if (($status = $request->deferralStatus()) !== null) {
            $summariseDeferralStatus->applyFilter($query, $status, $asOf);
        }

        /** @var CursorPaginator<int, Donor> $page */
        $page = $this->listing($query, $request);

        // Satu query agregat untuk seluruh halaman, bukan satu per baris.
        $statuses = $summariseDeferralStatus->forDonorIds(
            array_map(fn (Donor $donor): int => $donor->id, $page->items()),
            $asOf,
        );

        return ApiResponse::paginated($page->through(
            fn (Donor $donor): array => $donor->toListArray($statuses[$donor->id] ?? SummariseDeferralStatus::NONE)
        ));
    }

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
