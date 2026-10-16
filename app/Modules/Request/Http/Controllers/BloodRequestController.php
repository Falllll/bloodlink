<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Controllers;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Crossmatch;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CancelBloodRequest;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\IssueAllocatedUnit;
use App\Modules\Request\Application\RecordCrossmatch;
use App\Modules\Request\Application\ReserveAllocatedUnits;
use App\Modules\Request\Domain\RequestUrgency;
use App\Modules\Request\Http\Requests\AllocateRequest;
use App\Modules\Request\Http\Requests\BloodRequestListRequest;
use App\Modules\Request\Http\Requests\CancelBloodRequestRequest;
use App\Modules\Request\Http\Requests\RecordCrossmatchRequest;
use App\Modules\Request\Http\Requests\ReleaseAllocationRequest;
use App\Modules\Request\Http\Requests\ReserveRequest;
use App\Modules\Request\Http\Requests\StoreBloodRequestRequest;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class BloodRequestController
{
    use AppliesListQuery;

    public function index(BloodRequestListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', BloodRequest::class);

        /** @var User|null $user */
        $user = $request->user();

        /** @var CursorPaginator<int, BloodRequest> $page */
        $page = $this->listing(BloodRequest::query()->visibleTo($user)->with('facility'), $request);

        return ApiResponse::paginated($page, transform: fn (BloodRequest $bloodRequest): array => $bloodRequest->toApiArray());
    }

    public function store(StoreBloodRequestRequest $request, CreateBloodRequest $createBloodRequest): JsonResponse
    {
        Gate::authorize('create', BloodRequest::class);

        /** @var User $user */
        $user = $request->user();

        $bloodRequest = $createBloodRequest->handle(
            (int) $user->facilityId(),
            $user->id,
            BloodComponent::from($request->validated('component')),
            BloodGroup::from($request->validated('blood_group')),
            RhFactor::from($request->validated('rh_factor')),
            (int) $request->validated('quantity'),
            RequestUrgency::from($request->validated('urgency')),
            new DateTimeImmutable($request->validated('needed_by')),
            $request->validated('patient_reference'),
            $request->validated('note'),
        );

        return ApiResponse::success($bloodRequest->load('facility')->toApiArray())->setStatusCode(201);
    }

    /** Saga pembatalan (kartu 360): semua alokasi yang belum terbit dilepas dalam satu transaksi. */
    public function cancel(CancelBloodRequestRequest $request, BloodRequest $bloodRequest, CancelBloodRequest $cancelBloodRequest): JsonResponse
    {
        Gate::authorize('cancel', $bloodRequest);

        $bloodRequest = $cancelBloodRequest->handle($bloodRequest, $request->validated('reason'));

        return ApiResponse::success($bloodRequest->load('facility')->toApiArray());
    }

    /** Kandidat FEFO yang lolos gerbang kompatibilitas, sebanyak sisa kuantitas. Tidak menulis apa pun. */
    public function candidates(BloodRequest $bloodRequest, AllocateUnitsFefo $fefo): JsonResponse
    {
        Gate::authorize('allocate', $bloodRequest);

        $remaining = max(0, $bloodRequest->quantity - BloodRequestAllocation::query()
            ->where('blood_request_id', $bloodRequest->id)
            ->whereNull('released_at')
            ->count());

        return ApiResponse::success([
            'blood_request_id' => $bloodRequest->public_id,
            'remaining' => $remaining,
            'candidates' => $fefo->candidatesFor($bloodRequest, $remaining)
                ->map(fn (BloodBatch $unit): array => [
                    'unit_id' => $unit->public_id,
                    'blood_group' => $unit->getAttribute('blood_group'),
                    'rh_factor' => $unit->getAttribute('rh_factor'),
                    'expires_at' => $unit->expires_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function allocate(AllocateRequest $request, BloodRequest $bloodRequest, AllocateUnitsFefo $fefo): JsonResponse
    {
        Gate::authorize('allocate', $bloodRequest);

        /** @var User $user */
        $user = $request->user();

        $allocations = $fefo->handle($bloodRequest, $user->id, $request->max());

        return ApiResponse::success([
            'blood_request' => $bloodRequest->load('facility')->toApiArray(),
            'allocations' => array_map(
                fn (BloodRequestAllocation $allocation): array => $allocation->load(['bloodRequest', 'bloodBatch'])->toApiArray(),
                $allocations,
            ),
        ])->setStatusCode(201);
    }

    public function releaseAllocation(
        ReleaseAllocationRequest $request,
        BloodRequestAllocation $allocation,
        AllocateUnitsFefo $fefo,
        ReserveAllocatedUnits $reservations,
    ): JsonResponse {
        Gate::authorize('allocate', $allocation->bloodRequest);

        $reason = $request->validated('reason');

        // Alokasi yang dilepas dengan reservasi masih menempel akan meninggalkan unit
        // reserved tanpa alokasi aktif -- tak terlihat timeout, tak bisa dialokasikan.
        // Lepas reservasinya dulu, dalam transaksi yang sama.
        $allocation = DB::transaction(function () use ($allocation, $reason, $fefo, $reservations): BloodRequestAllocation {
            // URUTAN KUNCI sama dengan saga pembatalan: permintaan dulu, baru unit dan alokasi.
            BloodRequest::query()->whereKey($allocation->blood_request_id)->lockForUpdate()->firstOrFail();

            $reservations->release($allocation, 'allocation released: '.$reason);

            return $fefo->release($allocation, $reason);
        });

        return ApiResponse::success($allocation->load(['bloodRequest', 'bloodBatch'])->toApiArray());
    }

    public function reserve(ReserveRequest $request, BloodRequest $bloodRequest, ReserveAllocatedUnits $reservations): JsonResponse
    {
        Gate::authorize('reserve', $bloodRequest);

        $reserved = $reservations->handle($bloodRequest, $request->windowHours());

        return ApiResponse::success(array_map(
            fn (BloodRequestAllocation $allocation): array => $allocation->load(['bloodRequest', 'bloodBatch'])->toApiArray(),
            $reserved,
        ))->setStatusCode(201);
    }

    public function releaseReservation(ReleaseAllocationRequest $request, BloodRequestAllocation $allocation, ReserveAllocatedUnits $reservations): JsonResponse
    {
        Gate::authorize('reserve', $allocation->bloodRequest);

        $allocation = $reservations->release($allocation, $request->validated('reason'));

        return ApiResponse::success($allocation->load(['bloodRequest', 'bloodBatch'])->toApiArray());
    }

    public function recordCrossmatch(RecordCrossmatchRequest $request, BloodRequestAllocation $allocation, RecordCrossmatch $recordCrossmatch): JsonResponse
    {
        Gate::authorize('recordCrossmatch', $allocation->bloodRequest);

        /** @var User $user */
        $user = $request->user();

        $crossmatch = $recordCrossmatch->handle(
            $allocation,
            $request->result(),
            $user,
            $request->validated('method'),
            $request->validated('note'),
        );

        return ApiResponse::success($crossmatch->toApiArray())->setStatusCode(201);
    }

    public function issue(Request $request, BloodRequestAllocation $allocation, IssueAllocatedUnit $issueAllocatedUnit): JsonResponse
    {
        Gate::authorize('issue', $allocation->bloodRequest);

        /** @var User $user */
        $user = $request->user();

        $allocation = $issueAllocatedUnit->handle($allocation, $user);

        return ApiResponse::success([
            'allocation' => $allocation->load(['bloodRequest', 'bloodBatch'])->toApiArray(),
            'unit_status' => (string) $allocation->bloodBatch->refresh()->getRawOriginal('status'),
            'crossmatches' => Crossmatch::query()
                ->where('blood_request_allocation_id', $allocation->id)
                ->orderBy('performed_at')
                ->orderBy('id')
                ->get()
                ->map(fn (Crossmatch $crossmatch): array => $crossmatch->setRelation('allocation', $allocation)->toApiArray())
                ->all(),
        ]);
    }
}
