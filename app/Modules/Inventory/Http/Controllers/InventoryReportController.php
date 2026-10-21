<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\BloodBatch;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\InventoryReportQuery;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class InventoryReportController
{
    public function wastage(ReportRequest $request, InventoryReportQuery $query): JsonResponse
    {
        Gate::authorize('viewReports', BloodBatch::class);

        /** @var User $user */
        $user = $request->user();
        $window = $request->window();

        return ApiResponse::success($query->wastage($window, $user), [
            ...$window->toMeta(),
            'numerator' => 'transitions to discarded or expired',
            'denominator' => 'transitions from testing to released',
        ]);
    }

    public function stockTrend(ReportRequest $request, InventoryReportQuery $query): JsonResponse
    {
        Gate::authorize('viewReports', BloodBatch::class);

        /** @var User $user */
        $user = $request->user();
        $window = $request->window();

        return ApiResponse::success($query->stockFlow($window, $user), $window->toMeta());
    }
}
