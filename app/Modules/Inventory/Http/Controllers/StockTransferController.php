<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\BloodBatch;
use App\Models\StockTransfer;
use App\Models\User;
use App\Modules\Inventory\Application\RequestStockTransfer;
use App\Modules\Inventory\Application\TransitionStockTransfer;
use App\Modules\Inventory\Domain\TransferStatus;
use App\Modules\Inventory\Http\Requests\DecideStockTransferRequest;
use App\Modules\Inventory\Http\Requests\RequestStockTransferRequest;
use App\Modules\Inventory\Http\Requests\StockTransferListRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Transfer dicari di antara transfer yang menyentuh fasilitas pemegang token,
 * bukan lewat route model binding: transfer fasilitas lain TIDAK ADA (404),
 * bukan ditemukan lalu ditolak (403). Policy tetap memutuskan PERAN di tiap
 * langkah -- peminta tidak bisa memutuskan, pemilik tidak bisa menerima.
 */
final class StockTransferController
{
    use AppliesListQuery;

    private const array RELATIONS = ['bloodBatch', 'fromFacility', 'toFacility'];

    public function index(StockTransferListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', StockTransfer::class);

        $user = $this->user($request);
        $query = $this->visibleTo($user)->with(self::RELATIONS);

        $facilityId = $user->facilityId();
        if ($facilityId !== null) {
            match ($request->direction()) {
                StockTransferListRequest::INCOMING => $query->where('to_facility_id', $facilityId),
                StockTransferListRequest::OUTGOING => $query->where('from_facility_id', $facilityId),
                default => null,
            };
        }

        /** @var CursorPaginator<int, StockTransfer> $page */
        $page = $this->listing($query, $request);

        return ApiResponse::paginated($page, transform: fn (StockTransfer $transfer): array => $transfer->toApiArray());
    }

    public function store(RequestStockTransferRequest $request, RequestStockTransfer $requestStockTransfer): JsonResponse
    {
        $user = $this->user($request);

        $unit = BloodBatch::query()->where('public_id', $request->validated('unit_id'))->firstOrFail();

        // Kelas disebut eksplisit: Gate::authorize('request', $unit) memilih BloodBatchPolicy.
        Gate::authorize('request', [StockTransfer::class, $unit]);

        $transfer = $requestStockTransfer->handle(
            $unit,
            (int) $user->facilityId(),
            $user->id,
            $request->validated('reason'),
        );

        return ApiResponse::success($transfer->load(self::RELATIONS)->toApiArray())->setStatusCode(201);
    }

    public function decide(DecideStockTransferRequest $request, string $stockTransfer, TransitionStockTransfer $transition): JsonResponse
    {
        $user = $this->user($request);
        $transfer = $this->find($user, $stockTransfer);

        Gate::authorize('decide', $transfer);

        $transfer = $transition->handle($transfer, $request->status(), $user->id, $request->validated('decision_note'));

        return ApiResponse::success($transfer->load(self::RELATIONS)->toApiArray());
    }

    public function ship(Request $request, string $stockTransfer, TransitionStockTransfer $transition): JsonResponse
    {
        return $this->step($request, $stockTransfer, $transition, 'ship', TransferStatus::SHIPPED);
    }

    public function receive(Request $request, string $stockTransfer, TransitionStockTransfer $transition): JsonResponse
    {
        return $this->step($request, $stockTransfer, $transition, 'receive', TransferStatus::RECEIVED);
    }

    public function cancel(Request $request, string $stockTransfer, TransitionStockTransfer $transition): JsonResponse
    {
        return $this->step($request, $stockTransfer, $transition, 'cancel', TransferStatus::CANCELLED);
    }

    private function step(Request $request, string $publicId, TransitionStockTransfer $transition, string $ability, TransferStatus $to): JsonResponse
    {
        $transfer = $this->find($this->user($request), $publicId);

        Gate::authorize($ability, $transfer);

        return ApiResponse::success($transition->handle($transfer, $to)->load(self::RELATIONS)->toApiArray());
    }

    private function find(User $user, string $publicId): StockTransfer
    {
        return $this->visibleTo($user)->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * Penyaringan eksplisit, bukan ScopedToFacility: transfer punya dua kolom
     * fasilitas, dan keduanya berhak melihatnya.
     *
     * @return Builder<StockTransfer>
     */
    private function visibleTo(User $user): Builder
    {
        $query = StockTransfer::query();
        $facilityId = $user->facilityId();

        if ($facilityId !== null) {
            return $query->where(fn (Builder $q) => $q->where('from_facility_id', $facilityId)->orWhere('to_facility_id', $facilityId));
        }

        return $user->isGlobalOperator() ? $query : $query->whereRaw('1 = 0');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
