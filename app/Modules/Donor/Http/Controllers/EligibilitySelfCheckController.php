<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Controllers;

use App\Modules\Donor\Application\CheckEligibilityAnonymously;
use App\Modules\Donor\Domain\EligibilityDecision;
use App\Modules\Donor\Http\Requests\SelfCheckRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class EligibilitySelfCheckController
{
    public function __invoke(
        SelfCheckRequest $request,
        CheckEligibilityAnonymously $check,
    ): JsonResponse {
        $decision = $check->handle($request->validated());

        return ApiResponse::success($this->present($decision));
    }

    /** @return array<string, mixed> */
    private function present(EligibilityDecision $decision): array
    {
        return [
            'outcome' => $decision->outcome->value,
            'is_provisional' => true,
            'retry_after_date' => $decision->retryAfterDate?->format('Y-m-d'),
            'findings' => array_map(fn ($f) => [
                'code' => $f->code->value,
                'outcome' => $f->outcome->value,
                'retry_after_date' => $f->retryAfterDate?->format('Y-m-d'),
                'detail' => $f->detail,
            ], $decision->findings),
        ];
    }
}
