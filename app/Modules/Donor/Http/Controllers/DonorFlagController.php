<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Donor;
use App\Models\DonorFlag;
use App\Models\User;
use App\Modules\Donor\Application\FlagDonor;
use App\Modules\Donor\Http\Requests\DonorFlagListRequest;
use App\Modules\Donor\Http\Requests\LiftDonorFlagRequest;
use App\Modules\Donor\Http\Requests\PlaceDonorFlagRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

/** Lapisan HTTP saja: aturan flag milik FlagDonor (kartu 430). */
final class DonorFlagController
{
    use AppliesListQuery;

    public function index(DonorFlagListRequest $request, Donor $donor): JsonResponse
    {
        Gate::authorize('viewAny', [DonorFlag::class, $donor]);

        $query = DonorFlag::query()->where('donor_id', $donor->id)->with('donor');

        // Definisi "aktif" yang SAMA dengan mesin kelayakan: FlagDonor::whereActive.
        match ($request->active()) {
            true => FlagDonor::whereActive($query, now()),
            false => $query->whereNot(fn ($q) => FlagDonor::whereActive($q, now())),
            null => null,
        };

        /** @var CursorPaginator<int, DonorFlag> $page */
        $page = $this->listing($query, $request);

        return ApiResponse::paginated($page, transform: fn (DonorFlag $flag): array => $flag->toApiArray());
    }

    public function store(PlaceDonorFlagRequest $request, Donor $donor, FlagDonor $flagDonor): JsonResponse
    {
        Gate::authorize('create', DonorFlag::class);

        /** @var User $user */
        $user = $request->user();

        // Donor SELALU dari path; body tidak punya kolom donor sama sekali.
        $flag = $flagDonor->place($donor, $request->reason(), (string) $request->validated('note'), $request->endsAt(), $user);

        return ApiResponse::success($flag->toApiArray())->setStatusCode(201);
    }

    public function lift(LiftDonorFlagRequest $request, DonorFlag $donorFlag, FlagDonor $flagDonor): JsonResponse
    {
        Gate::authorize('lift', $donorFlag);

        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($flagDonor->lift($donorFlag, $request->reason(), $user)->load('donor')->toApiArray());
    }
}
