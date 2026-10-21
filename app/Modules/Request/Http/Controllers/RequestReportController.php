<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Controllers;

use App\Models\BloodRequest;
use App\Models\User;
use App\Modules\Request\Infrastructure\CrossmatchTransfusionReportQuery;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class RequestReportController
{
    public function crossmatchTransfusion(ReportRequest $request, CrossmatchTransfusionReportQuery $query): JsonResponse
    {
        Gate::authorize('viewReports', BloodRequest::class);

        /** @var User $user */
        $user = $request->user();
        $window = $request->window();

        return ApiResponse::success($query->ratio($window, $user), [
            ...$window->toMeta(),
            'numerator' => 'crossmatches performed, any result',
            'denominator' => 'transfusions started',
        ]);
    }
}
