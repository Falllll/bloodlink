<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Identity\Http\Requests\AuditLogListRequest;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppliesListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;

final class AuditLogController
{
    use AppliesListQuery;

    public function index(AuditLogListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        /** @var User|null $user */
        $user = $request->user();

        $query = AuditLog::query()->readableBy($user);

        $query->when($request->occurredFrom(), fn ($q, $v) => $q->where('occurred_at', '>=', $v));
        $query->when($request->occurredTo(), fn ($q, $v) => $q->where('occurred_at', '<=', $v));

        /** @var CursorPaginator<int, AuditLog> $page */
        $page = $this->listing($query, $request);

        return ApiResponse::paginated($page->through(fn (AuditLog $l) => $l->toApiArray()));
    }
}
