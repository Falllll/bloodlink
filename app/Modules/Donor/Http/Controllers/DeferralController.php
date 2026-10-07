<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\Deferral;
use App\Models\DeferralReason;
use App\Models\Donor;
use App\Modules\Donor\Application\LiftDeferral;
use App\Modules\Donor\Application\PlaceDeferral;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Http\Requests\DeferralListRequest;
use App\Modules\Donor\Http\Requests\LiftDeferralRequest;
use App\Modules\Donor\Http\Requests\StoreDeferralRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Lapisan HTTP saja: seluruh aturan deferral (masa berlaku, permanen tidak bisa
 * dicabut, alasan nonaktif) milik PlaceDeferral/LiftDeferral (Kartu 120).
 */
final class DeferralController
{
    use AppliesListQuery;

    public function index(DeferralListRequest $request, Donor $donor): JsonResponse
    {
        Gate::authorize('viewDeferrals', $donor);

        $query = Deferral::query()->where('donor_id', $donor->id)->with(['donor', 'reason']);

        // Definisi "aktif" sama dengan Deferral::isActive() dan DonorEligibilityService.
        $active = fn ($q) => $q->whereNull('lifted_at')->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));

        match ($request->active()) {
            true => $active($query),
            false => $query->whereNot(fn ($q) => $active($q)),
            null => null,
        };

        /** @var CursorPaginator<int, Deferral> $page */
        $page = $this->listing($query, $request);

        return ApiResponse::paginated($page->through(fn (Deferral $deferral): array => $deferral->toApiArray()));
    }

    public function store(StoreDeferralRequest $request, Donor $donor, PlaceDeferral $placeDeferral): JsonResponse
    {
        Gate::authorize('manageDeferrals', $donor);

        $reason = DeferralReason::query()
            ->where('jurisdiction', 'WHO')
            ->where('code', $request->validated('reason_code'))
            ->firstOrFail();

        $deferral = $placeDeferral->handle(
            $donor,
            $reason,
            $request->anchorAt(),
            // Tindakan petugas lewat panel admin.
            DeferralSource::MANUAL,
            $request->user()?->id,
            $request->validated('note'),
        );

        return ApiResponse::success($deferral->toApiArray())->setStatusCode(201);
    }

    public function lift(LiftDeferralRequest $request, Deferral $deferral, LiftDeferral $liftDeferral): JsonResponse
    {
        Gate::authorize('manageDeferrals', $deferral->donor);

        $deferral = $liftDeferral->handle($deferral, (int) $request->user()?->id, $request->reason());

        return ApiResponse::success($deferral->toApiArray());
    }
}
