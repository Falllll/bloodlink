<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Controllers;

use App\Models\BloodRequest;
use App\Models\User;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Domain\RequestUrgency;
use App\Modules\Request\Http\Requests\BloodRequestListRequest;
use App\Modules\Request\Http\Requests\StoreBloodRequestRequest;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
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
}
