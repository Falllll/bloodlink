<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Models\DeferralReason;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master data alasan deferral untuk form penempatan di FE, supaya kodenya tidak
 * di-hardcode di frontend. Read-only, tanpa paginasi.
 */
final class DeferralReasonController
{
    public function __invoke(Request $request): JsonResponse
    {
        $reasons = DeferralReason::query()
            ->where('jurisdiction', 'WHO')
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (DeferralReason $reason): array => [
                'code' => $reason->code,
                'label' => $reason->label,
                'type' => $reason->type->value,
                'default_duration_value' => $reason->default_duration_value,
                'default_duration_unit' => $reason->default_duration_unit?->value,
                'source_reference' => $reason->source_reference,
            ])
            ->values()
            ->all();

        return ApiResponse::success($reasons);
    }
}
